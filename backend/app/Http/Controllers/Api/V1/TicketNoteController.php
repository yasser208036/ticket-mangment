<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TicketActivityEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreTicketNoteRequest;
use App\Http\Resources\V1\TicketActivityResource;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Services\ActivityRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class TicketNoteController extends Controller
{
    public function __invoke(StoreTicketNoteRequest $request, Ticket $ticket, ActivityRecorder $recorder): JsonResponse
    {
        $actorId = $request->user()->getKey();
        $note = DB::transaction(function () use ($request, $ticket, $recorder, $actorId): TicketActivity {
            $recorder->record($ticket->getKey(), TicketActivityEvent::NoteAdded, [
                'user_id' => $actorId,
                'meta' => ['note' => $request->validated('body')],
            ]);

            // TicketActivity::insert() returns no ids, so the row is re-read.
            // Safe inside this transaction: route model binding already opened
            // the read view, so a note committed by another request after that
            // point is invisible here and the highest id is our own row. If this
            // ever proves wrong the fix is a return value on
            // ActivityRecorder::record() -- a change to that file that needs
            // its own decision, not a wider select here.
            return $ticket->activities()->with('user')->orderByDesc('id')->firstOrFail();
        });

        return TicketActivityResource::make($note)->response()->setStatusCode(201);
    }
}
