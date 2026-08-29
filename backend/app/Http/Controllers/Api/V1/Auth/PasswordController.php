<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdatePasswordRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class PasswordController extends Controller
{
    public function __invoke(UpdatePasswordRequest $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        DB::transaction(function () use ($request, $user): void {
            $user->update(['password' => $request->validated('password')]);
            $this->revokeOtherTokens($user);
        });

        return response()->noContent();
    }

    private function revokeOtherTokens(User $user): void
    {
        $current = $user->currentAccessToken();
        $user->tokens()->when(
            $current instanceof PersonalAccessToken,
            fn (Builder $query) => $query->whereKeyNot($current->getKey()),
        )->delete();
    }
}
