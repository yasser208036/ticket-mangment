<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class EscalateTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('escalate', $this->route('ticket'));
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:5000'],
            // The terminal check is NOT here: it needs the row lock to be
            // race-free, so it lives in the controller. TicketPolicy::escalate
            // is a pure role gate for the same reason.
            'priority_id' => ['prohibited'],
            'assigned_to' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why this ticket needs to be escalated.',
            'reason.min' => 'The escalation reason must be at least 10 characters.',
            'priority_id.prohibited' => 'Escalation raises the priority by one level on its own.',
            'assigned_to.prohibited' => 'Escalation routes the ticket to an admin on its own.',
        ];
    }
}
