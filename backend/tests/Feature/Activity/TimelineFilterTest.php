<?php

namespace Tests\Feature\Activity;

use App\Enums\TicketActivityEvent;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TimelineFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_the_facet_lists_every_event_type_on_the_ticket_with_counts(): void
    {
        $ticket = Ticket::factory()->create();
        $expected = $this->fill($ticket, 3);

        $counts = $this->asAgent()->getJson($this->url($ticket))->assertOk()->json('meta.event_counts');

        $this->assertSame($expected, $counts);
        foreach (array_keys($counts) as $key) {
            $this->assertIsString($key);
        }
        foreach ($counts as $count) {
            $this->assertIsInt($count);
        }
    }

    public function test_filtering_narrows_data_and_total(): void
    {
        $ticket = Ticket::factory()->create();
        $this->fill($ticket, 3);

        $response = $this->asAgent()->getJson($this->url($ticket).'?events[]=created')->assertOk();
        $this->assertSame(3, $response->json('meta.total'));
        $this->assertCount(3, $response->json('data'));
        foreach ($response->json('data') as $row) {
            $this->assertSame('created', $row['event']);
        }

        $two = $this->asAgent()->getJson($this->url($ticket).'?events[]=created&events[]=category_changed')->assertOk();
        $this->assertSame(6, $two->json('meta.total'));
    }

    public function test_an_unknown_or_malformed_event_filter_is_rejected(): void
    {
        $ticket = Ticket::factory()->create();
        $token = $this->tokenFor(User::factory()->agent()->create());

        $response = $this->withToken($token)->getJson($this->url($ticket).'?events[]=not_an_event')
            ->assertUnprocessable();
        // Laravel keys an array-item validation error as the literal string
        // "events.0", not a nested events -> {0: ...} structure, so
        // assertJsonPath's dot-notation traversal would find nothing here.
        $this->assertSame(['Unknown activity event type: not_an_event.'], $response->json('errors')['events.0']);

        $this->withToken($token)->getJson($this->url($ticket).'?events[]=CREATED')->assertUnprocessable();

        $this->withToken($token)->getJson($this->url($ticket).'?events=created')->assertUnprocessable();

        $tooMany = implode('&', array_fill(0, count(TicketActivityEvent::values()) + 1, 'events[]=created'));
        $this->withToken($token)->getJson($this->url($ticket).'?'.$tooMany)->assertUnprocessable();
    }

    public function test_a_new_event_case_becomes_filterable_with_no_change_to_this_story(): void
    {
        // Loops TicketActivityEvent::cases() rather than naming values, so a
        // story that adds a case is covered here with no edit -- the whole
        // point of validating against Rule::in(TicketActivityEvent::values()).
        $ticket = Ticket::factory()->create();
        $token = $this->tokenFor(User::factory()->agent()->create());

        foreach (TicketActivityEvent::cases() as $case) {
            $this->withToken($token)->getJson($this->url($ticket)."?events[]={$case->value}")->assertOk();
        }
    }

    public function test_the_facet_ignores_the_active_filter(): void
    {
        $ticket = Ticket::factory()->create();
        $expected = $this->fill($ticket, 3);

        $counts = $this->asAgent()->getJson($this->url($ticket).'?events[]=created')->assertOk()->json('meta.event_counts');

        $this->assertSame(array_keys($expected), array_keys($counts));
    }

    public function test_a_filter_matching_nothing_returns_an_empty_page_and_a_full_facet(): void
    {
        $ticket = Ticket::factory()->create();
        DB::transaction(function () use ($ticket): void {
            app(ActivityRecorder::class)->recordMany([$ticket->id], TicketActivityEvent::Created, ['meta' => []]);
        });

        $response = $this->asAgent()->getJson($this->url($ticket).'?events[]=category_changed')->assertOk();

        $this->assertSame([], $response->json('data'));
        $this->assertSame(0, $response->json('meta.total'));
        $this->assertArrayHasKey('created', $response->json('meta.event_counts'));
    }

    public function test_a_repeated_event_value_changes_nothing(): void
    {
        $ticket = Ticket::factory()->create();
        $this->fill($ticket, 3);

        $once = $this->asAgent()->getJson($this->url($ticket).'?events[]=created')->assertOk()->json();
        $twice = $this->asAgent()->getJson($this->url($ticket).'?events[]=created&events[]=created')->assertOk()->json();

        $this->assertSame($once, $twice);
    }

    public function test_the_query_count_does_not_grow_with_the_trail(): void
    {
        $small = Ticket::factory()->create();
        $this->fill($small, 1);
        $large = Ticket::factory()->create();
        $this->fill($large, 100);

        // A fresh token per call, created OUTSIDE countQueries() so fixture
        // setup is never counted, and not one token reused across both:
        // Sanctum throttles its last_used_at update on a token used again
        // within the same minute, so reusing one would make the second call
        // look one query cheaper for a reason that has nothing to do with
        // trail size. Auth::forgetGuards() is still needed between calls, or
        // the guard serves the first request's resolved user to the second.
        $tokenA = $this->tokenFor(User::factory()->agent()->create());
        $tokenB = $this->tokenFor(User::factory()->agent()->create());
        $tokenC = $this->tokenFor(User::factory()->agent()->create());
        $tokenD = $this->tokenFor(User::factory()->agent()->create());

        Auth::forgetGuards();
        $smallQueries = $this->countQueries(fn () => $this->withToken($tokenA)->getJson($this->url($small))->assertOk());
        Auth::forgetGuards();
        $largeQueries = $this->countQueries(fn () => $this->withToken($tokenB)->getJson($this->url($large))->assertOk());
        $this->assertSame($smallQueries, $largeQueries);
        $this->assertLessThanOrEqual(7, $largeQueries);

        Auth::forgetGuards();
        $smallFiltered = $this->countQueries(fn () => $this->withToken($tokenC)->getJson($this->url($small).'?events[]=created')->assertOk());
        Auth::forgetGuards();
        $largeFiltered = $this->countQueries(fn () => $this->withToken($tokenD)->getJson($this->url($large).'?events[]=created')->assertOk());
        $this->assertSame($smallFiltered, $largeFiltered);
    }

    public function test_a_four_hundred_row_ticket_returns_only_one_page(): void
    {
        $ticket = Ticket::factory()->create();
        $this->fill($ticket, 100);
        $token = $this->tokenFor(User::factory()->agent()->create());
        $total = count(TicketActivityEvent::cases()) * 100;

        $response = $this->withToken($token)->getJson($this->url($ticket))->assertOk();
        $this->assertCount(20, $response->json('data'));
        $this->assertSame(20, $response->json('meta.per_page'));
        $this->assertSame($total, $response->json('meta.total'));
        $this->assertSame((int) ceil($total / 20), $response->json('meta.last_page'));

        $this->withToken($token)->getJson($this->url($ticket).'?per_page=100')->assertOk()->assertJsonCount(100, 'data');
        $this->withToken($token)->getJson($this->url($ticket).'?per_page=101')->assertUnprocessable();
    }

    public function test_paging_through_a_filtered_trail_never_repeats_an_id(): void
    {
        $ticket = Ticket::factory()->create();
        $this->fill($ticket, 25);
        $token = $this->tokenFor(User::factory()->agent()->create());

        $seen = [];
        $total = null;
        $page = 1;
        do {
            $response = $this->withToken($token)->getJson($this->url($ticket)."?events[]=created&per_page=10&page={$page}")->assertOk();
            $total ??= $response->json('meta.total');
            foreach ($response->json('data') as $row) {
                $seen[] = $row['id'];
            }
            $page++;
        } while ($page <= $response->json('meta.last_page'));

        $this->assertCount($total, array_unique($seen));
        $this->assertCount($total, $seen);
    }

    public function test_the_filter_does_not_widen_access(): void
    {
        $admin = User::factory()->admin()->create();
        $ticket = Ticket::factory()->create(['created_by' => $admin->id]);
        $this->fill($ticket, 1);

        $this->asAgent()->getJson($this->url($ticket).'?events[]=created')->assertOk();
        // withToken() (inside asAgent()) sets a default Authorization header
        // that survives for the rest of the test; withoutToken() clears it,
        // and forgetGuards() clears the Sanctum guard's cached resolved user
        // from the call above — without both, this "unauthenticated" request
        // authenticates as the previous agent and 200s instead of 401ing.
        $this->withoutToken();
        Auth::forgetGuards();
        $this->getJson($this->url($ticket).'?events[]=created')->assertUnauthorized();
        Auth::forgetGuards();
        $this->asAgent()->getJson('/api/v1/tickets/999999/activities?events[]=created')->assertNotFound();
    }

    /** @return array<string, int> the expected facet */
    private function fill(Ticket $ticket, int $perEvent): array
    {
        $expected = [];
        DB::transaction(function () use ($ticket, $perEvent, &$expected): void {
            $recorder = app(ActivityRecorder::class);
            foreach (TicketActivityEvent::cases() as $case) {
                $recorder->recordMany(array_fill(0, $perEvent, $ticket->getKey()), $case, [
                    'user_id' => null, 'field' => null, 'old_value' => null,
                    'new_value' => null, 'meta' => [],
                ]);
                $expected[$case->value] = $perEvent;
            }
        });
        ksort($expected);

        return $expected;
    }

    private function countQueries(\Closure $callback): int
    {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $callback();

        return $queries;
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
