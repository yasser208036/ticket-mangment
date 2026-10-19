<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketActivityEvent;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\TicketAssignmentRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * view() admits a ticket's author, so it cannot gate the audit trail: the
 * timeline carries internal notes, assignment-request notes and decline and
 * escalation reasons. TicketPolicy::viewActivity keeps them staff-only.
 */
class TicketTrailIsInternalTest extends TestCase
{
    use RefreshDatabase;

    private const NOTE = 'Internal: the customer has history of chargebacks.';

    private const ESCALATION_REASON = 'Escalating because the outage affects the whole building.';

    private const DECLINE_NOTE = 'Declined: this agent is on leave next week.';

    private User $admin;

    private User $holdingAgent;

    private User $author;

    private User $otherEndUser;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::factory()->admin()->create();
        $this->holdingAgent = User::factory()->agent()->create();
        $this->author = User::factory()->endUser()->create();
        $this->otherEndUser = User::factory()->endUser()->create();

        $this->ticket = Ticket::factory()->unassigned()->create(['created_by' => $this->author->getKey()]);
        $this->fillTrailWithSensitiveRows();
    }

    /** Leaves the ticket held by $holdingAgent, with every sensitive row type in its trail. */
    private function fillTrailWithSensitiveRows(): void
    {
        $requester = User::factory()->agent()->create();
        $request = TicketAssignmentRequest::query()->create([
            'ticket_id' => $this->ticket->getKey(),
            'user_id' => $requester->getKey(),
        ]);
        $this->asUser($this->admin)
            ->postJson("/api/v1/admin/assignment-requests/{$request->id}/decline", ['note' => self::DECLINE_NOTE])
            ->assertOk();

        $this->assign();
        $this->asUser($this->holdingAgent)
            ->postJson("/api/v1/tickets/{$this->ticket->id}/notes", ['body' => self::NOTE])
            ->assertCreated();
        // Escalation reassigns to an admin, so hand the ticket back afterwards.
        $this->asUser($this->admin)
            ->postJson("/api/v1/tickets/{$this->ticket->id}/escalate", ['reason' => self::ESCALATION_REASON])
            ->assertOk();
        $this->assign();
    }

    private function assign(): void
    {
        $this->asUser($this->admin)
            ->postJson("/api/v1/tickets/{$this->ticket->id}/assign", ['assigned_to' => $this->holdingAgent->getKey()])
            ->assertOk();
    }

    /** Sanctum's guard caches the first resolved user, so each request starts clean. */
    private function asUser(User $user): static
    {
        Auth::forgetGuards();

        return $this->withToken($user->createToken('test')->plainTextToken);
    }

    private function activitiesUrl(): string
    {
        return "/api/v1/tickets/{$this->ticket->id}/activities";
    }

    public function test_the_author_cannot_read_the_trail_and_the_body_leaks_nothing(): void
    {
        $response = $this->asUser($this->author)->getJson($this->activitiesUrl())->assertForbidden();

        $body = $response->getContent();
        $this->assertStringNotContainsString(self::NOTE, $body);
        $this->assertStringNotContainsString(self::ESCALATION_REASON, $body);
        $this->assertStringNotContainsString(self::DECLINE_NOTE, $body);
    }

    public function test_staff_still_read_the_trail(): void
    {
        foreach ([$this->holdingAgent, $this->admin] as $staff) {
            $response = $this->asUser($staff)->getJson($this->activitiesUrl())->assertOk();

            $this->assertContains(self::NOTE, $response->json('data.*.meta.note'));
        }
    }

    public function test_the_author_sees_the_ticket_without_the_escalation_reason_or_actor(): void
    {
        $this->asUser($this->author)->getJson("/api/v1/tickets/{$this->ticket->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.escalation_reason')
            ->assertJsonMissingPath('data.escalated_by')
            ->assertJsonPath('data.escalation_level', 1);

        $this->asUser($this->admin)->getJson("/api/v1/tickets/{$this->ticket->id}")
            ->assertOk()
            ->assertJsonPath('data.escalation_reason', self::ESCALATION_REASON)
            ->assertJsonPath('data.escalated_by.id', $this->admin->getKey());
    }

    public function test_the_author_cannot_add_a_note(): void
    {
        $before = TicketActivity::query()->where('event', TicketActivityEvent::NoteAdded)->count();

        $this->asUser($this->author)
            ->postJson("/api/v1/tickets/{$this->ticket->id}/notes", ['body' => 'Let me add my own internal note.'])
            ->assertForbidden();

        $this->assertSame($before, TicketActivity::query()->where('event', TicketActivityEvent::NoteAdded)->count());
    }

    public function test_another_end_user_is_refused_and_the_event_filter_changes_nothing(): void
    {
        $this->asUser($this->otherEndUser)->getJson($this->activitiesUrl())->assertForbidden();
        $this->asUser($this->otherEndUser)->getJson($this->activitiesUrl().'?events[]=note_added')->assertForbidden();
        $this->asUser($this->author)->getJson($this->activitiesUrl().'?events[]=note_added')->assertForbidden();
    }
}
