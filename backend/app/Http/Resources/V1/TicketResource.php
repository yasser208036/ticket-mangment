<?php

namespace App\Http\Resources\V1;

use App\Enums\TicketActivityEvent;
use App\Models\TicketActivity;
use App\Models\TicketAssignmentRequest;
use App\Services\TicketWorkflow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'reference' => $this->reference,
            'subject' => $this->subject,
            'description' => $this->when(! $request->routeIs('tickets.index'), fn () => $this->description),
            'requester' => RequesterResource::make($this->whenLoaded('requester')),
            'category' => CategoryResource::make($this->whenLoaded('category')),
            'priority' => PriorityResource::make($this->whenLoaded('priority')),
            'status' => StatusResource::make($this->whenLoaded('status')),
            'assignee' => $this->whenLoaded('assignee', fn () => ['id' => $this->assignee->id, 'name' => $this->assignee->name]),
            'creator' => $this->whenLoaded('creator', fn () => ['id' => $this->creator->id, 'name' => $this->creator->name]),
            'escalated_by' => $this->whenLoaded('escalatedBy', fn () => ['id' => $this->escalatedBy->id, 'name' => $this->escalatedBy->name]),
            'escalation_level' => $this->escalation_level,
            'escalated_at' => $this->escalated_at?->toIso8601String(),
            'first_responded_at' => $this->first_responded_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'escalation_reason' => $this->when($request->routeIs('tickets.show'), fn () => $this->escalation_reason),
            'can' => $this->when($request->routeIs('tickets.show'), fn () => ['update' => $request->user()->can('update', $this->resource), 'assign' => $request->user()->can('assign', $this->resource), 'request_assignment' => $request->user()->can('requestAssignment', $this->resource) && $this->assigned_to === null, 'change_status' => $request->user()->can('changeStatus', $this->resource), 'escalate' => $request->user()->can('escalate', $this->resource) && ! $this->status->is_terminal, 'delete' => $request->user()->can('delete', $this->resource), 'add_note' => $request->user()->can('addNote', $this->resource)]),
            'my_pending_assignment_request' => $this->when($request->routeIs('tickets.show'), fn (): bool => TicketAssignmentRequest::query()->pending()
                ->where('ticket_id', $this->id)
                ->where('user_id', $request->user()->getKey())
                ->exists()),
            'allowed_transitions' => $this->when($request->routeIs('tickets.show'), fn () => StatusResource::collection(app(TicketWorkflow::class)->allowedTransitions($this->resource, $request->user()))->resolve()),
            'resolution' => $this->when($request->routeIs('tickets.show'), fn () => $this->currentResolution()),
            // `?? 0`: loadCount() sets this attribute only on the show route --
            // a future caller rendering this resource without it must get 0,
            // not a 500 on a missing attribute. (int): MySQL can return a
            // COUNT() as a string depending on the driver.
            'reopen_count' => $this->when($request->routeIs('tickets.show'), fn (): int => (int) ($this->reopen_count ?? 0)),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /** @return array{note: string, at: string|null, by: array{id: int, name: string}|null}|null */
    private function currentResolution(): ?array
    {
        // resolved_at is the index into the trail: it is stamped by the move
        // that carried the note and cleared by any move back into an active
        // status, so a reopened ticket costs zero queries and shows no stale
        // note. latest('id') and not first() -- a ticket can be resolved more
        // than once, and the second note supersedes the first.
        if ($this->resolved_at === null) {
            return null;
        }

        $activity = TicketActivity::query()
            ->with('user')
            ->where('ticket_id', $this->id)
            ->where('event', TicketActivityEvent::StatusChanged)
            ->whereNotNull('meta->resolution')
            ->latest('id')
            ->first();

        if ($activity === null) {
            return null;
        }

        return [
            'note' => $activity->meta['resolution'],
            'at' => $this->resolved_at?->toIso8601String(),
            'by' => $activity->user === null ? null : ['id' => $activity->user->id, 'name' => $activity->user->name],
        ];
    }
}
