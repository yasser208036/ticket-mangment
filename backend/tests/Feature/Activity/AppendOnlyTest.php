<?php

namespace Tests\Feature\Activity;

use App\Enums\TicketActivityEvent;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class AppendOnlyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_the_recorder_still_writes(): void
    {
        $ticket = Ticket::factory()->create();
        $this->record($ticket, TicketActivityEvent::Created, ['meta' => ['reference' => $ticket->reference]]);
        $this->record($ticket, TicketActivityEvent::Updated, ['meta' => ['reason' => 'edited']]);

        $rows = TicketActivity::query()->where('ticket_id', $ticket->id)->get();
        $this->assertCount(2, $rows);
        $this->assertSame($ticket->reference, $rows->firstWhere('event', TicketActivityEvent::Created)->meta['reference']);
        $this->assertSame('edited', $rows->firstWhere('event', TicketActivityEvent::Updated)->meta['reason']);
    }

    public function test_saving_a_changed_activity_throws(): void
    {
        $activity = $this->makeActivity();
        $raw = $this->rawRow($activity->id);

        // The attribute must actually be dirtied: performUpdate() skips the
        // write entirely when nothing changed (Model.php:1517), so a clean
        // save() would pass this test for the wrong reason.
        $activity->field = 'changed_field';
        $this->expectException(LogicException::class);
        try {
            $activity->save();
        } finally {
            $this->assertSame($raw, $this->rawRow($activity->id));
        }
    }

    public function test_updating_an_activity_throws(): void
    {
        $activity = $this->makeActivity();
        $raw = $this->rawRow($activity->id);

        $this->expectException(LogicException::class);
        try {
            // A different value than the row already holds: update() funnels
            // through fill() + save(), and a no-op save is not a mutation
            // attempt worth asserting against.
            $activity->update(['event' => TicketActivityEvent::Updated->value]);
        } finally {
            $this->assertSame($raw, $this->rawRow($activity->id));
        }
    }

    public function test_deleting_an_activity_throws(): void
    {
        $activity = $this->makeActivity();
        $countBefore = TicketActivity::query()->count();

        try {
            $activity->delete();
            $this->fail('Expected a LogicException.');
        } catch (LogicException) {
            // expected
        }
        $this->assertSame($countBefore, TicketActivity::query()->count());

        try {
            TicketActivity::destroy($activity->id);
            $this->fail('Expected a LogicException.');
        } catch (LogicException) {
            // expected
        }
        $this->assertSame($countBefore, TicketActivity::query()->count());
    }

    public function test_deleting_through_the_relation_throws(): void
    {
        $ticket = Ticket::factory()->create();
        $this->record($ticket, TicketActivityEvent::Created);
        $countBefore = TicketActivity::query()->count();

        $this->expectException(LogicException::class);
        try {
            $ticket->activities()->delete();
        } finally {
            $this->assertSame($countBefore, TicketActivity::query()->count());
        }
    }

    public function test_a_mass_builder_update_throws(): void
    {
        $activity = $this->makeActivity();
        $raw = $this->rawRow($activity->id);

        $this->expectException(LogicException::class);
        try {
            TicketActivity::query()->whereKey($activity->id)->update(['event' => TicketActivityEvent::Created->value]);
        } finally {
            $this->assertSame($raw, $this->rawRow($activity->id));
        }
    }

    public function test_the_quiet_and_eventless_bypasses_also_throw(): void
    {
        $activity = $this->makeActivity();

        $threw = [];
        try {
            $activity->updateQuietly(['field' => 'x']);
        } catch (LogicException) {
            $threw[] = 'updateQuietly';
        }
        try {
            $activity->deleteQuietly();
        } catch (LogicException) {
            $threw[] = 'deleteQuietly';
        }
        try {
            TicketActivity::withoutEvents(fn () => $activity->delete());
        } catch (LogicException) {
            $threw[] = 'withoutEvents';
        }

        $this->assertSame(['updateQuietly', 'deleteQuietly', 'withoutEvents'], $threw);
    }

    public function test_upsert_increment_decrement_and_truncate_all_throw(): void
    {
        $this->makeActivity();
        $operations = [
            'upsert' => fn () => TicketActivity::query()->upsert([['id' => 1, 'ticket_id' => 1, 'event' => 'created']], ['id']),
            'increment' => fn () => TicketActivity::query()->increment('id'),
            'decrement' => fn () => TicketActivity::query()->decrement('id'),
            'truncate' => fn () => TicketActivity::query()->truncate(),
        ];
        $threw = [];
        foreach ($operations as $name => $operation) {
            try {
                $operation();
            } catch (LogicException) {
                $threw[] = $name;
            }
        }

        $this->assertSame(array_keys($operations), $threw);
    }

    public function test_deleting_a_user_still_nulls_the_actor(): void
    {
        $ticket = Ticket::factory()->create();
        $actor = User::factory()->agent()->create();
        $this->record($ticket, TicketActivityEvent::Updated, ['user_id' => $actor->id]);
        $activityId = TicketActivity::query()->where('ticket_id', $ticket->id)->value('id');

        DB::table('users')->where('id', $actor->id)->delete();

        $activity = TicketActivity::query()->find($activityId);
        $this->assertNotNull($activity);
        $this->assertNull($activity->user_id);
    }

    public function test_a_soft_deleted_ticket_keeps_and_still_exposes_its_activities(): void
    {
        $ticket = Ticket::factory()->create();
        $this->record($ticket, TicketActivityEvent::Created);
        $this->record($ticket, TicketActivityEvent::Updated);

        $ticket->delete();

        $this->assertSame(2, TicketActivity::query()->where('ticket_id', $ticket->id)->count());
        $this->assertCount(2, Ticket::withTrashed()->find($ticket->id)->activities);

        $agentToken = User::factory()->agent()->create()->createToken('t')->plainTextToken;
        $this->withToken($agentToken)->getJson("/api/v1/tickets/{$ticket->id}/activities")->assertNotFound();
    }

    public function test_force_deleting_a_ticket_throws(): void
    {
        $ticket = Ticket::factory()->create();
        $this->record($ticket, TicketActivityEvent::Created);

        foreach (['forceDelete', 'forceDeleteQuietly'] as $method) {
            try {
                $ticket->$method();
                $this->fail("Expected a LogicException from {$method}().");
            } catch (LogicException) {
                // expected
            }
        }
        try {
            Ticket::forceDestroy([$ticket->id]);
            $this->fail('Expected a LogicException from forceDestroy().');
        } catch (LogicException) {
            // expected
        }

        $this->assertSame(1, TicketActivity::query()->where('ticket_id', $ticket->id)->count());
    }

    public function test_restoring_a_soft_deleted_ticket_keeps_its_activities(): void
    {
        $ticket = Ticket::factory()->create();
        $this->record($ticket, TicketActivityEvent::Created);
        $this->record($ticket, TicketActivityEvent::Updated);

        $ticket->delete();
        $ticket->restore();

        $this->assertCount(2, $ticket->fresh()->activities);
    }

    public function test_a_new_activity_can_be_recorded_for_a_soft_deleted_ticket(): void
    {
        $ticket = Ticket::factory()->create();
        $ticket->delete();

        $this->record($ticket, TicketActivityEvent::Deleted, ['meta' => ['reference' => $ticket->reference, 'subject' => $ticket->subject]]);

        $this->assertSame(1, TicketActivity::query()->where('ticket_id', $ticket->id)->count());
    }

    private function makeActivity(): TicketActivity
    {
        $ticket = Ticket::factory()->create();
        $this->record($ticket, TicketActivityEvent::Created);

        return TicketActivity::query()->where('ticket_id', $ticket->id)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function rawRow(int $id): array
    {
        return (array) DB::table('ticket_activities')->where('id', $id)->first();
    }

    /** @param array<string, mixed> $overrides */
    private function record(Ticket $ticket, TicketActivityEvent $event, array $overrides = []): void
    {
        DB::transaction(function () use ($ticket, $event, $overrides): void {
            app(ActivityRecorder::class)->record($ticket->getKey(), $event, $overrides);
        });
    }
}
