<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Data\AssignmentChange;
use App\Enums\AssignmentRequestStatus;
use App\Enums\TicketActivityEvent;
use App\Events\TicketAssigned;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\DeclineAssignmentRequestRequest;
use App\Http\Resources\V1\TicketAssignmentRequestResource;
use App\Models\Ticket;
use App\Models\TicketAssignmentRequest;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\TicketAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AssignmentRequestController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', TicketAssignmentRequest::class);
        $status = $request->validate([
            'status' => ['sometimes', Rule::enum(AssignmentRequestStatus::class)],
        ])['status'] ?? AssignmentRequestStatus::Pending->value;

        $requests = TicketAssignmentRequest::query()
            ->where('status', $status)
            ->with(['ticket.status', 'ticket.priority', 'requester', 'decidedBy'])
            ->orderBy('created_at')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return TicketAssignmentRequestResource::collection($requests);
    }

    public function approve(Request $request, TicketAssignmentRequest $assignmentRequest, ActivityRecorder $recorder, TicketAssignment $assignment): JsonResponse
    {
        $this->authorize('decide', $assignmentRequest);
        $actorId = $request->user()->getKey();

        DB::transaction(function () use ($assignmentRequest, $actorId, $recorder, $assignment): void {
            $locked = TicketAssignmentRequest::query()->whereKey($assignmentRequest->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== AssignmentRequestStatus::Pending) {
                throw ValidationException::withMessages(['status' => ['This request has already been decided.']]);
            }
            $requester = User::query()->whereKey($locked->user_id)->firstOrFail();
            if (! $requester->isAgent() || ! $requester->is_active) {
                throw ValidationException::withMessages(['user' => ['That user is no longer an active agent.']]);
            }
            $ticket = Ticket::query()->whereKey($locked->ticket_id)->lockForUpdate()->firstOrFail();
            if ($ticket->assigned_to !== null) {
                throw ValidationException::withMessages(['ticket' => ['This ticket already has an assignee. Decline the request or reassign the ticket directly.']]);
            }

            $change = new AssignmentChange($actorId, $locked->user_id, 'assignment_request');
            $assignment->changeAssignee($ticket, $recorder, $change);

            $locked->forceFill([
                'status' => AssignmentRequestStatus::Approved,
                'decided_by' => $actorId,
                'decided_at' => now(),
            ])->save();

            $this->declineOthers($locked, $actorId, $recorder);
        });

        TicketAssigned::dispatch($assignmentRequest->ticket_id, $assignmentRequest->user_id, $actorId, null);

        return TicketAssignmentRequestResource::make($assignmentRequest->fresh(['ticket', 'requester', 'decidedBy']))->response();
    }

    public function decline(DeclineAssignmentRequestRequest $request, TicketAssignmentRequest $assignmentRequest, ActivityRecorder $recorder): JsonResponse
    {
        $this->authorize('decide', $assignmentRequest);
        $actorId = $request->user()->getKey();
        $note = $request->validated('note');

        DB::transaction(function () use ($assignmentRequest, $actorId, $note, $recorder): void {
            $locked = TicketAssignmentRequest::query()->whereKey($assignmentRequest->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== AssignmentRequestStatus::Pending) {
                throw ValidationException::withMessages(['status' => ['This request has already been decided.']]);
            }
            $locked->forceFill([
                'status' => AssignmentRequestStatus::Declined,
                'decided_by' => $actorId,
                'decision_note' => $note,
                'decided_at' => now(),
            ])->save();
            $recorder->record($locked->ticket_id, TicketActivityEvent::AssignmentRequestDeclined, [
                'user_id' => $actorId,
                'meta' => array_filter(['reason' => $note], fn ($value) => $value !== null),
            ]);
        });

        return TicketAssignmentRequestResource::make($assignmentRequest->fresh(['ticket', 'requester', 'decidedBy']))->response();
    }

    /** Every other pending request on the same ticket loses once one is approved. */
    private function declineOthers(TicketAssignmentRequest $approved, int $actorId, ActivityRecorder $recorder): void
    {
        $others = TicketAssignmentRequest::query()->pending()
            ->where('ticket_id', $approved->ticket_id)
            ->whereKeyNot($approved->getKey())
            ->get();

        foreach ($others as $other) {
            $other->forceFill([
                'status' => AssignmentRequestStatus::Declined,
                'decided_by' => $actorId,
                'decision_note' => 'Another agent was assigned.',
                'decided_at' => now(),
            ])->save();
            $recorder->record($other->ticket_id, TicketActivityEvent::AssignmentRequestDeclined, [
                'user_id' => $actorId,
                'meta' => ['reason' => 'Another agent was assigned.'],
            ]);
        }
    }
}
