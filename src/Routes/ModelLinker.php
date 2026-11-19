<?php

namespace KdrDev\Dissect\Routes;

use KdrDev\Dissect\SchemaExporter;
use Throwable;

/**
 * Turns a class name into a node id the graph can address.
 *
 * Node ids are fully qualified class names. Used by the jobs surface, for a
 * queued class's payload type hints: `App\Models\Author` is the node itself,
 * and a bare `Author` lands on it too when exactly one model has that name.
 *
 * The routes surface deliberately does not use this. An endpoint is described
 * by its own contract, and a rule's `exists:authors,id` travels verbatim rather
 * than resolving to a node — see {@see RouteExporter}.
 *
 * A name that resolves to nothing is left as nothing. Inventing a node the
 * graph does not have would produce a link that goes nowhere, which is worse
 * than no link at all.
 */
class ModelLinker
{
    /** @var array<string, string>|null table name => node id */
    protected ?array $byTable = null;

    /** @var array<string, string>|null fully qualified class => node id */
    protected ?array $byClass = null;

    /** @var array<string, array<int, string>>|null short class name => node ids */
    protected ?array $byBasename = null;

    public function __construct(protected SchemaExporter $models) {}

    /** `authors` => `App\Models\Author` */
    public function forTable(?string $table): ?string
    {
        if ($table === null) {
            return null;
        }

        $this->load();

        return $this->byTable[$table] ?? null;
    }

    /**
     * `App\Models\Author` => `App\Models\Author`, and a bare `Author` too.
     *
     * A namespaced name must match exactly. It used to fall back to its short
     * name, which linked a `Billing\Data\Invoice` DTO to the `Invoice` model.
     *
     * A bare name is a convention-derived guess ("AuthorResource describes an
     * Author"). It answers only when exactly one model has that name: with two
     * `Invoice` models there is no telling which was meant.
     */
    public function forClass(?string $class): ?string
    {
        if ($class === null || $class === '') {
            return null;
        }

        $this->load();

        $class = ltrim($class, '\\');

        if (isset($this->byClass[$class])) {
            return $this->byClass[$class];
        }

        if (str_contains($class, '\\')) {
            return null;
        }

        $candidates = $this->byBasename[$class] ?? [];

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /** Whether the graph holds a node by this id. */
    public function knows(string $id): bool
    {
        $this->load();

        return in_array($id, $this->byClass ?? [], true);
    }

    protected function load(): void
    {
        if ($this->byTable !== null) {
            return;
        }

        $this->byTable = [];
        $this->byClass = [];
        $this->byBasename = [];

        try {
            foreach ($this->models->tables() as $class => $table) {
                $id = ltrim($class, '\\');

                $this->byClass[$id] = $id;
                $this->byBasename[class_basename($id)][] = $id;

                // Two models can share a table (single-table inheritance, or a
                // read-only variant). First one wins rather than last, so the
                // answer does not depend on directory iteration order.
                $this->byTable[$table] ??= $id;
            }
        } catch (Throwable) {
            // Discovery reads the filesystem and instantiates models; neither is
            // guaranteed on a half-configured application. An endpoint list with
            // no model links still describes the endpoints.
        }
    }
}
