<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Status;
use App\Models\StatusTransition;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class TicketWorkflow
{
    public function allowedTransitions(Ticket $ticket, User $user): Collection
    {
        return $this->edgesFrom($ticket->status_id)->filter(fn (StatusTransition $edge) => $edge->required_role !== UserRole::Admin || $user->isAdmin())->map(fn (StatusTransition $edge) => $edge->toStatus)->sortBy('sort_order')->values();
    }

    public function assertCanTransition(Ticket $ticket, Status $target, User $user): void
    {
        if ($ticket->status_id === $target->getKey()) {
            $this->reject("This ticket is already {$target->name}.");
        }
        $edge = $this->edgesFrom($ticket->status_id)->firstWhere('to_status_id', $target->getKey());
        if ($edge === null) {
            $this->reject("A ticket cannot move from {$ticket->status->name} to {$target->name}.");
        }
        if ($edge->required_role === UserRole::Admin && ! $user->isAdmin()) {
            $this->reject("Only an administrator can move a ticket from {$ticket->status->name} to {$target->name}.");
        }
    }

    private function edgesFrom(int $statusId): Collection
    {
        return StatusTransition::query()->where('from_status_id', $statusId)->with('toStatus')->get();
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['status_id' => [$message]]);
    }
}
