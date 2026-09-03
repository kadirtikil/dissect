<?php

namespace Workbench\App\JsonApi\V1\Authors;

use LaravelJsonApi\Eloquent\Fields\ID;
use LaravelJsonApi\Eloquent\Fields\Relations\HasMany;
use LaravelJsonApi\Eloquent\Fields\Str;
use LaravelJsonApi\Eloquent\Schema;
use Workbench\App\Models\Author;

/** A second schema, so "which schema does this route mean" is a real question. */
class AuthorSchema extends Schema
{
    public static string $model = Author::class;

    public function fields(): array
    {
        return [
            ID::make(),
            Str::make('name'),
            Str::make('email'),
            HasMany::make('posts'),
        ];
    }
}
