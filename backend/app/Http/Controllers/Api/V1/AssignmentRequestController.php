<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AssignmentRequestStatus;
use App\Enums\TicketActivityEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreAssignmentRequestRequest;
use App\Http\Resources\V1\TicketAssignmentRequestResource;
use App\Models\Ticket;
use App\Models\TicketAssignmentRequest;
use App\Services\ActivityRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssignmentRequestController extends Controller
{
    public function store(StoreAssignmentRequestRequest $request, Ticket $ticket, ActivityRecorder $recorder): JsonResponse
    {
        $actorId = $request->user()->getKey();
        $note = $request->validated('note');

        $assignmentRequest = DB::transaction(function () use ($ticket, $actorId, $note, $recorder): TicketAssignmentRequest {
            Ticket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();
            $ticket->refresh();

            // Re-checked under the lock: the form request's own check ran
            // before this transaction opened, so a concurrent /assign could
            // have landed in between. A denied policy can only ever be a
            // 403, so this half of the precondition lives here for a 422.
            if ($ticket->assigned_to !== null) {
                throw ValidationException::withMessages(['ticket' => ['This ticket already has an assignee.']]);
            }

            // create()'s in-memory model does not pick up the column's DB
            // default, and 'status' is deliberately not mass-assignable -- so
            // it is set explicitly rather than left to a post-create refresh.
            $assignmentRequest = new TicketAssignmentRequest([
                'ticket_id' => $ticket->getKey(),
                'user_id' => $actorId,
                'note' => $note,
            ]);
            $assignmentRequest->status = AssignmentRequestStatus::Pending;
            $assignmentRequest->save();

            $recorder->record($ticket->getKey(), TicketActivityEvent::AssignmentRequested, [
                'user_id' => $actorId,
                'meta' => array_filter(['note' => $note], fn ($value) => $value !== null),
            ]);

            return $assignmentRequest;
        });

        return TicketAssignmentRequestResource::make($assignmentRequest)->response()->setStatusCode(201);
    }
}
