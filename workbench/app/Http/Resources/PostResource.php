<?php

namespace Workbench\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Every shape the response analyzer has to classify, in one file:
 *
 *  - plain attribute access, which resolves to a column on the mixed-in model
 *  - a nested single resource
 *  - a nested collection
 *  - a conditional key
 *  - a computed value with no column behind it
 *
 * @mixin \Workbench\App\Models\Post
 */
class PostResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'is_featured' => $this->is_featured,
            'published_at' => $this->published_at,

            // Computed: no column to point at, and the analyzer should say so
            // rather than inventing one.
            'excerpt' => str($this->body ?? '')->limit(120)->toString(),

            'author' => new AuthorResource($this->whenLoaded('author')),
            'comments' => CommentResource::collection($this->whenLoaded('comments')),

            // Present only sometimes — worth marking, since a consumer reading
            // this as a contract would otherwise assume the key is always there.
            'meta' => $this->when($this->is_featured, $this->meta),
        ];
    }
}
