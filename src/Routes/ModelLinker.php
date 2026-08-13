<?php

namespace KdrDev\Dissect\Routes;

use KdrDev\Dissect\SchemaExporter;
use Throwable;

/**
 * Turns the names an endpoint mentions into node ids the graph can address.
 *
 * This is the piece that makes the routes surface part of dissect rather than a
 * second `route:list`. A rule says `exists:authors,id`, a resource is called
 * `AuthorResource`, a parameter is type-hinted `Author` — three different ways
 * of naming the same node, and all three have to land on the id `schema.json`
 * uses as a key.
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

    public function __construct(protected SchemaExporter $models) {}

    /** `authors` => `Author` */
    public function forTable(?string $table): ?string
    {
        if ($table === null) {
            return null;
        }

        $this->load();

        return $this->byTable[$table] ?? null;
    }

    /**
     * `App\Models\Author` => `Author`, and a bare `Author` too.
     *
     * The basename fallback is what makes a convention-derived guess
     * ("AuthorResource describes an Author") checkable: it only answers when
     * the graph actually holds a node by that name.
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

        $basename = str_contains($class, '\\')
            ? substr($class, strrpos($class, '\\') + 1)
            : $class;

        return in_array($basename, $this->byClass ?? [], true) ? $basename : null;
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

        try {
            foreach ($this->models->tables() as $class => $table) {
                $id = class_basename($class);

                $this->byClass[ltrim($class, '\\')] = $id;

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
