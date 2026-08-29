<?php

namespace Tests\Feature\Activity;

use App\Enums\TicketActivityEvent;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TicketNotesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_a_note_is_stored_as_one_note_added_row(): void
    {
        $ticket = Ticket::factory()->create();
        $agent = User::factory()->agent()->create();

        $this->withToken($this->tokenFor($agent))
            ->postJson($this->url($ticket), ['body' => 'Customer confirmed the fix works.'])
            ->assertCreated();

        $rows = TicketActivity::query()->where('ticket_id', $ticket->id)->get();
        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame(TicketActivityEvent::NoteAdded, $row->event);
        $this->assertSame('Customer confirmed the fix works.', $row->meta['note']);
        $this->assertSame($agent->id, $row->user_id);
        $this->assertNull($row->field);
        $this->assertNull($row->old_value);
        $this->assertNull($row->new_value);
    }

    public function test_the_response_is_the_created_activity_with_its_actor(): void
    {
        $ticket = Ticket::factory()->create();
        $agent = User::factory()->agent()->create();

        $response = $this->withToken($this->tokenFor($agent))
            ->postJson($this->url($ticket), ['body' => 'Waiting on vendor reply.'])
            ->assertCreated();

        $response->assertJsonPath('data.event', 'note_added')
            ->assertJsonPath('data.meta.note', 'Waiting on vendor reply.')
            ->assertJsonPath('data.actor.name', $agent->name)
            ->assertJsonPath('data.field', null)
            ->assertJsonPath('data.old_value', null)
            ->assertJsonPath('data.new_value', null)
            ->assertJsonPath('data.from_label', null)
            ->assertJsonPath('data.to_label', null);
    }

    public function test_the_body_length_bounds_are_enforced(): void
    {
        $ticket = Ticket::factory()->create();
        $token = $this->tokenFor(User::factory()->agent()->create());

        $this->withToken($token)->postJson($this->url($ticket), ['body' => 'ab'])
            ->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->withToken($token)->postJson($this->url($ticket), ['body' => 'abc'])
            ->assertCreated();
        $this->withToken($token)->postJson($this->url($ticket), ['body' => str_repeat('a', 5000)])
            ->assertCreated();
        $this->withToken($token)->postJson($this->url($ticket), ['body' => str_repeat('a', 5001)])
            ->assertUnprocessable()->assertJsonValidationErrors('body');
    }

    public function test_an_empty_or_whitespace_body_is_rejected(): void
    {
        $ticket = Ticket::factory()->create();
        $token = $this->tokenFor(User::factory()->agent()->create());

        foreach (['', '   ', "\t", "\n"] as $body) {
            $this->withToken($token)->postJson($this->url($ticket), ['body' => $body])
                ->assertUnprocessable()
                ->assertJsonPath('errors.body.0', 'Write something before saving the note.');
        }
        $this->withToken($token)->postJson($this->url($ticket), [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.body.0', 'Write something before saving the note.');
    }

    public function test_markup_and_unicode_are_stored_byte_identically(): void
    {
        $ticket = Ticket::factory()->create();
        $token = $this->tokenFor(User::factory()->agent()->create());
        $body = "<script>alert(1)</script> مرحبا 🎫\nsecond line";

        $this->withToken($token)->postJson($this->url($ticket), ['body' => $body])
            ->assertCreated()
            ->assertJsonPath('data.meta.note', $body);

        $rawMeta = DB::table('ticket_activities')->where('ticket_id', $ticket->id)->value('meta');
        $this->assertStringContainsString('<script>', $rawMeta);
    }

    public function test_adding_a_note_touches_no_ticket_column(): void
    {
        $ticket = Ticket::factory()->create();
        $before = $ticket->fresh()->only(['updated_at', 'status_id', 'escalation_level', 'escalated_at', 'first_responded_at', 'resolved_at', 'closed_at']);
        $token = $this->tokenFor(User::factory()->agent()->create());

        $this->withToken($token)->postJson($this->url($ticket), ['body' => 'First note.'])->assertCreated();
        $this->travel(1)->hour();
        $this->withToken($token)->postJson($this->url($ticket), ['body' => 'Second note.'])->assertCreated();

        $after = $ticket->fresh()->only(['updated_at', 'status_id', 'escalation_level', 'escalated_at', 'first_responded_at', 'resolved_at', 'closed_at']);
        $this->assertEquals($before, $after);
    }

    public function test_the_activity_model_does_not_touch_its_parent(): void
    {
        $this->assertSame([], (new TicketActivity)->getTouchedRelations());
    }

    public function test_adding_a_note_sends_and_queues_nothing(): void
    {
        // This does NOT discharge E8-S3's or E8-S6's "no internal notes leak
        // into a requester email" criterion -- there is no mailable to test
        // against yet. It fails the day mail is wired into this endpoint.
        Mail::fake();
        Notification::fake();
        $ticket = Ticket::factory()->create();
        $token = $this->tokenFor(User::factory()->agent()->create());

        $this->withToken($token)->postJson($this->url($ticket), ['body' => 'Internal only.'])->assertCreated();

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
    }

    public function test_an_agent_may_note_a_ticket(): void
    {
        $ticket = Ticket::factory()->create();
        $this->withToken($this->tokenFor(User::factory()->agent()->create()))
            ->postJson($this->url($ticket), ['body' => 'Looks fine now.'])
            ->assertCreated();
    }

    public function test_a_terminal_ticket_may_be_noted(): void
    {
        $terminalStatus = Status::query()->where('is_terminal', true)->firstOrFail();
        $ticket = Ticket::factory()->create(['status_id' => $terminalStatus->id]);

        $this->withToken($this->tokenFor(User::factory()->agent()->create()))
            ->postJson($this->url($ticket), ['body' => 'Post-mortem: root cause was the firmware.'])
            ->assertCreated();
    }

    public function test_a_soft_deleted_ticket_returns_404(): void
    {
        $ticket = Ticket::factory()->create();
        $ticket->delete();

        $this->withToken($this->tokenFor(User::factory()->agent()->create()))
            ->postJson($this->url($ticket), ['body' => 'Should not land.'])
            ->assertNotFound();
        $this->assertSame(0, TicketActivity::query()->where('ticket_id', $ticket->id)->count());
    }

    public function test_unknown_ticket_and_unauthenticated_are_rejected(): void
    {
        $ticket = Ticket::factory()->create();
        $this->postJson($this->url($ticket), ['body' => 'Anything.'])->assertUnauthorized();

        $this->withToken($this->tokenFor(User::factory()->agent()->create()))
            ->postJson('/api/v1/tickets/999999/notes', ['body' => 'Anything.'])
            ->assertNotFound();
    }

    public function test_two_sequential_notes_each_return_their_own_body(): void
    {
        $ticket = Ticket::factory()->create();
        $token = $this->tokenFor(User::factory()->agent()->create());

        $first = $this->withToken($token)->postJson($this->url($ticket), ['body' => 'Note A'])->assertCreated();
        $second = $this->withToken($token)->postJson($this->url($ticket), ['body' => 'Note B'])->assertCreated();

        $this->assertSame('Note A', $first->json('data.meta.note'));
        $this->assertSame('Note B', $second->json('data.meta.note'));
        $this->assertNotSame($first->json('data.id'), $second->json('data.id'));
    }

    // test_no_route_can_amend_or_remove_a_note lived here (TM-47). TM-48's
    // ActivityRouteImmutabilityTest::test_every_mutating_verb_against_every_activity_path_is_refused
    // absorbs it at full breadth -- it scans every activity and note path,
    // not just this one -- so the narrower copy was deleted rather than kept
    // beside it.

    private function url(Ticket $ticket): string
    {
        return "/api/v1/tickets/{$ticket->id}/notes";
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }
}
