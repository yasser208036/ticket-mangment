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
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Ticket $ticket): bool
    {
        return true;
    }

    public function delete(User $user, Ticket $ticket): bool
    {
        return $user->isAdmin();
    }

    public function assign(User $user, Ticket $ticket): bool
    {
        return $user->isAdmin();
    }

    public function claim(User $user, Ticket $ticket): bool
    {
        return ! $user->isAdmin();
    }

    public function changeStatus(User $user, Ticket $ticket): bool
    {
        return true;
    }

    public function escalate(User $user, Ticket $ticket): bool
    {
        // A pure role gate, deliberately. The "is this ticket terminal" half
        // lives in TicketController::escalate(), because a terminal ticket
        // must return 422 and a denied policy can only ever be a 403.
        // TicketResource's `can.escalate` keeps the state half so the button
        // still hides.
        return true;
    }

    /**
     * Any active staff member may note any ticket, including a terminal one --
     * a post-mortem note on a Closed ticket is the point of the feature. This
     * deliberately does NOT mirror escalate()'s is_terminal guard: escalating a
     * closed ticket is incoherent, recording a fact about one is not.
     */
    public function addNote(User $user, Ticket $ticket): bool
    {
        return true;
    }
}
