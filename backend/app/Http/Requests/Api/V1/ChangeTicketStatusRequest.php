<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Status;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ChangeTicketStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('changeStatus', $this->route('ticket'));
    }

    public function rules(): array
    {
        $slug = Status::query()->whereKey($this->integer('status_id'))->value('slug');

        return [
            'status_id' => ['required', 'integer', Rule::exists('statuses', 'id')],
            'resolution' => $slug === Status::SLUG_RESOLVED ? ['required', 'string', 'min:10', 'max:5000'] : ['prohibited'],
            'reason' => $slug === Status::SLUG_REOPENED ? ['required', 'string', 'min:10', 'max:5000'] : ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'resolution.required' => 'Say how the ticket was resolved before resolving it.',
            'resolution.min' => 'The resolution note must be at least 10 characters.',
            'resolution.prohibited' => 'A resolution note belongs only on a move into Resolved.',
            'reason.required' => 'Say what brought this ticket back before reopening it.',
            'reason.min' => 'The reopen reason must be at least 10 characters.',
            'reason.prohibited' => 'A reason belongs only on a move into Reopened.',
        ];
    }
}
