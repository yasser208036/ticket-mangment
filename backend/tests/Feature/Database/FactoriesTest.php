<?php

namespace Tests\Feature\Database;

use App\Enums\TicketActivityEvent;
use App\Models\Category;
use App\Models\Priority;
use App\Models\Requester;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use App\Services\TicketReferenceGenerator;
use BadMethodCallException;
use Database\Seeders\CategorySeeder;
use Database\Seeders\PrioritySeeder;
use Database\Seeders\StatusSeeder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class FactoriesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CategorySeeder::class, PrioritySeeder::class, StatusSeeder::class]);
    }

    public function test_every_named_model_has_a_factory(): void
    {
        $this->assertInstanceOf(Factory::class, User::factory());
        $this->assertInstanceOf(Factory::class, Requester::factory());
        $this->assertInstanceOf(Factory::class, Category::factory());
        $this->assertInstanceOf(Factory::class, Ticket::factory());
        $this->assertInstanceOf(Factory::class, TicketActivity::factory());
    }

    /**
     * Deliberate omission: priorities_single_default_unique and
     * statuses_single_default_unique permit exactly one is_default=1 row.
     * A factory definition() returning fake booleans would fail
     * intermittently with generated-column error 3105.
     */
    public function test_priority_and_status_have_no_factory(): void
    {
        $this->expectException(BadMethodCallException::class);
        Priority::factory();
    }

    public function test_status_has_no_factory(): void
    {
        $this->expectException(BadMethodCallException::class);
        Status::factory();
    }

    public function test_requester_factory_produces_unique_persistable_contacts(): void
    {
        $requesters = Requester::factory()->count(25)->create();

        $this->assertCount(25, $requesters->pluck('email')->unique());

        $bare = Requester::factory()->withoutContactDetails()->create();
        $this->assertNull($bare->phone);
        $this->assertNull($bare->company);
    }

    public function test_category_factory_keeps_name_and_slug_in_step(): void
    {
        $categories = Category::factory()->count(10)->create();

        foreach ($categories as $category) {
            $this->assertSame(Str::slug($category->name), $category->slug);
            $this->assertTrue($category->is_active);
            $this->assertSame(7, strlen($category->color));
        }
    }

    public function test_ticket_factory_fills_every_not_null_column(): void
    {
        $ticket = Ticket::factory()->create();

        $this->assertNotNull($ticket->reference);
        $this->assertNotNull($ticket->requester_id);
        $this->assertNotNull($ticket->category_id);
        $this->assertNotNull($ticket->priority_id);
        $this->assertNotNull($ticket->status_id);
        $this->assertNotNull($ticket->created_by);
        $this->assertSame(0, $ticket->escalation_level);
        $this->assertNull($ticket->assigned_to);
    }

    /**
     * The collision regression, and the most important test in this story.
     * Against the pre-fix TicketFactory (a private static counter that never
     * touched ticket_sequences) this fails with 1062 Duplicate entry.
     */
    public function test_ticket_factory_references_come_from_the_sequence_table(): void
    {
        $tickets = Ticket::factory()->count(5)->create();

        $row = DB::table('ticket_sequences')->where('year', now()->year)->first();
        $this->assertNotNull($row);
        $this->assertGreaterThanOrEqual(5, $row->next_number);

        $next = DB::transaction(fn () => app(TicketReferenceGenerator::class)->next());
        $this->assertNotContains($next, $tickets->pluck('reference')->all());
        $this->assertDatabaseHas('ticket_sequences', ['year' => now()->year]);
    }

    public function test_ticket_factory_reference_year_matches_created_at(): void
    {
        $lastYear = now()->subYear();
        $ticket = Ticket::factory()->createdAt($lastYear)->create();

        $this->assertStringStartsWith('TKT-'.$lastYear->year.'-', $ticket->reference);
    }

    /** Against the pre-fix factory this fails: definition() set no created_at, so all 20 are now(). */
    public function test_ticket_factory_spreads_created_at_into_the_past(): void
    {
        $tickets = Ticket::factory()->count(20)->create();

        $this->assertGreaterThanOrEqual(15, $tickets->pluck('created_at')->unique()->count());
        $this->assertTrue($tickets->min('created_at')->lt(now()->subDays(30)));
    }

    public function test_ticket_factory_honours_an_explicit_created_at(): void
    {
        $at = now()->subMonths(2)->startOfSecond();
        $ticket = Ticket::factory()->createdAt($at)->create();

        $this->assertTrue($ticket->fresh()->created_at->equalTo($at));
    }

    public function test_ticket_factory_works_inside_and_outside_a_transaction(): void
    {
        $outside = Ticket::factory()->create();
        $inside = DB::transaction(fn () => Ticket::factory()->create());

        $this->assertNotSame($outside->reference, $inside->reference);
    }

    /** Against the pre-fix factory this fails with 1048 Column 'category_id' cannot be null. */
    public function test_ticket_factory_names_the_missing_master_data(): void
    {
        Category::query()->forceDelete();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/db:seed/');
        Ticket::factory()->create();
    }

    public function test_escalated_state_sets_all_four_columns(): void
    {
        $ticket = Ticket::factory()->escalated()->create();

        $this->assertGreaterThanOrEqual(1, $ticket->escalation_level);
        $this->assertNotNull($ticket->escalated_at);
        $this->assertNotNull($ticket->escalated_by);
        $this->assertNotNull($ticket->escalation_reason);
        $this->assertTrue($ticket->escalated_at->gte($ticket->created_at));
    }

    public function test_resolved_and_closed_states_set_their_timestamps(): void
    {
        $resolved = Ticket::factory()->resolved()->create();
        $this->assertNotNull($resolved->resolved_at);
        $this->assertNull($resolved->closed_at);
        $this->assertSame('resolved', $resolved->status->slug);

        $closed = Ticket::factory()->closed()->create();
        $this->assertNotNull($closed->resolved_at);
        $this->assertNotNull($closed->closed_at);
        $this->assertTrue($closed->closed_at->gte($closed->resolved_at));
        $this->assertSame('closed', $closed->status->slug);
    }

    public function test_in_status_rejects_an_unknown_slug(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/nope/');
        Ticket::factory()->inStatus('nope')->create();
    }

    public function test_ticket_activity_factory_only_ever_writes_known_events(): void
    {
        $ticket = Ticket::factory()->create();

        foreach (TicketActivityEvent::cases() as $case) {
            TicketActivity::factory()->state(['ticket_id' => $ticket->getKey()])->create(['event' => $case]);
        }
        TicketActivity::factory()->state(['ticket_id' => $ticket->getKey()])->statusChange('open', 'resolved')->create();
        TicketActivity::factory()->state(['ticket_id' => $ticket->getKey()])->assignment(User::factory()->create())->create();
        TicketActivity::factory()->state(['ticket_id' => $ticket->getKey()])->escalation('reason')->create();

        $events = DB::table('ticket_activities')->pluck('event');
        foreach ($events as $raw) {
            $this->assertNotNull(TicketActivityEvent::tryFrom($raw), "Unknown raw event [{$raw}]");
        }
        // Reading through the model must not throw ValueError.
        TicketActivity::query()->get()->each(fn (TicketActivity $a) => $a->event);
        $this->assertTrue(true);
    }

    public function test_ticket_activity_factory_attaches_to_a_ticket(): void
    {
        $ticket = Ticket::factory()->create();
        TicketActivity::factory()->state(['ticket_id' => $ticket->getKey()])->create();

        $this->assertCount(1, $ticket->activities);
    }

    public function test_ticket_activity_factory_defaults_meta_to_an_array_not_null(): void
    {
        $activity = TicketActivity::factory()->create();

        $raw = DB::table('ticket_activities')->where('id', $activity->getKey())->value('meta');
        $this->assertSame('[]', $raw);
        $this->assertSame([], $activity->fresh()->meta);
    }

    public function test_by_system_state_leaves_the_actor_null(): void
    {
        $activity = TicketActivity::factory()->bySystem()->create();

        $this->assertNull($activity->user_id);
        $this->assertDatabaseHas('ticket_activities', ['id' => $activity->getKey(), 'user_id' => null]);
    }

    public function test_the_generated_columns_are_still_unreachable(): void
    {
        $this->assertTrue(Schema::hasColumn('priorities', 'is_default_unique'));

        $this->expectException(QueryException::class);
        DB::table('priorities')->insert([
            'name' => 'Broken', 'slug' => 'broken', 'level' => 99,
            'color' => '#000000', 'is_default' => 1, 'is_default_unique' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
