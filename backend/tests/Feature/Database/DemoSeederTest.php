<?php

namespace Tests\Feature\Database;

use App\Enums\StatusBucket;
use App\Models\Category;
use App\Models\Requester;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 14 = 2 x 7, so full status coverage still holds and the class
        // stays fast. Tests 22, 23 and 25 override back to something larger.
        config()->set([
            'seeding.demo.tickets' => 14,
            'seeding.demo.agents' => 4,
            'seeding.demo.admins' => 2,
        ]);
    }

    /**
     * AC5's "separate from" half. Behavioural, not a source scan — it fails
     * if anyone adds DemoSeeder::class to DatabaseSeeder's call() array.
     */
    public function test_the_production_seeder_creates_no_demo_data(): void
    {
        config()->set('seeding.admin', ['name' => 'Configured Admin', 'email' => 'configured@test', 'password' => 'x']);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, Requester::count());
        $this->assertSame(0, TicketActivity::count());
        $this->assertSame(1, User::count());
    }

    public function test_it_creates_the_configured_people(): void
    {
        $this->seed(DemoSeeder::class);

        $this->assertSame(2, User::where('role', 'admin')->count());
        $this->assertSame(4, User::where('role', 'agent')->count());
        $this->assertSame(1, User::where('role', 'agent')->where('is_active', false)->count());

        $domain = config('seeding.demo.email_domain');
        $this->assertSame(6, User::where('email', 'like', "%@{$domain}")->count());
        $this->assertTrue(Hash::check(config('seeding.demo.password'), User::first()->password));
    }

    /**
     * AC5's "never runs" half. config()->set('app.env', ...) is measured NOT
     * to change app()->environment() — that version would pass over an
     * unprotected path. detectEnvironment() is the real switch.
     */
    public function test_it_refuses_to_run_in_production(): void
    {
        app()->detectEnvironment(fn () => 'production');

        // Calling run() directly, not through `db:seed`: the guard this test
        // proves lives in the seeder itself, not in artisan's confirmation
        // prompt (which --force bypasses entirely — verified by hand, see
        // the plan's verification steps).
        try {
            $this->expectException(RuntimeException::class);
            app(DemoSeeder::class)->run();
        } finally {
            $this->assertSame(0, Ticket::count());
            app()->detectEnvironment(fn () => 'testing');
        }
    }

    public function test_it_refuses_a_second_run(): void
    {
        $this->seed(DemoSeeder::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/migrate:fresh/');
        $this->seed(DemoSeeder::class);
    }

    public function test_it_refuses_a_second_run_even_after_soft_deleting_every_ticket(): void
    {
        $this->seed(DemoSeeder::class);
        Ticket::query()->delete();

        $this->expectException(RuntimeException::class);
        $this->seed(DemoSeeder::class);
    }

    public function test_fifty_tickets_cover_every_status_and_every_priority(): void
    {
        config()->set('seeding.demo.tickets', 50);
        $this->seed(DemoSeeder::class);

        $this->assertSame(50, Ticket::count());
        $this->assertSame(7, Ticket::query()->distinct('status_id')->count('status_id'));
        $this->assertSame(4, Ticket::query()->distinct('priority_id')->count('priority_id'));
        $this->assertSame(6, Ticket::query()->distinct('category_id')->count('category_id'));
        $this->assertGreaterThan(0, Ticket::whereNull('assigned_to')->count());
        $this->assertGreaterThan(0, Ticket::whereNotNull('assigned_to')->count());
    }

    public function test_every_ticket_is_internally_consistent(): void
    {
        config()->set('seeding.demo.tickets', 50);
        $this->seed(DemoSeeder::class);

        $tickets = Ticket::with('status')->get();
        $anyEscalated = false;

        foreach ($tickets as $ticket) {
            $label = "ticket {$ticket->reference}";
            $isDone = $ticket->status->bucket === StatusBucket::Done;
            $isReopened = $ticket->status->slug === 'reopened';

            if ($isDone) {
                $this->assertNotNull($ticket->resolved_at, "{$label}: done bucket missing resolved_at");
            } elseif ($isReopened) {
                $this->assertNull($ticket->resolved_at, "{$label}: reopened must clear resolved_at");
            }

            if ($ticket->status->slug === 'closed') {
                $this->assertNotNull($ticket->closed_at, $label);
                $this->assertTrue($ticket->closed_at->gte($ticket->resolved_at), $label);
            } else {
                $this->assertNull($ticket->closed_at, $label);
            }

            if ($ticket->escalation_level > 0) {
                $this->assertNotNull($ticket->escalated_at, $label);
                $this->assertNotNull($ticket->escalated_by, $label);
                $this->assertNotNull($ticket->escalation_reason, $label);
                $this->assertFalse($isDone, "{$label}: escalated ticket must not sit in a done bucket");
                $anyEscalated = true;
            }

            foreach (['first_responded_at', 'resolved_at', 'closed_at', 'escalated_at'] as $column) {
                if ($ticket->$column !== null) {
                    $this->assertTrue($ticket->$column->gte($ticket->created_at), "{$label}: {$column} before created_at");
                    $this->assertTrue($ticket->$column->lte(now()), "{$label}: {$column} in the future");
                }
            }

            if ($ticket->status->slug === 'new') {
                $this->assertNull($ticket->assigned_to, $label);
            }
        }

        $this->assertTrue($anyEscalated);
    }

    public function test_activity_histories_are_ordered_and_typed(): void
    {
        config()->set('seeding.demo.tickets', 50);
        $this->seed(DemoSeeder::class);

        $events = DB::table('ticket_activities')->pluck('event');
        $this->assertContains('assigned', $events->all());
        $this->assertContains('escalated', $events->all());
        $this->assertTrue(
            DB::table('ticket_activities')->where('event', 'status_changed')->where('new_value', 'resolved')->exists()
        );

        foreach (Ticket::with('activities')->get() as $ticket) {
            $this->assertGreaterThanOrEqual(1, $ticket->activities->count(), $ticket->reference);
            $first = $ticket->activities->sortBy('created_at')->first();
            $this->assertSame('created', $first->event->value, $ticket->reference);
            foreach ($ticket->activities as $activity) {
                $this->assertTrue($activity->created_at->gte($ticket->created_at), $ticket->reference);
                $this->assertTrue($activity->created_at->lte(now()), $ticket->reference);
            }
        }

        $this->assertFalse(Carbon::hasTestNow());
    }

    public function test_created_dates_span_the_configured_months(): void
    {
        config()->set(['seeding.demo.tickets' => 50, 'seeding.demo.months' => 6]);
        $this->seed(DemoSeeder::class);

        $dates = Ticket::query()->pluck('created_at');
        $this->assertTrue($dates->min()->lt(now()->subDays(150)));
        $this->assertTrue($dates->max()->gt(now()->subDays(2)));

        $months = $dates->map(fn ($d) => $d->format('Y-m'))->unique();
        $this->assertGreaterThanOrEqual(6, $months->count());
    }

    public function test_no_assignee_is_inactive(): void
    {
        config()->set('seeding.demo.tickets', 50);
        $this->seed(DemoSeeder::class);

        $inactiveAssignees = Ticket::query()
            ->whereNotNull('assigned_to')
            ->whereHas('assignee', fn ($q) => $q->where('is_active', false))
            ->count();

        $this->assertSame(0, $inactiveAssignees);
    }

    /** The tripwire that catches TM-52...TM-57 emailing 50 requesters on every demo seed. */
    public function test_seeding_sends_no_mail_and_queues_no_job(): void
    {
        $this->seed(DemoSeeder::class);

        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    /** The end-to-end proof: exercises endpoints that only exist because E4 landed. */
    public function test_the_api_still_works_on_a_seeded_database(): void
    {
        $this->seed(DemoSeeder::class);
        $admin = User::where('role', 'admin')->first();
        $endUser = User::factory()->endUser()->create();
        $categoryId = Category::query()->value('id');

        $created = $this->actingAs($endUser)->postJson(route('tickets.store'), [
            'subject' => 'Post-seed ticket',
            'description' => 'Proves the reference sequence survived seeding.',
            'category_id' => $categoryId,
        ])->assertCreated();

        $existingReferences = Ticket::query()->pluck('reference')->all();
        $this->assertNotContains($created->json('data.reference'), array_diff($existingReferences, [$created->json('data.reference')]));

        $this->actingAs($admin)->getJson('/api/v1/tickets?per_page=50')->assertOk();
        $this->actingAs($admin)->getJson('/api/v1/tickets/stats')->assertOk();
    }
}
