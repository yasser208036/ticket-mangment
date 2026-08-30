<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketAssignmentRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'note' => $this->note,
            'decision_note' => $this->decision_note,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'ticket' => $this->whenLoaded('ticket', fn () => TicketResource::make($this->ticket)->resolve()),
            'requester' => $this->whenLoaded('requester', fn () => ['id' => $this->requester->id, 'name' => $this->requester->name]),
            'decided_by' => $this->whenLoaded('decidedBy', fn () => $this->decidedBy === null ? null : ['id' => $this->decidedBy->id, 'name' => $this->decidedBy->name]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
