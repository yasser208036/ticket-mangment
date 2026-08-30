<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class DeclineAssignmentRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('decide', $this->route('assignmentRequest'));
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
