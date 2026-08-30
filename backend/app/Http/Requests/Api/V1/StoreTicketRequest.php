<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\UserRole;
use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', Ticket::class);
    }

    /** @return array<string, list<mixed>|string> */
    public function rules(): array
    {
        return [
            'requester' => ['prohibited'],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:16000'],
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'priority_id' => ['sometimes', 'integer', Rule::exists('priorities', 'id')],
            'assigned_to' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')->where('role', UserRole::Agent)->where('is_active', true)],
            'reference' => ['prohibited'], 'created_by' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'category_id.exists' => 'That category does not exist or is no longer active.',
            'requester.prohibited' => 'The requester is your own account; do not send one.',
            'assigned_to.exists' => 'That user is not an active agent.',
        ];
    }
}
