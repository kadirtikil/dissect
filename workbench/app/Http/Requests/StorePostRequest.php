<?php

namespace Workbench\App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Workbench\App\Enums\PostVisibility;

/**
 * The straightforward case: `rules()` touches nothing outside itself, so the
 * analyzer can construct this and call it — `confidence: certain`.
 *
 * Between them the rules cover every form the normalizer has to read: a piped
 * string, an array of strings, a nested `*` path, a Rule object carrying a
 * table and column, and an enum.
 */
class StorePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'body' => ['nullable', 'string'],
            'author_id' => ['required', 'integer', Rule::exists('authors', 'id')],
            'category_id' => 'nullable|integer|exists:categories,id',
            'is_featured' => 'boolean',
            'published_at' => 'nullable|date',
            'visibility' => ['required', Rule::enum(PostVisibility::class)],
            'meta' => ['nullable', 'array'],

            // The nested case: normalises to `tags[].name` so request and
            // response field paths share one grammar.
            'tags' => ['array', 'max:10'],
            'tags.*.name' => ['required', 'string', 'max:50'],
            'tags.*.colour' => ['nullable', 'string'],
        ];
    }
}
