<?php

namespace Tests\Feature\Console;

use App\Enums\TicketActivityEvent;
use App\Events\TicketsFlaggedStale;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Services\ActivityRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class FlagStaleTicketsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['tickets.stale_after_hours' => 48, 'tickets.stale_notify' => false]);
    }

    public function test_it_flags_a_stale_non_terminal_ticket(): void
    {
        $ticket = $this->ticketIdleFor(49);
        $this->artisan('tickets:flag-stale')->assertExitCode(0);
        $this->assertSame(1, TicketActivity::query()->where('ticket_id', $ticket->id)->where('event', TicketActivityEvent::Stale->value)->count());
    }

    public function test_the_row_is_attributed_to_no_user(): void
    {
        $ticket = $this->ticketIdleFor(49);
        $this->artisan('tickets:flag-stale');

        $row = TicketActivity::query()->where('ticket_id', $ticket->id)->where('event', TicketActivityEvent::Stale->value)->firstOrFail();
        $this->assertNull($row->user_id);
        $this->assertNull($row->field);
        $this->assertNull($row->old_value);
        $this->assertNull($row->new_value);
        $this->assertSame(48, $row->meta['threshold_hours']);
    }

    public function test_terminal_tickets_are_never_flagged(): void
    {
        foreach (['resolved', 'closed'] as $slug) {
            $status = Status::query()->where('slug', $slug)->firstOrFail();
            $this->ticketIdleFor(500, $status->id);
        }
        $this->artisan('tickets:flag-stale');
        $this->assertSame(0, TicketActivity::query()->where('event', TicketActivityEvent::Stale->value)->count());
    }

    public function test_the_threshold_boundary_is_strict(): void
    {
        $atThreshold = $this->ticketIdleFor(48);
        $pastThreshold = $this->ticketIdleForMinutes(48 * 60 + 1);
        $this->artisan('tickets:flag-stale');

        $flaggedIds = TicketActivity::query()->where('event', TicketActivityEvent::Stale->value)->pluck('ticket_id')->all();
        $this->assertNotContains($atThreshold->id, $flaggedIds);
        $this->assertContains($pastThreshold->id, $flaggedIds);
    }

    public function test_a_fresh_ticket_is_not_flagged(): void
    {
        $this->ticketIdleFor(1);
        $this->artisan('tickets:flag-stale');
        $this->assertSame(0, TicketActivity::query()->where('event', TicketActivityEvent::Stale->value)->count());
    }

    public function test_soft_deleted_tickets_are_never_flagged(): void
    {
        $ticket = $this->ticketIdleFor(500);
        $ticket->delete();
        $this->artisan('tickets:flag-stale');
        $this->assertSame(0, TicketActivity::query()->where('event', TicketActivityEvent::Stale->value)->count());
    }

    public function test_it_does_not_re_flag_an_already_flagged_ticket(): void
    {
        $this->ticketIdleFor(49);
        $this->artisan('tickets:flag-stale');
        $this->artisan('tickets:flag-stale')->expectsOutputToContain('No stale tickets.');
        $this->assertSame(1, TicketActivity::query()->where('event', TicketActivityEvent::Stale->value)->count());
    }

    public function test_a_ticket_touched_after_flagging_is_flagged_again(): void
    {
        $ticket = $this->ticketIdleFor(49);
        $this->artisan('tickets:flag-stale');
        $staleRow = TicketActivity::query()->where('ticket_id', $ticket->id)->where('event', TicketActivityEvent::Stale->value)->firstOrFail();

        // Ticket touched (updated_at bumped forward), then goes idle again --
        // the row's created_at must be older than the new updated_at.
        DB::table('tickets')->where('id', $ticket->id)->update(['updated_at' => now()->subHours(49)]);
        DB::table('ticket_activities')->where('id', $staleRow->id)->update(['created_at' => now()->subHours(72)]);

        $this->artisan('tickets:flag-stale');
        $this->assertSame(2, TicketActivity::query()->where('ticket_id', $ticket->id)->where('event', TicketActivityEvent::Stale->value)->count());
    }

    public function test_dry_run_changes_nothing(): void
    {
        Event::fake();
        config(['tickets.stale_notify' => true]);
        $ticket = $this->ticketIdleFor(49);
        $before = TicketActivity::query()->count();

        $this->artisan('tickets:flag-stale --dry-run')
            ->expectsOutputToContain($ticket->reference)
            ->expectsOutputToContain('would flag 1 ticket(s)');

        $this->assertSame($before, TicketActivity::query()->count());
        Event::assertNotDispatched(TicketsFlaggedStale::class);
    }

    public function test_it_chunks_beyond_five_hundred(): void
    {
        Event::fake();
        config(['tickets.stale_notify' => true]);
        collect(range(1, 501))->each(fn () => $this->ticketIdleFor(49));

        $this->artisan('tickets:flag-stale')->assertExitCode(0);

        $this->assertSame(501, TicketActivity::query()->where('event', TicketActivityEvent::Stale->value)->count());
        Event::assertDispatched(TicketsFlaggedStale::class, fn (TicketsFlaggedStale $event) => count($event->ticketIds) === 501 && $event->thresholdHours === 48);
    }

    public function test_a_failure_part_way_leaves_earlier_chunks_committed(): void
    {
        collect(range(1, 501))->each(fn () => $this->ticketIdleFor(49));

        // A real recorder handles the first (500-row) chunk so it is genuinely
        // committed; the mock throws only on the second chunk, so the failure
        // lands exactly at the chunk boundary this test is about.
        $real = new ActivityRecorder;
        $calls = 0;
        $this->mock(ActivityRecorder::class, function ($mock) use (&$calls, $real): void {
            $mock->shouldReceive('recordMany')->twice()->andReturnUsing(function (array $ticketIds, TicketActivityEvent $event, array $attributes) use (&$calls, $real): void {
                $calls++;
                if ($calls === 2) {
                    throw new RuntimeException('boom');
                }
                $real->recordMany($ticketIds, $event, $attributes);
            });
        });

        // $this->artisan() lets the underlying exception surface (unlike an
        // HTTP request, which the exception handler turns into a response),
        // so catching it here is the only way to then assert the recovery
        // behaviour below in the same test.
        try {
            $this->artisan('tickets:flag-stale');
            $this->fail('Expected the second chunk to throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        $this->assertSame(500, TicketActivity::query()->where('event', TicketActivityEvent::Stale->value)->count());

        // A clean re-run finishes the job: the 500 committed tickets now fail
        // the NOT EXISTS clause, so only the one remaining ticket is flagged.
        // Swap the mock back out first -- it already used up its expectation.
        $this->app->instance(ActivityRecorder::class, $real);
        $this->artisan('tickets:flag-stale');
        $this->assertSame(501, TicketActivity::query()->where('event', TicketActivityEvent::Stale->value)->count());
    }

    public function test_it_dispatches_one_event_only_when_notify_is_on_and_something_was_flagged(): void
    {
        Event::fake();
        $ticket = $this->ticketIdleFor(49);

        config(['tickets.stale_notify' => false]);
        $this->artisan('tickets:flag-stale');
        Event::assertNotDispatched(TicketsFlaggedStale::class);

        DB::table('ticket_activities')->where('ticket_id', $ticket->id)->delete();
        config(['tickets.stale_notify' => true]);
        $this->artisan('tickets:flag-stale');
        Event::assertDispatched(TicketsFlaggedStale::class, 1);

        $this->artisan('tickets:flag-stale');
        Event::assertDispatched(TicketsFlaggedStale::class, 1);
    }

    public function test_a_non_positive_threshold_fails_and_writes_nothing(): void
    {
        foreach ([0, -5] as $hours) {
            config(['tickets.stale_after_hours' => $hours]);
            $this->ticketIdleFor(500);
            $this->artisan('tickets:flag-stale')
                ->expectsOutputToContain('TICKETS_STALE_AFTER_HOURS')
                ->assertExitCode(1);
            $this->assertSame(0, TicketActivity::query()->where('event', TicketActivityEvent::Stale->value)->count());
        }
    }

    public function test_flagging_touches_no_ticket_column(): void
    {
        $ticket = $this->ticketIdleFor(49);
        $before = DB::table('tickets')->where('id', $ticket->id)->first();
        $this->artisan('tickets:flag-stale');
        $after = DB::table('tickets')->where('id', $ticket->id)->first();

        foreach (['updated_at', 'status_id', 'escalation_level', 'escalated_at'] as $column) {
            $this->assertEquals($before->$column, $after->$column, "{$column} changed");
        }
    }

    public function test_the_config_values_have_the_right_types(): void
    {
        $this->assertIsInt(config('tickets.stale_after_hours'));
        $this->assertIsBool(config('tickets.stale_notify'));
    }

    private function ticketIdleFor(int $hours, ?int $statusId = null): Ticket
    {
        return $this->ticketIdleForMinutes($hours * 60, $statusId);
    }

    private function ticketIdleForMinutes(int $minutes, ?int $statusId = null): Ticket
    {
        $ticket = Ticket::factory()->create($statusId === null ? [] : ['status_id' => $statusId]);
        DB::table('tickets')->where('id', $ticket->id)->update(['updated_at' => now()->subMinutes($minutes)]);

        return $ticket->fresh();
    }
}
