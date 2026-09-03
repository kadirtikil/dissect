<?php

namespace Workbench\App\JsonApi\V1\Posts;

use LaravelJsonApi\Eloquent\Contracts\Paginator;
use LaravelJsonApi\Eloquent\Fields\Boolean;
use LaravelJsonApi\Eloquent\Fields\DateTime;
use LaravelJsonApi\Eloquent\Fields\ID;
use LaravelJsonApi\Eloquent\Fields\Relations\BelongsTo;
use LaravelJsonApi\Eloquent\Fields\Relations\HasMany;
use LaravelJsonApi\Eloquent\Fields\Str;
use LaravelJsonApi\Eloquent\Pagination\PagePagination;
use LaravelJsonApi\Eloquent\Schema;
use Workbench\App\Models\Post;

/**
 * Covers every field shape the exporter has to describe: an id, plain
 * attributes, a cast one, and both kinds of relation.
 */
class PostSchema extends Schema
{
    public static string $model = Post::class;

    public function fields(): array
    {
        return [
            ID::make(),
            Str::make('title'),
            Str::make('slug'),
            Boolean::make('featured', 'is_featured'),
            DateTime::make('publishedAt', 'published_at')->sortable(),
            BelongsTo::make('author'),
            HasMany::make('comments')->readOnly(),
        ];
    }

    public function pagination(): ?Paginator
    {
        return PagePagination::make();
    }
}
