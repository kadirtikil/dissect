<?php

namespace KdrDev\Dissect\Support;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use KdrDev\Dissect\Architecture\Boundaries;
use KdrDev\Dissect\Architecture\ClassIndex;
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
 * It reads through the same cache the page does, keyed by each surface's
 * fingerprint: an agent asking right after an edit gets a fresh build, and
 * one asking again before the next edit gets the payload already built.
 *
 * The queue is left out. It is the one surface that is runtime state rather
 * than a description of code, and it carries real jobs' data.
 */
final class Surfaces
{
    /** In the order a reader meets them: data, then how it is reached. */
    public const NAMES = ['schema', 'routes', 'jobs', 'providers', 'domains', 'classes', 'boundaries'];

    public function __construct(
        protected Container $app,
        protected PayloadCache $cache,
    ) {}

    /** @return array<string, mixed> */
    public function export(string $name): array
    {
        $exporter = match ($name) {
            'schema' => $this->app->make(SchemaExporter::class),
            'routes' => $this->app->make(RouteExporter::class),
            'jobs' => $this->app->make(JobExporter::class),
            'providers' => $this->app->make(ProviderExporter::class),
            'domains' => $this->app->make(DomainMap::class),
            'classes' => $this->app->make(ClassIndex::class),
            'boundaries' => $this->app->make(Boundaries::class),
            default => throw new InvalidArgumentException(
                "There is no surface called \"{$name}\". Pick one of: ".implode(', ', self::NAMES).'.'
            ),
        };

        return $this->cache->remember($name, $exporter->fingerprint(), fn () => $exporter->export());
    }

    public static function has(string $name): bool
    {
        return in_array($name, self::NAMES, true);
    }
}
