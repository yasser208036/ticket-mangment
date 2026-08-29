<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class DestroyUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('delete', $this->route('user'));
    }

    /** @return array<string, list<mixed>|string> */
    public function rules(): array
    {
        $user = $this->route('user');

        return [
            // Active only: moving a departing agent's queue onto another
            // departing agent is the one destination guaranteed to be wrong.
            // Role is deliberately unconstrained — an admin can inherit.
            'reassign_to' => ['sometimes', 'integer', Rule::notIn([$user?->getKey()]), Rule::exists('users', 'id')->where('is_active', true)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reassign_to.not_in' => 'Tickets cannot be reassigned to the account being deleted.',
            'reassign_to.exists' => 'That user does not exist or is deactivated.',
        ];
    }
}
