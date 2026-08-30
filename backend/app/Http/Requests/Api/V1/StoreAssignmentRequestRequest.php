<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Ticket;
use App\Models\TicketAssignmentRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class StoreAssignmentRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('requestAssignment', $this->route('ticket'));
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var Ticket $ticket */
            $ticket = $this->route('ticket');
            if ($ticket->assigned_to !== null) {
                $validator->errors()->add('ticket', 'This ticket already has an assignee.');

                return;
            }
            $exists = TicketAssignmentRequest::query()->pending()
                ->where('ticket_id', $ticket->getKey())
                ->where('user_id', $this->user()->getKey())
                ->exists();
            if ($exists) {
                $validator->errors()->add('ticket', 'You have already asked for this ticket. An administrator is reviewing it.');
            }
        });
    }
}
