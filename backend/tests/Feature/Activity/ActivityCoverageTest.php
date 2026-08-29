<?php

namespace Tests\Feature\Activity;

use App\Enums\TicketActivityEvent;
use App\Models\Ticket;
use App\Services\ActivityRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ties every TicketActivityEvent case to a named test class that proves it is
 * written. A case absent from PRODUCERS is a mutation path nobody is testing.
 */
class ActivityCoverageTest extends TestCase
{
    use RefreshDatabase;

    private const PRODUCERS = [
        'created' => 'Tests\Feature\Tickets\CreateTicketTest',
        'category_changed' => 'Tests\Feature\Categories\DeleteCategoryTest',
        'note_added' => 'Tests\Feature\Activity\TicketNotesTest',
        'updated' => 'Tests\Feature\Tickets\UpdateTicketTest',
        'deleted' => 'Tests\Feature\Tickets\DeleteTicketTest',
        'assigned' => 'Tests\Feature\Tickets\AssignTicketTest',
        'claimed' => 'Tests\Feature\Tickets\ClaimTicketTest',
        'unassigned' => 'Tests\Feature\Tickets\AssignTicketTest',
        'status_changed' => 'Tests\Feature\Tickets\TicketStatusTest',
        'reopened' => 'Tests\Feature\Tickets\TicketStatusTest',
        'escalated' => 'Tests\Feature\Tickets\TicketEscalateTest',
        'stale' => 'Tests\Feature\Console\FlagStaleTicketsTest',
    ];

    public function test_every_event_case_has_a_producing_test(): void
    {
        foreach (TicketActivityEvent::cases() as $case) {
            $this->assertArrayHasKey($case->value, self::PRODUCERS, "No producing test class named for [{$case->value}].");
        }
    }

    public function test_every_named_producer_class_exists(): void
    {
        foreach (self::PRODUCERS as $event => $class) {
            $this->assertTrue(class_exists($class), "Producer class [{$class}] for [{$event}] does not exist.");
        }
    }

    /**
     * Writes one row per case through the real writer (ActivityRecorder,
     * never a raw insert) and reads each back through the model's enum cast
     * -- the regression guard for a raw string slipping past every producer
     * and only surfacing as a ValueError the next time someone lists activity.
     */
    public function test_every_stored_event_round_trips_through_the_enum(): void
    {
        $this->seed();
        $ticket = Ticket::factory()->create();

        DB::transaction(function () use ($ticket): void {
            $recorder = app(ActivityRecorder::class);
            foreach (TicketActivityEvent::cases() as $case) {
                $recorder->record($ticket->getKey(), $case);
            }
        });

        foreach (DB::table('ticket_activities')->distinct()->pluck('event') as $raw) {
            $this->assertNotNull(TicketActivityEvent::tryFrom($raw), "Stored event [{$raw}] is not a valid TicketActivityEvent.");
        }
        $ticket->activities()->get()->each(fn ($activity) => $activity->event);
        $this->assertTrue(true);
    }
}
