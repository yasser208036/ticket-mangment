<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $meta = $this->meta ?? [];

        return [
            'id' => $this->id,
            'event' => $this->event->value,
            'field' => $this->field,
            'old_value' => $this->old_value,
            'new_value' => $this->new_value,
            // ticket_activities stores stringified foreign keys; the readable
            // names live under meta.from_name / meta.to_name, a convention set
            // by CategoryController::reassignTickets() and copied by assign
            // and status-change writers. Normalised here so the SPA never has
            // to know it.
            'from_label' => $meta['from_name'] ?? $this->old_value,
            'to_label' => $meta['to_name'] ?? $this->new_value,
            'meta' => $meta,
            // null means the system acted -- a scheduled command or a cascade.
            // See TicketActivity::user()'s docblock. Never substitute a name.
            'actor' => $this->whenLoaded('user', fn () => ['id' => $this->user->id, 'name' => $this->user->name]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
