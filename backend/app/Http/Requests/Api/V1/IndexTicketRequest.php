<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class IndexTicketRequest extends FormRequest
{
    public const SORTS = ['created_at' => 'created_at', 'updated_at' => 'updated_at', 'priority' => 'level', 'relevance' => 'relevance', 'escalated_at' => 'escalated_at'];

    public function authorize(): bool
    {
        return Gate::allows('viewAny', Ticket::class);
    }

    protected function prepareForValidation(): void
    {
        foreach (['status_id', 'priority_id', 'category_id'] as $key) {
            if ($this->has($key) && ! is_array($this->input($key))) {
                $this->merge([$key => Arr::wrap($this->input($key))]);
            }
        }
        if (is_string($q = $this->input('q'))) {
            $this->merge(['q' => trim($q)]);
        }
    }

    public function rules(): array
    {
        return [
            'status_id' => ['sometimes', 'array', 'max:20'],
            'status_id.*' => ['integer', Rule::exists('statuses', 'id')],
            'priority_id' => ['sometimes', 'array', 'max:20'],
            'priority_id.*' => ['integer', Rule::exists('priorities', 'id')],
            'category_id' => ['sometimes', 'array', 'max:50'],
            'category_id.*' => ['integer', Rule::exists('categories', 'id')->whereNull('deleted_at')],
            'assigned_to' => ['sometimes', 'string', 'regex:/^(me|unassigned|[1-9]\d*)$/'],
            'escalated' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', Rule::in(array_keys(self::SORTS))],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'q' => ['sometimes', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('sort') === 'relevance' && ! filled($this->input('q'))) {
                $validator->errors()->add('sort', 'Sorting by relevance requires a search term.');
            }
        });
    }

    public function messages(): array
    {
        return ['sort.in' => 'Tickets can only be sorted by created_at, updated_at, priority, relevance or escalated_at.', 'direction.in' => 'Sort direction must be asc or desc.', 'assigned_to.regex' => 'Assignee must be me, unassigned or a user id.', 'q.max' => 'A search term cannot exceed 255 characters.'];
    }

    public function assigneeFilter(): string|int|null
    {
        $assignee = $this->input('assigned_to');
        if ($assignee === null || $assignee === '') {
            return null;
        }
        if ($assignee === 'unassigned') {
            return 'unassigned';
        }

        return $assignee === 'me' ? (int) $this->user()->getKey() : (int) $assignee;
    }
}
