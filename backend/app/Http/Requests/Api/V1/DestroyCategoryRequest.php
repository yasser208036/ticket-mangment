<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class DestroyCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('delete', $this->route('category'));
    }

    public function rules(): array
    {
        $category = $this->route('category');

        return ['reassign_to' => ['sometimes', 'integer', Rule::notIn([$category?->getKey()]), Rule::exists('categories', 'id')->where('is_active', true)->whereNull('deleted_at')]];
    }

    public function messages(): array
    {
        return ['reassign_to.not_in' => 'A category cannot be reassigned to itself.', 'reassign_to.exists' => 'That category does not exist, is deactivated, or has been deleted.'];
    }
}
