<?php

namespace Workbench\App\JsonApi\V1;

use LaravelJsonApi\Core\Server\Server as BaseServer;
use Workbench\App\JsonApi\V1\Authors\AuthorSchema;
use Workbench\App\JsonApi\V1\Comments\CommentSchema;
use Workbench\App\JsonApi\V1\Posts\PostSchema;

/**
 * The JSON:API half of the route fixture.
 *
 * A real server, registered the way an application registers one, so the
 * exporter is developed against the package rather than against an imitation
 * of it.
 */
class Server extends BaseServer
{
    protected string $baseUri = '/api/v1';

    /** @return array<int, class-string> */
    protected function allSchemas(): array
    {
        return [
            PostSchema::class,
            AuthorSchema::class,
            CommentSchema::class,
        ];
    }
}
