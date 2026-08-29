<?php

namespace Tests\Feature\Activity;

use App\Enums\TicketActivityEvent;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TicketTimelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_activities_are_returned_newest_first(): void
    {
        $ticket = Ticket::factory()->create();
        $this->record($ticket, TicketActivityEvent::Created, ['created_at' => now()->subMinutes(3)]);
        $this->record($ticket, TicketActivityEvent::Updated, ['created_at' => now()->subMinutes(2)]);
        $this->record($ticket, TicketActivityEvent::Updated, ['created_at' => now()->subMinute()]);

        $response = $this->asAgent()->getJson($this->url($ticket))->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertEquals($ids->sortDesc()->values(), $ids);
    }

    public function test_rows_sharing_a_timestamp_are_ordered_by_id_descending(): void
    {
        $ticket = Ticket::factory()->create();
        $this->travelTo(now());
        for ($i = 0; $i < 3; $i++) {
            $this->record($ticket, TicketActivityEvent::Updated);
        }

        $page1 = $this->asAgent()->getJson($this->url($ticket).'?per_page=1&page=1')->assertOk();
        $page2 = $this->asAgent()->getJson($this->url($ticket).'?per_page=1&page=2')->assertOk();
        $page3 = $this->asAgent()->getJson($this->url($ticket).'?per_page=1&page=3')->assertOk();

        $ids = [$page1->json('data.0.id'), $page2->json('data.0.id'), $page3->json('data.0.id')];
        $this->assertSame($ids, collect($ids)->sortDesc()->values()->all());
        $this->assertCount(3, array_unique($ids));
    }

    public function test_per_page_is_validated_and_defaults_to_twenty(): void
    {
        $ticket = Ticket::factory()->create();
        for ($i = 0; $i < 25; $i++) {
            $this->record($ticket, TicketActivityEvent::Updated);
        }

        $default = $this->asAgent()->getJson($this->url($ticket))->assertOk();
        $this->assertSame(20, $default->json('meta.per_page'));
        $this->assertCount(20, $default->json('data'));

        $five = $this->asAgent()->getJson($this->url($ticket).'?per_page=5')->assertOk();
        $this->assertCount(5, $five->json('data'));

        $this->asAgent()->getJson($this->url($ticket).'?per_page=0')->assertUnprocessable();
        $this->asAgent()->getJson($this->url($ticket).'?per_page=101')->assertUnprocessable();
        $this->asAgent()->getJson($this->url($ticket).'?per_page=abc')->assertUnprocessable();
    }

    public function test_the_actor_is_present_and_null_for_a_system_row(): void
    {
        $ticket = Ticket::factory()->create();
        $actor = User::factory()->agent()->create();
        $this->record($ticket, TicketActivityEvent::Updated, ['user_id' => $actor->id]);
        $this->record($ticket, TicketActivityEvent::Stale);

        $response = $this->asAgent()->getJson($this->url($ticket))->assertOk();
        $rows = $response->json('data');
        $userRow = collect($rows)->firstWhere('event', 'updated');
        $systemRow = collect($rows)->firstWhere('event', 'stale');

        $this->assertSame($actor->name, $userRow['actor']['name']);
        $this->assertArrayHasKey('actor', $systemRow);
        $this->assertNull($systemRow['actor']);
    }

    public function test_field_change_exposes_raw_values_and_resolved_labels(): void
    {
        $ticket = Ticket::factory()->create();
        $this->record($ticket, TicketActivityEvent::CategoryChanged, [
            'field' => 'category_id',
            'old_value' => '1',
            'new_value' => '2',
            'meta' => ['from_name' => 'Hardware', 'to_name' => 'Software'],
        ]);

        $row = $this->asAgent()->getJson($this->url($ticket))->assertOk()->json('data.0');

        $this->assertSame('category_id', $row['field']);
        $this->assertSame('1', $row['old_value']);
        $this->assertSame('2', $row['new_value']);
        $this->assertSame('Hardware', $row['from_label']);
        $this->assertSame('Software', $row['to_label']);
    }

    public function test_labels_fall_back_to_raw_values_and_meta_never_returns_null(): void
    {
        $ticket = Ticket::factory()->create();
        $this->record($ticket, TicketActivityEvent::Updated, [
            'field' => 'subject', 'old_value' => 'Old subject', 'new_value' => 'New subject',
        ]);
        DB::table('ticket_activities')->insert([
            'ticket_id' => $ticket->id, 'event' => TicketActivityEvent::Updated->value,
            'user_id' => null, 'field' => null, 'old_value' => null, 'new_value' => null,
            'meta' => null, 'created_at' => now(),
        ]);

        $response = $this->asAgent()->getJson($this->url($ticket))->assertOk();
        $rows = $response->json('data');
        $withoutNames = collect($rows)->firstWhere('field', 'subject');
        $nullMetaRow = collect($rows)->first(fn ($row) => $row['field'] === null);

        $this->assertSame('Old subject', $withoutNames['from_label']);
        $this->assertSame('New subject', $withoutNames['to_label']);
        $this->assertSame([], $nullMetaRow['meta']);
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $ticket = Ticket::factory()->create();
        $this->getJson($this->url($ticket))->assertUnauthorized();
    }

    public function test_unknown_and_soft_deleted_tickets_return_404(): void
    {
        $this->asAgent()->getJson('/api/v1/tickets/999999/activities')->assertNotFound();

        $ticket = Ticket::factory()->create();
        $ticket->delete();
        $this->asAgent()->getJson($this->url($ticket))->assertNotFound();
    }

    public function test_the_actor_is_eager_loaded(): void
    {
        // A per-row `users` query would push this well past ten: one select
        // per distinct actor plus the fixed overhead of auth, the ticket
        // lookup, the count query and the page query. ->with('user') collapses
        // every actor into one query regardless of row count.
        $ticket = Ticket::factory()->create();
        for ($i = 0; $i < 10; $i++) {
            $this->record($ticket, TicketActivityEvent::Updated, ['user_id' => User::factory()->agent()->create()->id]);
        }
        $agentToken = $this->tokenFor(User::factory()->agent()->create());
        $this->withToken($agentToken)->getJson($this->url($ticket))->assertOk();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $this->withToken($agentToken)->getJson($this->url($ticket))->assertOk();

        $this->assertLessThanOrEqual(10, $queries);
    }

    public function test_unicode_and_markup_round_trip_through_the_api(): void
    {
        $ticket = Ticket::factory()->create();
        $reason = '<script>alert(1)</script> مرحبا';
        $this->record($ticket, TicketActivityEvent::Updated, ['meta' => ['reason' => $reason]]);

        $this->asAgent()->getJson($this->url($ticket))->assertOk()
            ->assertJsonPath('data.0.meta.reason', $reason);
    }

    public function test_an_agent_may_read_any_tickets_timeline(): void
    {
        $admin = User::factory()->admin()->create();
        $ticket = Ticket::factory()->create(['created_by' => $admin->id]);
        $this->record($ticket, TicketActivityEvent::Created);

        $this->asAgent()->getJson($this->url($ticket))->assertOk();
    }

    public function test_activities_of_another_ticket_are_not_included(): void
    {
        $ticketA = Ticket::factory()->create();
        $ticketB = Ticket::factory()->create();
        $this->record($ticketA, TicketActivityEvent::Created);
        $this->record($ticketB, TicketActivityEvent::Created);
        $this->record($ticketB, TicketActivityEvent::Updated);

        $response = $this->asAgent()->getJson($this->url($ticketB))->assertOk();

        $this->assertSame(2, $response->json('meta.total'));
        foreach ($response->json('data') as $row) {
            $this->assertNotNull($row['id']);
        }
    }

    /** @param array<string, mixed> $overrides */
    private function record(Ticket $ticket, TicketActivityEvent $event, array $overrides = []): void
    {
        $attributes = collect($overrides)->except('created_at')->all();
        DB::transaction(function () use ($ticket, $event, $attributes): void {
            app(ActivityRecorder::class)->record($ticket->getKey(), $event, $attributes);
        });
        if (isset($overrides['created_at'])) {
            DB::table('ticket_activities')
                ->where('ticket_id', $ticket->id)->orderByDesc('id')->limit(1)
                ->update(['created_at' => $overrides['created_at']]);
        }
    }

    private function url(Ticket $ticket): string
    {
        return "/api/v1/tickets/{$ticket->id}/activities";
    }

    private function asAgent(): static
    {
        return $this->withToken($this->tokenFor(User::factory()->agent()->create()));
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }
}
