<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AssignTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('assign', $this->route('ticket'));
    }

    public function rules(): array
    {
        return [
            'assigned_to' => ['present', 'nullable', 'integer', Rule::exists('users', 'id')->where('role', UserRole::Agent)->where('is_active', true)],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'assigned_to.present' => 'Send assigned_to explicitly. Use null to return the ticket to the queue.',
            'assigned_to.exists' => 'That user is not an active agent.',
            'reason.max' => 'Keep the reason to 500 characters or fewer.',
        ];
    }
}
