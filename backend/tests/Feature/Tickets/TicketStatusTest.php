<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketActivityEvent;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class TicketStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $ticket = $this->ticketAt('new');
        $this->postJson($this->url($ticket), ['status_id' => $this->statusId('open')])->assertUnauthorized();
    }

    public function test_an_agent_makes_a_legal_move(): void
    {
        $ticket = $this->ticketAt('new');
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('open')])
            ->assertOk()
            ->assertJsonPath('data.status.slug', 'open');
        $this->assertSame('open', $ticket->fresh()->status->slug);
    }

    public function test_the_response_omits_can_and_allowed_transitions(): void
    {
        $ticket = $this->ticketAt('new');
        $response = $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('open')])->assertOk();
        $response->assertJsonMissingPath('data.can')->assertJsonMissingPath('data.allowed_transitions');
        $response->assertJsonPath('data.requester.id', fn ($id) => $id !== null)
            ->assertJsonPath('data.category.id', fn ($id) => $id !== null)
            ->assertJsonPath('data.priority.id', fn ($id) => $id !== null)
            ->assertJsonPath('data.status.id', fn ($id) => $id !== null)
            ->assertJsonPath('data.creator.id', fn ($id) => $id !== null);
    }

    public function test_the_change_writes_one_status_changed_row(): void
    {
        $ticket = $this->ticketAt('new');
        $newId = $ticket->status_id;
        $openId = $this->statusId('open');
        $agent = User::factory()->agent()->create();

        $this->withToken($this->tokenFor($agent))->postJson($this->url($ticket), ['status_id' => $openId])->assertOk();

        $row = TicketActivity::query()->where('ticket_id', $ticket->id)->where('event', TicketActivityEvent::StatusChanged->value)->firstOrFail();
        $this->assertSame('status_id', $row->field);
        $this->assertSame((string) $newId, $row->old_value);
        $this->assertSame((string) $openId, $row->new_value);
        $this->assertSame('New', $row->meta['from_name']);
        $this->assertSame('Open', $row->meta['to_name']);
        $this->assertSame($agent->id, $row->user_id);
        $this->assertSame(1, TicketActivity::query()->where('ticket_id', $ticket->id)->count());
    }

    public function test_an_illegal_move_is_rejected_and_changes_nothing(): void
    {
        // 'closed' needs neither `resolution` nor `reason`, so the 422 below can
        // only come from TicketWorkflow -- a target of 'resolved' would 422 on
        // the missing `resolution` field first and never reach the workflow.
        $ticket = $this->ticketAt('new');
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('closed')])
            ->assertStatus(422)
            ->assertJsonPath('errors.status_id.0', 'A ticket cannot move from New to Closed.');
        $this->assertSame('new', $ticket->fresh()->status->slug);
        $this->assertSame(0, TicketActivity::query()->where('ticket_id', $ticket->id)->count());
    }

    public function test_an_admin_only_edge_is_refused_for_an_agent(): void
    {
        $ticket = $this->ticketAt('resolved');
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('closed')])
            ->assertStatus(422)
            ->assertJsonPath('errors.status_id.0', 'Only an administrator can move a ticket from Resolved to Closed.');
    }

    public function test_an_admin_only_edge_is_allowed_for_an_admin(): void
    {
        $ticket = $this->ticketAt('resolved');
        $this->asAdmin()->postJson($this->url($ticket), ['status_id' => $this->statusId('closed')])->assertOk();
    }

    public function test_moving_to_the_current_status_is_rejected(): void
    {
        $ticket = $this->ticketAt('open');
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('open')])
            ->assertStatus(422)
            ->assertJsonPath('errors.status_id.0', 'This ticket is already Open.');
    }

    public function test_an_unknown_status_id_is_rejected_before_the_workflow(): void
    {
        $ticket = $this->ticketAt('new');
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => 999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status_id');
        $this->assertSame(0, TicketActivity::query()->where('ticket_id', $ticket->id)->count());
    }

    public function test_missing_and_malformed_status_id_are_rejected(): void
    {
        $ticket = $this->ticketAt('new');
        $this->asAgent()->postJson($this->url($ticket), [])->assertStatus(422)->assertJsonValidationErrors('status_id');
        Auth::forgetGuards();
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => null])->assertStatus(422)->assertJsonValidationErrors('status_id');
        Auth::forgetGuards();
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('status_id');
    }

    public function test_resolution_is_required_moving_into_resolved_and_prohibited_otherwise(): void
    {
        $ticket = $this->ticketAt('in-progress');
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('resolved')])
            ->assertStatus(422)->assertJsonValidationErrors('resolution');
        Auth::forgetGuards();
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('resolved'), 'resolution' => 'Fixed the jam.'])
            ->assertOk()->assertJsonPath('data.status.slug', 'resolved');

        $other = $this->ticketAt('new');
        Auth::forgetGuards();
        $this->asAgent()->postJson($this->url($other), ['status_id' => $this->statusId('open'), 'resolution' => 'Not allowed here.'])
            ->assertStatus(422)->assertJsonValidationErrors('resolution');
    }

    public function test_reason_is_required_moving_into_reopened_and_prohibited_otherwise(): void
    {
        $ticket = $this->ticketAt('resolved');
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('reopened')])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        Auth::forgetGuards();
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('reopened'), 'reason' => 'Customer says it broke again.'])
            ->assertOk()->assertJsonPath('data.status.slug', 'reopened');

        $other = $this->ticketAt('new');
        Auth::forgetGuards();
        $this->asAgent()->postJson($this->url($other), ['status_id' => $this->statusId('open'), 'reason' => 'Not allowed here.'])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_reopening_writes_a_reopened_event_not_status_changed(): void
    {
        $ticket = $this->ticketAt('closed');
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('reopened'), 'reason' => 'Customer says it broke again.'])->assertOk();

        $this->assertSame(1, TicketActivity::query()->where('ticket_id', $ticket->id)->where('event', TicketActivityEvent::Reopened->value)->count());
        $this->assertSame(0, TicketActivity::query()->where('ticket_id', $ticket->id)->where('event', TicketActivityEvent::StatusChanged->value)->count());
    }

    public function test_first_responded_at_is_set_once_on_the_first_move_off_new(): void
    {
        $ticket = $this->ticketAt('new');
        $this->assertNull($ticket->first_responded_at);
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('open')])->assertOk();
        $firstStamp = $ticket->fresh()->first_responded_at;
        $this->assertNotNull($firstStamp);

        Auth::forgetGuards();
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('pending')])->assertOk();
        $this->assertEquals($firstStamp, $ticket->fresh()->first_responded_at);
    }

    public function test_resolved_at_and_closed_at_are_stamped_and_cleared_on_reopen(): void
    {
        $ticket = $this->ticketAt('in-progress');
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('resolved'), 'resolution' => 'Fixed the jam.'])->assertOk();
        $this->assertNotNull($ticket->fresh()->resolved_at);
        $this->assertNull($ticket->fresh()->closed_at);

        Auth::forgetGuards();
        $this->asAdmin()->postJson($this->url($ticket), ['status_id' => $this->statusId('closed')])->assertOk();
        $this->assertNotNull($ticket->fresh()->closed_at);

        Auth::forgetGuards();
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('reopened'), 'reason' => 'Customer says it broke again.'])->assertOk();
        $this->assertNull($ticket->fresh()->resolved_at);
        $this->assertNull($ticket->fresh()->closed_at);
    }

    public function test_a_nonexistent_ticket_is_404_for_both_roles(): void
    {
        $this->asAgent()->postJson('/api/v1/tickets/999999/status', ['status_id' => $this->statusId('open')])->assertNotFound();
        Auth::forgetGuards();
        $this->asAdmin()->postJson('/api/v1/tickets/999999/status', ['status_id' => $this->statusId('open')])->assertNotFound();
    }

    public function test_a_soft_deleted_ticket_is_404(): void
    {
        $ticket = $this->ticketAt('new');
        $ticket->delete();
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('open')])->assertNotFound();
    }

    public function test_the_change_is_atomic(): void
    {
        $ticket = $this->ticketAt('new');
        $this->mock(ActivityRecorder::class, function ($mock): void {
            $mock->shouldReceive('record')->once()->andThrow(new RuntimeException('boom'));
        });
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('open')])->assertServerError();
        $this->assertSame('new', $ticket->fresh()->status->slug);
    }

    public function test_show_returns_allowed_transitions_filtered_by_role(): void
    {
        $ticket = $this->ticketAt('resolved');
        $this->asAgent()->getJson("/api/v1/tickets/{$ticket->id}")->assertJsonPath('data.allowed_transitions.*.slug', ['reopened']);
        Auth::forgetGuards();
        $this->asAdmin()->getJson("/api/v1/tickets/{$ticket->id}")->assertJsonPath('data.allowed_transitions.*.slug', ['closed', 'reopened']);
    }

    public function test_allowed_transitions_is_absent_from_the_index_and_the_status_response(): void
    {
        $ticket = $this->ticketAt('new');
        $this->asAgent()->getJson('/api/v1/tickets')->assertJsonMissingPath('data.0.allowed_transitions');
        Auth::forgetGuards();
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('open')])->assertJsonMissingPath('data.allowed_transitions');
    }

    public function test_the_status_route_carries_no_admin_middleware(): void
    {
        $middleware = collect(Route::getRoutes())->first(fn ($route) => $route->getName() === 'tickets.status')->gatherMiddleware();
        $this->assertNotContains('admin', $middleware);
    }

    public function test_resolving_without_a_note_names_the_note(): void
    {
        $ticket = $this->ticketAt('in-progress');
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('resolved')])
            ->assertStatus(422)
            ->assertJsonPath('errors.resolution.0', 'Say how the ticket was resolved before resolving it.');
    }

    public function test_a_whitespace_only_note_is_rejected_as_required(): void
    {
        // TrimStrings rewrites "   " to "" before validation, so this fails
        // `required`, not `min` -- no redundant trim() belongs in the request.
        $ticket = $this->ticketAt('in-progress');
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('resolved'), 'resolution' => '   '])
            ->assertStatus(422)
            ->assertJsonPath('errors.resolution.0', 'Say how the ticket was resolved before resolving it.');
    }

    public function test_a_short_note_is_rejected(): void
    {
        $ticket = $this->ticketAt('in-progress');
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('resolved'), 'resolution' => 'too short'])
            ->assertStatus(422)
            ->assertJsonPath('errors.resolution.0', 'The resolution note must be at least 10 characters.');
    }

    public function test_an_overlong_note_is_rejected(): void
    {
        $ticket = $this->ticketAt('in-progress');
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('resolved'), 'resolution' => str_repeat('a', 5001)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('resolution');
    }

    public function test_a_note_is_stored_verbatim(): void
    {
        $script = $this->ticketAt('in-progress');
        $this->asAgent()->postJson($this->url($script), ['status_id' => $this->statusId('resolved'), 'resolution' => '<script>alert(1)</script>, ten chars.'])->assertOk();
        $scriptRow = TicketActivity::query()->where('ticket_id', $script->id)->where('event', TicketActivityEvent::StatusChanged->value)->firstOrFail();
        $this->assertSame('<script>alert(1)</script>, ten chars.', $scriptRow->meta['resolution']);

        Auth::forgetGuards();
        $arabic = $this->ticketAt('in-progress');
        $this->asAgent()->postJson($this->url($arabic), ['status_id' => $this->statusId('resolved'), 'resolution' => 'تم الإصلاح بنجاح كامل'])->assertOk();
        $arabicRow = TicketActivity::query()->where('ticket_id', $arabic->id)->where('event', TicketActivityEvent::StatusChanged->value)->firstOrFail();
        $this->assertSame('تم الإصلاح بنجاح كامل', $arabicRow->meta['resolution']);
    }

    public function test_the_note_is_stored_on_the_activity_row_alongside_the_move(): void
    {
        $ticket = $this->ticketAt('in-progress');
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('resolved'), 'resolution' => 'Replaced the failed PSU.'])->assertOk();

        $row = TicketActivity::query()->where('ticket_id', $ticket->id)->where('event', TicketActivityEvent::StatusChanged->value)->firstOrFail();
        $this->assertSame('Replaced the failed PSU.', $row->meta['resolution']);
        $this->assertSame('In Progress', $row->meta['from_name']);
        $this->assertSame('Resolved', $row->meta['to_name']);
    }

    public function test_resolving_an_already_resolved_ticket_is_rejected_before_the_note(): void
    {
        $ticket = $this->ticketAt('resolved');
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('resolved'), 'resolution' => 'A brand new valid note.'])
            ->assertStatus(422)
            ->assertJsonPath('errors.status_id.0', 'This ticket is already Resolved.');
    }

    public function test_a_second_resolution_supersedes_the_first(): void
    {
        // Pins latest('id') in TicketResource::currentResolution(): swap it
        // for first() and this fails, because the first note would still win.
        $ticket = $this->ticketAt('in-progress');
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('resolved'), 'resolution' => 'First fix attempt here.'])->assertOk();
        Auth::forgetGuards();
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('reopened'), 'reason' => 'It broke again overnight.'])->assertOk();
        Auth::forgetGuards();
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('resolved'), 'resolution' => 'Second, different fix applied.'])->assertOk();

        $this->assertSame(2, TicketActivity::query()->where('ticket_id', $ticket->id)->whereNotNull('meta->resolution')->count());
        Auth::forgetGuards();
        $response = $this->asAgent()->getJson("/api/v1/tickets/{$ticket->id}");
        $this->assertSame('Second, different fix applied.', $response->json('data.resolution.note'));
    }

    public function test_an_unresolved_ticket_reports_a_null_resolution(): void
    {
        $ticket = $this->ticketAt('new');
        $response = $this->asAgent()->getJson("/api/v1/tickets/{$ticket->id}");
        $response->assertOk();
        $this->assertArrayHasKey('resolution', $response->json('data'));
        $this->assertNull($response->json('data.resolution'));
    }

    public function test_a_system_authored_resolution_renders_as_null_by(): void
    {
        $ticket = $this->ticketAt('in-progress');
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('resolved'), 'resolution' => 'Fixed by an automated job.'])->assertOk();
        // ticket_activities is append-only (TM-48): the Eloquent model refuses
        // update(); go through the raw query builder, matching how an
        // ON DELETE SET NULL on a deleted user's account would leave the row.
        DB::table('ticket_activities')->where('ticket_id', $ticket->id)->where('event', TicketActivityEvent::StatusChanged->value)->update(['user_id' => null]);

        Auth::forgetGuards();
        $response = $this->asAgent()->getJson("/api/v1/tickets/{$ticket->id}");
        $this->assertNull($response->json('data.resolution.by'));
        $this->assertSame('Fixed by an automated job.', $response->json('data.resolution.note'));
    }

    public function test_resolution_is_absent_from_the_status_response(): void
    {
        $ticket = $this->ticketAt('in-progress');
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('resolved'), 'resolution' => 'Fixed the underlying cause.'])
            ->assertOk()
            ->assertJsonMissingPath('data.resolution');
    }

    public function test_resolution_query_cost(): void
    {
        $unresolved = $this->ticketAt('new');
        $resolved = $this->ticketAt('in-progress');
        $token = $this->tokenFor(User::factory()->agent()->create());
        $this->withToken($token)->postJson($this->url($resolved), ['status_id' => $this->statusId('resolved'), 'resolution' => 'Fixed the underlying cause.'])->assertOk();

        DB::enableQueryLog();
        $this->withToken($token)->getJson("/api/v1/tickets/{$unresolved->id}")->assertOk();
        $unresolvedCount = count(DB::getQueryLog());
        DB::flushQueryLog();

        $this->withToken($token)->getJson("/api/v1/tickets/{$resolved->id}")->assertOk();
        $resolvedCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($unresolvedCount + 2, $resolvedCount);
    }

    public function test_the_timestamps_are_atomic_with_the_status(): void
    {
        $ticket = $this->ticketAt('in-progress');
        $before = $ticket->only(['status_id', 'resolved_at', 'first_responded_at']);
        $this->mock(ActivityRecorder::class, function ($mock): void {
            $mock->shouldReceive('record')->once()->andThrow(new RuntimeException('boom'));
        });
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('resolved'), 'resolution' => 'This will blow up mid-way.'])->assertServerError();

        $this->assertSame($before, $ticket->fresh()->only(['status_id', 'resolved_at', 'first_responded_at']));
    }

    public function test_reopening_without_a_reason_names_what_brought_it_back(): void
    {
        $ticket = $this->ticketAt('closed');
        $closedAtBefore = $ticket->closed_at;
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('reopened')])
            ->assertStatus(422)
            ->assertJsonPath('errors.reason.0', 'Say what brought this ticket back before reopening it.');
        $this->assertEquals($closedAtBefore, $ticket->fresh()->closed_at);
        $this->assertSame(0, TicketActivity::query()->where('ticket_id', $ticket->id)->count());
    }

    public function test_a_whitespace_only_reason_is_rejected_as_required(): void
    {
        $ticket = $this->ticketAt('closed');
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('reopened'), 'reason' => '   '])
            ->assertStatus(422)
            ->assertJsonPath('errors.reason.0', 'Say what brought this ticket back before reopening it.');
    }

    public function test_a_short_reason_is_rejected(): void
    {
        $ticket = $this->ticketAt('closed');
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('reopened'), 'reason' => 'again'])
            ->assertStatus(422)
            ->assertJsonPath('errors.reason.0', 'The reopen reason must be at least 10 characters.');
    }

    public function test_an_overlong_reason_is_rejected(): void
    {
        $ticket = $this->ticketAt('closed');
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('reopened'), 'reason' => str_repeat('a', 5001)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_the_reopen_row_is_a_status_change_by_field(): void
    {
        // The invariant that survives the event split: every status change
        // writes field = 'status_id', whichever event it is. Change that in
        // the controller and this is the test that fails.
        $ticket = $this->ticketAt('closed');
        $newId = $ticket->status_id;
        $reopenedId = $this->statusId('reopened');
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $reopenedId, 'reason' => 'The same disk failed a second time.'])->assertOk();

        $row = TicketActivity::query()->where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertSame('status_id', $row->field);
        $this->assertSame((string) $newId, $row->old_value);
        $this->assertSame((string) $reopenedId, $row->new_value);
    }

    public function test_the_reopen_row_uses_the_reopened_event_and_carries_the_reason(): void
    {
        $ticket = $this->ticketAt('closed');
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('reopened'), 'reason' => 'The same disk failed a second time.'])->assertOk();

        $row = TicketActivity::query()->where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertSame(TicketActivityEvent::Reopened->value, $row->event->value);
        $this->assertSame('The same disk failed a second time.', $row->meta['reason']);
        $this->assertArrayHasKey('from_name', $row->meta);
        $this->assertArrayHasKey('to_name', $row->meta);
        $this->assertSame(0, TicketActivity::query()->where('ticket_id', $ticket->id)->where('event', TicketActivityEvent::StatusChanged->value)->count());
    }

    public function test_a_reason_on_a_non_reopening_move_is_rejected(): void
    {
        $ticket = $this->ticketAt('new');
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('open'), 'reason' => 'Not allowed here at all.'])
            ->assertStatus(422)
            ->assertJsonPath('errors.reason.0', 'A reason belongs only on a move into Reopened.');
    }

    public function test_a_body_with_both_a_resolution_and_a_reason_is_rejected(): void
    {
        $intoResolved = $this->ticketAt('in-progress');
        $this->asAgent()->postJson($this->url($intoResolved), ['status_id' => $this->statusId('resolved'), 'resolution' => 'Fixed the underlying cause.', 'reason' => 'Also this reason here.'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $intoReopened = $this->ticketAt('closed');
        Auth::forgetGuards();
        $this->asAgent()->postJson($this->url($intoReopened), ['status_id' => $this->statusId('reopened'), 'resolution' => 'Also this resolution here.', 'reason' => 'The same disk failed again.'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('resolution');
    }

    public function test_reopening_a_non_terminal_ticket_is_rejected_by_the_graph(): void
    {
        $ticket = $this->ticketAt('open');
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('reopened'), 'reason' => 'A perfectly valid reason.'])
            ->assertStatus(422)
            ->assertJsonPath('errors.status_id.0', 'A ticket cannot move from Open to Reopened.');
    }

    public function test_reopening_an_already_reopened_ticket_is_rejected_before_the_reason(): void
    {
        $ticket = $this->ticketAt('reopened');
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('reopened'), 'reason' => 'A perfectly valid reason.'])
            ->assertStatus(422)
            ->assertJsonPath('errors.status_id.0', 'This ticket is already Reopened.');
    }

    public function test_reopening_clears_both_timestamps_and_the_resolution(): void
    {
        $ticket = $this->ticketAt('in-progress');
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('resolved'), 'resolution' => 'Fixed the underlying cause.'])->assertOk();
        Auth::forgetGuards();
        $this->asAdmin()->postJson($this->url($ticket), ['status_id' => $this->statusId('closed')])->assertOk();
        Auth::forgetGuards();
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('reopened'), 'reason' => 'The same disk failed a second time.'])->assertOk();

        $this->assertNull($ticket->fresh()->resolved_at);
        $this->assertNull($ticket->fresh()->closed_at);
        Auth::forgetGuards();
        $show = $this->asAgent()->getJson("/api/v1/tickets/{$ticket->id}");
        $this->assertNull($show->json('data.resolution'));
        $this->assertSame(1, TicketActivity::query()->where('ticket_id', $ticket->id)->whereNotNull('meta->resolution')->count());
    }

    public function test_a_reason_is_stored_verbatim(): void
    {
        $script = $this->ticketAt('closed');
        $this->asAgent()->postJson($this->url($script), ['status_id' => $this->statusId('reopened'), 'reason' => '<script>alert(1)</script>, ten chars.'])->assertOk();
        $scriptRow = TicketActivity::query()->where('ticket_id', $script->id)->firstOrFail();
        $this->assertSame('<script>alert(1)</script>, ten chars.', $scriptRow->meta['reason']);

        Auth::forgetGuards();
        $arabic = $this->ticketAt('closed');
        $this->asAgent()->postJson($this->url($arabic), ['status_id' => $this->statusId('reopened'), 'reason' => 'انقطع نفس العطل مرة أخرى'])->assertOk();
        $arabicRow = TicketActivity::query()->where('ticket_id', $arabic->id)->firstOrFail();
        $this->assertSame('انقطع نفس العطل مرة أخرى', $arabicRow->meta['reason']);
    }

    public function test_reopening_leaves_assignee_priority_and_escalation_untouched(): void
    {
        $agent = User::factory()->agent()->create();
        $ticket = $this->ticketAt('closed');
        $ticket->assigned_to = $agent->id;
        $ticket->escalation_level = 2;
        $ticket->save();
        $priorityId = $ticket->priority_id;

        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('reopened'), 'reason' => 'The same disk failed a second time.'])->assertOk();

        $fresh = $ticket->fresh();
        $this->assertSame($agent->id, $fresh->assigned_to);
        $this->assertSame($priorityId, $fresh->priority_id);
        $this->assertSame(2, $fresh->escalation_level);
    }

    public function test_reopen_count_is_zero_and_present_on_a_fresh_ticket(): void
    {
        $ticket = $this->ticketAt('new');
        $response = $this->asAgent()->getJson("/api/v1/tickets/{$ticket->id}");
        $this->assertArrayHasKey('reopen_count', $response->json('data'));
        $this->assertSame(0, $response->json('data.reopen_count'));
    }

    public function test_reopen_count_survives_the_ticket_moving_on(): void
    {
        $ticket = $this->ticketAt('closed');
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('reopened'), 'reason' => 'The same disk failed a second time.'])->assertOk();
        Auth::forgetGuards();
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('in-progress')])->assertOk();

        Auth::forgetGuards();
        $response = $this->asAgent()->getJson("/api/v1/tickets/{$ticket->id}");
        $this->assertSame(1, $response->json('data.reopen_count'));
    }

    public function test_reopen_count_counts_every_reopen(): void
    {
        $ticket = $this->ticketAt('in-progress');
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('resolved'), 'resolution' => 'First fix attempt here.'])->assertOk();
        Auth::forgetGuards();
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('reopened'), 'reason' => 'First recurrence of the issue.'])->assertOk();
        Auth::forgetGuards();
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('resolved'), 'resolution' => 'Second, different fix applied.'])->assertOk();
        Auth::forgetGuards();
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('reopened'), 'reason' => 'Second recurrence, unrelated cause.'])->assertOk();

        Auth::forgetGuards();
        $response = $this->asAgent()->getJson("/api/v1/tickets/{$ticket->id}");
        $this->assertSame(2, $response->json('data.reopen_count'));
        $reasons = TicketActivity::query()->where('ticket_id', $ticket->id)->where('event', TicketActivityEvent::Reopened->value)->orderBy('id')->pluck('meta');
        $this->assertSame('First recurrence of the issue.', $reasons[0]['reason']);
        $this->assertSame('Second recurrence, unrelated cause.', $reasons[1]['reason']);
    }

    public function test_show_query_cost_with_reopen_count(): void
    {
        // Measured, 2026-08-28: 12 queries for GET /tickets/{id} on an
        // unresolved ticket. test_resolution_query_cost separately pins the
        // resolved ticket's cost at this figure + 2 (Story 33's resolution
        // lookup), so together the two tests pin all three numbers this
        // story's loadCount and Story 32/33's other per-caller keys cost.
        $ticket = $this->ticketAt('new');
        $token = $this->tokenFor(User::factory()->agent()->create());
        DB::enableQueryLog();
        $this->withToken($token)->getJson("/api/v1/tickets/{$ticket->id}")->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(12, $count);
    }

    public function test_reopen_count_is_absent_from_other_responses(): void
    {
        $ticket = $this->ticketAt('closed');
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('reopened'), 'reason' => 'The same disk failed a second time.'])
            ->assertOk()
            ->assertJsonMissingPath('data.reopen_count');
        Auth::forgetGuards();
        $this->asAgent()->getJson('/api/v1/tickets')->assertJsonMissingPath('data.0.reopen_count');
    }

    public function test_the_reopen_is_atomic(): void
    {
        $ticket = $this->ticketAt('closed');
        $before = $ticket->only(['status_id', 'resolved_at', 'closed_at']);
        $this->mock(ActivityRecorder::class, function ($mock): void {
            $mock->shouldReceive('record')->once()->andThrow(new RuntimeException('boom'));
        });
        $this->asAgent()->postJson($this->url($ticket), ['status_id' => $this->statusId('reopened'), 'reason' => 'This will blow up mid-way.'])->assertServerError();

        $this->assertSame($before, $ticket->fresh()->only(['status_id', 'resolved_at', 'closed_at']));
    }

    private function url(Ticket $ticket): string
    {
        return "/api/v1/tickets/{$ticket->id}/status";
    }

    private function statusId(string $slug): int
    {
        return Status::query()->where('slug', $slug)->firstOrFail()->getKey();
    }

    private function ticketAt(string $slug): Ticket
    {
        return Ticket::factory()->create(['status_id' => $this->statusId($slug)]);
    }

    private function asAgent(): static
    {
        return $this->withToken($this->tokenFor(User::factory()->agent()->create()));
    }

    private function asAdmin(): static
    {
        return $this->withToken($this->tokenFor(User::factory()->admin()->create()));
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }
}
