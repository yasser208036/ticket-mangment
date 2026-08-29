<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TicketActivityEvent;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\TicketActivityResource;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class TicketActivityController extends Controller
{
    public function __invoke(Request $request, Ticket $ticket): AnonymousResourceCollection
    {
        $this->authorize('view', $ticket);
        $filters = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            // max: is derived, not a literal -- a story that adds an event case
            // widens the filter without editing this file.
            'events' => ['sometimes', 'array', 'max:'.count(TicketActivityEvent::values())],
            'events.*' => ['string', Rule::in(TicketActivityEvent::values())],
        ], [
            'events.*.in' => 'Unknown activity event type: :input.',
        ]);
        $events = array_values(array_unique($filters['events'] ?? []));
        if (isset($filters['events'])) {
            // withQueryString() below otherwise echoes the raw, undeduped
            // query into the pagination links even though the filter above
            // is deduped — the data matches either way, but the link a
            // client is handed should reflect the filter that was applied.
            $request->query->set('events', $events);
        }

        // created_at DESC alone is not deterministic: recordMany() stamps one
        // now() across a whole batch (ActivityRecorder.php:29), and the column
        // is second-resolution, so ties are normal. id DESC breaks them and
        // costs nothing -- the PK rides in the secondary index leaf.
        $activities = $ticket->activities()
            ->with('user')
            ->when($events !== [], fn ($query) => $query->whereIn('event', $events))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return TicketActivityResource::collection($activities)
            ->additional(['meta' => ['event_counts' => $this->eventCounts($ticket)]]);
    }

    /**
     * Every event type present on this ticket, with its count.
     *
     * Deliberately NOT filtered by the active selection: the facet drives the
     * filter's option list, so narrowing it to the active selection would
     * collapse the options to the one already chosen and leave no way back.
     *
     * @return array<string, int>
     */
    private function eventCounts(Ticket $ticket): array
    {
        return $ticket->activities()
            // 'aggregate', not 'event': Builder::pluck() casts the *value*
            // column when the model casts it, and 'event' casts to
            // TicketActivityEvent. The key column is never cast, so
            // event-as-key stays a plain string.
            ->selectRaw('event, COUNT(*) as aggregate')
            ->groupBy('event')
            ->orderBy('event')
            ->pluck('aggregate', 'event')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }
}
