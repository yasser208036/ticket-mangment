<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Password;

class ResetUserPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('resetPassword', $this->route('user'));
    }

    /** @return array<string, list<mixed>|string> */
    public function rules(): array
    {
        return [
            // The ACTING admin's password, not the target's: current_password
            // validates against the authenticated user. Without this re-auth an
            // unattended admin session is a one-click takeover of every account.
            // The guard must be named — the default is `web`, which this API
            // never populates, so the rule would fail for a correct password.
            'current_password' => ['required', 'string', 'current_password:sanctum'],
            // No `confirmed`, unlike PATCH /auth/password: a typo here locks out
            // someone else and is fixed by repeating the reset. It is not the
            // irrecoverable case that rule exists for.
            'password' => ['required', 'string', Password::defaults()],
        ];
    }
}
