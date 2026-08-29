<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateTicketRequest extends FormRequest
{
    public const EDITABLE = ['subject', 'description', 'category_id', 'priority_id'];

    public function authorize(): bool
    {
        return Gate::allows('update', $this->route('ticket'));
    }

    public function rules(): array
    {
        return [
            'subject' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'required', 'string', 'max:16000'],
            'category_id' => ['sometimes', 'required', 'integer', Rule::exists('categories', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'priority_id' => ['sometimes', 'required', 'integer', Rule::exists('priorities', 'id')],
            'status_id' => ['prohibited'], 'assigned_to' => ['prohibited'], 'requester_id' => ['prohibited'],
            'reference' => ['prohibited'], 'created_by' => ['prohibited'], 'created_at' => ['prohibited'], 'updated_at' => ['prohibited'], 'deleted_at' => ['prohibited'],
            'escalation_level' => ['prohibited'], 'escalated_at' => ['prohibited'], 'escalated_by' => ['prohibited'], 'escalation_reason' => ['prohibited'],
            'first_responded_at' => ['prohibited'], 'resolved_at' => ['prohibited'], 'closed_at' => ['prohibited'],
        ];
    }
}
