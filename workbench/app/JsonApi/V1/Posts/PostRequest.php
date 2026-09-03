<?php

namespace Workbench\App\JsonApi\V1\Posts;

use LaravelJsonApi\Laravel\Http\Requests\ResourceRequest;
use LaravelJsonApi\Validation\Rule as JsonApiRule;

/**
 * The validation half of the fixture resource.
 *
 * Rules are keyed by field name — `title`, `author` — not by where the value
 * sits in the document, which is the mapping the exporter has to do.
 */
class PostRequest extends ResourceRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string'],
            'featured' => ['boolean'],
            'publishedAt' => ['nullable', 'date'],
            'author' => JsonApiRule::toOne(),
        ];
    }
}
