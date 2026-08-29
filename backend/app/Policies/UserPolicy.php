<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, User $target): bool
    {
        return $user->isAdmin() || $user->is($target);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, User $target): bool
    {
        return $user->isAdmin();
    }

    /**
     * Admin-only, and never yourself: deleting the account behind the request
     * destroys the session performing it. The "last active admin" rule needs a
     * row lock, which a policy cannot hold — it lives in UserController.
     */
    public function delete(User $user, User $target): bool
    {
        return $user->isAdmin() && ! $user->is($target);
    }

    /**
     * Setting your OWN password goes through PATCH /auth/password, which asks
     * for the current one. This ability is only ever about someone else.
     */
    public function resetPassword(User $user, User $target): bool
    {
        return $user->isAdmin() && ! $user->is($target);
    }
}
