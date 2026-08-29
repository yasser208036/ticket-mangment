<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\V1\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function __invoke(LoginRequest $request): JsonResponse
    {
        $email = $request->string('email');
        $password = $request->string('password');
        $user = User::where('email', $email)->first();

        if (! $user) {
            Hash::make($password);
            $this->reject();
        }

        if (! Hash::check($password, $user->password) || ! $user->is_active) {
            $this->reject();
        }

        $token = $user->createToken('spa');

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'user' => UserResource::make($user)->toArray($request),
        ]);
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['email' => __('auth.failed')]);
    }
}
