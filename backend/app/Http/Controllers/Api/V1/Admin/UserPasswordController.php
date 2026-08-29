<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ResetUserPasswordRequest;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * An admin setting SOMEONE ELSE'S password. Your own goes through
 * PATCH /auth/password, which asks for the current one.
 */
class UserPasswordController extends Controller
{
    public function __invoke(ResetUserPasswordRequest $request, User $user): Response
    {
        $this->authorize('resetPassword', $user);
        DB::transaction(function () use ($request, $user): void {
            // The `hashed` cast does the hashing. A Hash::make() here as well
            // produces a hash of a hash: a 204 nobody can ever log in against.
            $user->update(['password' => $request->validated('password')]);
            // Every token, not "all but the current" the way Auth\PasswordController
            // does it — the current one belongs to the admin, not to the target.
            $user->tokens()->delete();
        });
        // Outside the transaction, so a rolled-back attempt cannot claim a reset
        // happened. Ids only: never the address, never the password.
        Log::warning('An administrator reset a user password.', [
            'actor_id' => $request->user()->getKey(),
            'user_id' => $user->getKey(),
        ]);

        return response()->noContent();
    }
}
