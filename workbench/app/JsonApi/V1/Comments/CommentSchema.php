<?php

namespace Workbench\App\JsonApi\V1\Comments;

use LaravelJsonApi\Eloquent\Fields\ID;
use LaravelJsonApi\Eloquent\Fields\Str;
use LaravelJsonApi\Eloquent\Schema;
use Workbench\App\Models\Comment;

/**
 * The far side of a to-many relation, so `posts/{post}/comments` has a schema
 * to describe rather than falling back to the resource it was reached from.
 */
class CommentSchema extends Schema
{
    public static string $model = Comment::class;

    public function fields(): array
    {
        return [
            ID::make(),
            Str::make('body'),
        ];
    }
}
