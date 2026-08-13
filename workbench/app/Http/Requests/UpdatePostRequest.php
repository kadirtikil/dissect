<?php

namespace Workbench\App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The awkward — and very common — case: `rules()` reaches for the resolved
 * route to build a unique-ignoring rule, so constructing this outside a request
 * cycle throws.
 *
 * The analyzer must fall back to reading the returned array literal instead of
 * giving up, and say so by reporting `confidence: inferred`.
 */
class UpdatePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $post = $this->route('post');

        return [
            'title' => ['sometimes', 'string', 'max:255', Rule::unique('posts')->ignore($post->id)],
            'body' => 'nullable|string',
            'is_featured' => 'boolean',
        ];
    }
}
