<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Category;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', Category::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($color = $this->input('color'))) {
            $this->merge(['color' => strtoupper($color)]);
        }
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255', 'unique:categories,name', $this->slugRule()], 'slug' => ['prohibited'], 'description' => ['sometimes', 'nullable', 'string', 'max:1000'], 'color' => ['sometimes', 'string', 'regex:/^#[0-9A-F]{6}$/'], 'is_active' => ['sometimes', 'boolean'], 'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535']];
    }

    protected function slugRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $slug = Str::slug((string) $value);
            if ($slug === '') {
                $fail('The name must contain at least one letter or number that can be used in a URL.');

                return;
            } if (Category::withTrashed()->where('slug', $slug)->exists()) {
                $fail("Another category already uses the identifier \"{$slug}\".");
            }
        };
    }
}
