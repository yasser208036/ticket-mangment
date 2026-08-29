<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategoryRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($color = $this->input('color'))) {
            $this->merge(['color' => strtoupper($color)]);
        }
    }

    public function rules(): array
    {
        $category = $this->route('category');

        return ['name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('categories', 'name')->ignore($category)], 'slug' => ['prohibited'], 'description' => ['sometimes', 'nullable', 'string', 'max:1000'], 'color' => ['sometimes', 'required', 'string', 'regex:/^#[0-9A-F]{6}$/'], 'is_active' => ['sometimes', 'required', 'boolean'], 'sort_order' => ['sometimes', 'required', 'integer', 'min:0', 'max:65535']];
    }
}
