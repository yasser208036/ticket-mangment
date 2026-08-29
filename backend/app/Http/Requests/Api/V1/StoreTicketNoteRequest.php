<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreTicketNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('addNote', $this->route('ticket'));
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        // A whitespace-only body never reaches `min`: TrimStrings then
        // ConvertEmptyStringsToNull (both in Laravel's default global stack)
        // turn "   " into null, which fails `required`. No custom rule needed.
        return ['body' => ['required', 'string', 'min:3', 'max:5000']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'body.required' => 'Write something before saving the note.',
            'body.min' => 'A note needs at least 3 characters.',
            'body.max' => 'A note is capped at 5000 characters. Put longer detail in the ticket description instead.',
        ];
    }
}
