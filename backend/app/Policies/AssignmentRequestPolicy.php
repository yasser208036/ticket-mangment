<?php

namespace App\Policies;

use App\Models\TicketAssignmentRequest;
use App\Models\User;

class AssignmentRequestPolicy
{
    /** Only an admin reviews the queue. */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function decide(User $user, TicketAssignmentRequest $request): bool
    {
        return $user->isAdmin();
    }
}
