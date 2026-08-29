<?php

namespace App\Http\Requests\Api\V1;

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

    protected function prepareForValidation(): void
    {
        if (is_string($email = $this->input('requester.email'))) {
            $this->merge(['requester' => [...(array) $this->input('requester'), 'email' => mb_strtolower(trim($email))]]);
        }
    }

    /** @return array<string, list<mixed>|string> */
    public function rules(): array
    {
        return [
            'requester' => ['required', 'array'],
            'requester.name' => ['required', 'string', 'max:255'],
            'requester.email' => ['required', 'string', 'email', 'max:255'],
            'requester.phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'requester.company' => ['sometimes', 'nullable', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:16000'],
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'priority_id' => ['sometimes', 'integer', Rule::exists('priorities', 'id')],
            'status_id' => ['sometimes', 'integer', Rule::exists('statuses', 'id')],
            'reference' => ['prohibited'], 'created_by' => ['prohibited'],
            'assigned_to' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return ['category_id.exists' => 'That category does not exist or is no longer active.',
            'assigned_to.prohibited' => 'Assign the ticket after creating it.'];
    }
}
