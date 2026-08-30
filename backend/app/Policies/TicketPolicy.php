<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;

class TicketPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Ticket $ticket): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        if ($user->isAgent()) {
            return $ticket->assigned_to === $user->getKey() || $ticket->assigned_to === null;
        }

        return $ticket->created_by === $user->getKey();
    }

    /** Only end users file tickets. Staff work them. */
    public function create(User $user): bool
    {
        return $user->isEndUser();
    }

    /** Editing the ticket's own fields is staff work, and an agent's own queue only. */
    public function update(User $user, Ticket $ticket): bool
    {
        return $user->isAdmin() || ($user->isAgent() && $ticket->assigned_to === $user->getKey());
    }

    public function delete(User $user, Ticket $ticket): bool
    {
        return $user->isAdmin();
    }

    public function assign(User $user, Ticket $ticket): bool
    {
        return $user->isAdmin();
    }

    /** An agent may ask for a ticket. Whether it is still unassigned is a 422, checked in the form request. */
    public function requestAssignment(User $user, Ticket $ticket): bool
    {
        return $user->isAgent();
    }

    public function changeStatus(User $user, Ticket $ticket): bool
    {
        return $this->update($user, $ticket);
    }

    public function escalate(User $user, Ticket $ticket): bool
    {
        // A pure role gate, deliberately. The "is this ticket terminal" half
        // lives in TicketController::escalate(), because a terminal ticket
        // must return 422 and a denied policy can only ever be a 403.
        // TicketResource's `can.escalate` keeps the state half so the button
        // still hides.
        return $user->isAdmin() || ($user->isAgent() && $ticket->assigned_to === $user->getKey());
    }

    /**
     * Any active staff member may note any ticket they can see, including a
     * terminal one -- a post-mortem note on a Closed ticket is the point of
     * the feature. This deliberately does NOT mirror escalate()'s is_terminal
     * guard: escalating a closed ticket is incoherent, recording a fact about
     * one is not. An end user never notes -- the trail stays internal.
     */
    public function addNote(User $user, Ticket $ticket): bool
    {
        return ! $user->isEndUser() && $this->view($user, $ticket);
    }
}
