<?php

namespace KdrDev\Dissect\Support;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use KdrDev\Dissect\Architecture\DomainMap;
use KdrDev\Dissect\Jobs\JobExporter;
use KdrDev\Dissect\ProviderTree\ProviderExporter;
use KdrDev\Dissect\Routes\RouteExporter;
use KdrDev\Dissect\SchemaExporter;

/**
 * Every payload the page reads, by name, for anything that is not the page.
 *
 * The console and the MCP server both describe the application through this,
 * so a terminal, an agent and the browser are always shown the same thing.
 * Each call builds the payload afresh: nothing here is cached, because a
 * command runs once and an agent asks right after changing the code.
 *
 * The queue is left out. It is the one surface that is runtime state rather
 * than a description of code, and it carries real jobs' data.
 */
final class Surfaces
{
    /** In the order a reader meets them: data, then how it is reached. */
    public const NAMES = ['schema', 'routes', 'jobs', 'providers', 'domains'];

    public function __construct(protected Container $app) {}

    /** @return array<string, mixed> */
    public function export(string $name): array
    {
        return match ($name) {
            'schema' => $this->app->make(SchemaExporter::class)->export(),
            'routes' => $this->app->make(RouteExporter::class)->export(),
            'jobs' => $this->app->make(JobExporter::class)->export(),
            'providers' => $this->app->make(ProviderExporter::class)->export(),
            'domains' => $this->app->make(DomainMap::class)->export(),
            default => throw new InvalidArgumentException(
                "There is no surface called \"{$name}\". Pick one of: ".implode(', ', self::NAMES).'.'
            ),
        };
    }

    public static function has(string $name): bool
    {
        return in_array($name, self::NAMES, true);
    }
}
