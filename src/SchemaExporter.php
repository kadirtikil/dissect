<?php

namespace KdrDev\Dissect;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelInspector;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use KdrDev\Dissect\Architecture\ContextResolver;
use KdrDev\Dissect\Support\ClassFile;
use KdrDev\Dissect\Support\Path;
use ReflectionClass;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * Builds the model graph.
 *
 * Column and relation discovery is delegated to Eloquent's own ModelInspector
 * (the class behind `artisan model:show`), which is why this package requires
 * Laravel 11.33+ — the inspector does not exist before that release.
 *
 * ModelInspector is public API, but the ModelInfo it returns is marked internal
 * by the framework, so it is consumed via toArray() and normalised immediately:
 * if Laravel reshapes it, only the two normalise* methods here need to change.
 */
class SchemaExporter
{
    /**
     * @param  array<int, string>  $modelsPaths  Directories holding models, relative to
     *                                           the application or absolute. A `*` stands
     *                                           for one directory name, so one entry can
     *                                           cover every domain's Models folder.
     */
    public function __construct(
        protected ModelInspector $inspector,
        protected ColumnNormalizer $columns,
        protected MigrationState $migrations,
        protected array $modelsPaths = ['app/Models'],
        protected ?ContextResolver $contexts = null,
    ) {}

    /**
     * @return array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>, generated_at: string}
     */
    public function export(): array
    {
        $nodes = [];
        $edges = [];

        foreach ($this->discoverModels() as $class) {
            // The fully qualified class name: the one name two models cannot
            // share. `Billing\Invoice` and `Sales\Invoice` used to collapse
            // into one `Invoice` node. The page shortens it for display.
            $id = ltrim($class, '\\');

            try {
                // ModelInspector::inspect() returns a plain array on Laravel
                // ≤12 and a ModelInfo object (the @internal value) on 13+.
                // Fold both to the array we normalise, or every model on 12
                // would fall into the catch below and render columnless.
                $inspected = $this->inspector->inspect($class);
                $info = is_array($inspected) ? $inspected : $inspected->toArray();
            } catch (Throwable $e) {
                // A model whose table is missing, or whose connection is down,
                // must not take down the whole page — show it without columns,
                // and say so: no columns read is not the same as no columns.
                $nodes[] = [
                    'id' => $id,
                    'class' => $class,
                    'table' => $this->tableFor($class),
                    'connection' => $this->connectionFor($class),
                    'columns' => [],
                    'unreadable' => $e->getMessage(),
                    'context' => $this->contexts?->forClass($class),
                ];

                continue;
            }

            $columns = $this->columns->normalize($info['attributes'], $info['database']);

            $nodes[] = [
                'id' => $id,
                'class' => $class,
                'table' => $info['table'],
                // The connection the model names, or null for the default:
                // what the code says, not which database this machine has.
                'connection' => $this->connectionFor($class),
                // What the columns' native types are written in, so the same
                // model read on MySQL here and on SQLite in CI is known to be
                // the same model read twice, not one whose every type moved.
                'driver' => $this->columns->driver($info['database']),
                // The connection is passed through so columns are read with the
                // normalizer for that model's driver, not the default one.
                'columns' => $columns,
                // The domain the model belongs to, or null. Named `context`
                // on every payload because routes already use `domain` for
                // the host.
                'context' => $this->contexts?->forClass($class),
            ];

            // A table that exists has a column. None read means it is not
            // there, on this database at least: not migrated yet, or a CI
            // run that never migrates.
            if (array_filter($columns, fn (array $column) => ! $column['virtual']) === []) {
                $nodes[array_key_last($nodes)]['unreadable'] = "The table {$info['table']} is not in the database.";
            }

            foreach ($this->normaliseRelations($class, $info['relations']) as $relation) {
                $edges[] = $relation + ['source' => $id];
            }
        }

        // Stable ordering keeps the payload (and the node grid derived from it)
        // from reshuffling between requests.
        usort($nodes, fn ($a, $b) => strcmp($a['id'], $b['id']));
        usort($edges, fn ($a, $b) => [$a['source'], $a['name'], $a['target']]
            <=> [$b['source'], $b['name'], $b['target']]);

        return [
            'nodes' => $nodes,
            'edges' => $edges,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Cheap change signal, used both as a cache key and by the client to poll
     * for changes without rebuilding the whole graph.
     *
     * The graph has two independent sources, so the signal covers both:
     *
     *  - **Relations** come from the model files — the newest mtime across the
     *    model directory catches an edited or added relation method.
     *  - **Columns** come from the live database — {@see MigrationState} moves
     *    only once a migration has actually run, so writing one changes
     *    nothing until `migrate` succeeds.
     *
     * Both are file stats and two indexed queries; nothing here inspects a
     * model or reads a table definition.
     */
    public function fingerprint(): string
    {
        $latest = 0;
        $count = 0;

        foreach ($this->modelFiles() as $file) {
            $latest = max($latest, $file->getMTime());
            $count++;
        }

        return substr(sha1($latest.':'.$count.':'.$this->migrations->signal()), 0, 16);
    }

    /**
     * Every model under the configured directories.
     *
     * The class name is read from the file's own declaration rather than
     * worked out from where it sits. With several directories — one per
     * domain — there is no single namespace to append a path to, and the file
     * already says what it is.
     *
     * @return array<int, class-string<Model>>
     */
    public function discoverModels(): array
    {
        $models = [];

        foreach ($this->modelFiles() as $file) {
            $class = ClassFile::classIn($file->getPathname());

            // Overlapping directories (`app/Models` and `app/Models/Billing`)
            // find the same file twice; it is still one model.
            if ($class !== null && ! isset($models[$class]) && $this->isModel($class)) {
                $models[$class] = true;
            }
        }

        return array_keys($models);
    }

    /**
     * Concrete, loadable, and an Eloquent model.
     *
     * Loading is the step that can throw: class_exists() includes the file, so
     * a half-saved model raises a ParseError and one extending a class that is
     * gone raises an Error. Either costs that model its node, never the graph —
     * an editor autosave mid-keystroke is a normal state for this tool.
     */
    protected function isModel(string $class): bool
    {
        try {
            if (! class_exists($class)) {
                return false;
            }

            $reflection = new ReflectionClass($class);
        } catch (Throwable) {
            return false;
        }

        return ! $reflection->isAbstract() && $reflection->isSubclassOf(Model::class);
    }

    /**
     * The table behind each discovered model.
     *
     * Deliberately much cheaper than {@see export()}: no inspection, no schema
     * query, just the name every model can report about itself. It exists so
     * the route exporter can turn an `exists:authors,id` rule into the node id
     * `Author` without paying for the whole graph to find out.
     *
     * @return array<class-string<Model>, string>
     */
    public function tables(): array
    {
        $tables = [];

        foreach ($this->discoverModels() as $class) {
            $tables[$class] = $this->tableFor($class);
        }

        return $tables;
    }

    /** @return iterable<SplFileInfo> */
    protected function modelFiles(): iterable
    {
        $directories = $this->modelsDirectories();

        if ($directories === []) {
            return [];
        }

        return (new Finder)->in($directories)->files()->name('*.php');
    }

    /**
     * The configured directories that exist, with any `*` expanded.
     *
     * A path that matches nothing is skipped rather than an error: a domain
     * without models yet, or a pattern written ahead of the folders it is for,
     * is an ordinary state for an application to be in.
     *
     * @return array<int, string>
     */
    protected function modelsDirectories(): array
    {
        $directories = [];

        foreach ($this->modelsPaths as $path) {
            // Absolute paths are used as-is; anything else is relative to the
            // app root, so models can live anywhere — a Domain folder, a
            // package, or the workbench app used to develop this package.
            $path = Path::isAbsolute($path)
                ? rtrim($path, DIRECTORY_SEPARATOR)
                : rtrim(base_path($path), DIRECTORY_SEPARATOR);

            $matches = str_contains($path, '*') ? (glob($path, GLOB_ONLYDIR) ?: []) : [$path];

            foreach ($matches as $directory) {
                if (is_dir($directory)) {
                    $directories[realpath($directory) ?: $directory] = true;
                }
            }
        }

        return array_keys($directories);
    }

    /** The connection a model names, or null when it uses the default. */
    protected function connectionFor(string $class): ?string
    {
        try {
            return (new $class)->getConnectionName();
        } catch (Throwable) {
            return null;
        }
    }

    protected function tableFor(string $class): string
    {
        try {
            return (new $class)->getTable();
        } catch (Throwable) {
            return Str::snake(Str::pluralStudly(class_basename($class)));
        }
    }

    /**
     * @param  iterable<int, array{name: string, type: string, related: string}>  $relations
     * @return array<int, array{target: string, type: string, name: string, keys: array{source: ?string, target: ?string, via: ?string}}>
     */
    protected function normaliseRelations(string $class, iterable $relations): array
    {
        $edges = [];

        foreach ($relations as $relation) {
            $edges[] = [
                'target' => ltrim($relation['related'], '\\'),
                'type' => $relation['type'],
                'name' => $relation['name'],
                'keys' => $this->relationKeys($class, $relation['name']),
            ];
        }

        return $edges;
    }

    /**
     * Which column on each end of a relation ties the two together.
     *
     * `source` is a column of the model that declares the relation, `target` a
     * column of the related model, so an edge can run from `posts.author_id`
     * to `authors.id` instead of only from Post to Author. Where the link runs
     * through a third table, `via` names it: the pivot of a many-to-many, or
     * the middle model's table of a has-through.
     *
     * The inspector only reports the related class, so the relation method is
     * called again here for its keys. It already ran once, inside the
     * inspector; a model whose relation throws the second time loses its keys,
     * not its edge.
     *
     * @return array{source: ?string, target: ?string, via: ?string}
     */
    protected function relationKeys(string $class, string $method): array
    {
        $keys = ['source' => null, 'target' => null, 'via' => null];

        try {
            $relation = (new $class)->{$method}();
        } catch (Throwable) {
            return $keys;
        }

        if (! $relation instanceof Relation) {
            return $keys;
        }

        return match (true) {
            // Before BelongsTo, which it extends: the owner is a different
            // model on every row, so only this end can be named.
            $relation instanceof MorphTo => [
                'source' => $this->column($relation->getForeignKeyName()),
            ] + $keys,
            $relation instanceof BelongsTo => [
                'source' => $this->column($relation->getForeignKeyName()),
                'target' => $this->column($relation->getOwnerKeyName()),
            ] + $keys,
            // Covers MorphOne and MorphMany: the morph's `_id` column is the
            // foreign key, its `_type` partner says which model owns the row.
            $relation instanceof HasOneOrMany => [
                'source' => $this->column($relation->getLocalKeyName()),
                'target' => $this->column($relation->getForeignKeyName()),
            ] + $keys,
            // Covers MorphToMany and MorphedByMany.
            $relation instanceof BelongsToMany => [
                'source' => $this->column($relation->getParentKeyName()),
                'target' => $this->column($relation->getRelatedKeyName()),
                'via' => $relation->getTable(),
            ],
            $relation instanceof HasOneOrManyThrough => [
                'source' => $this->column($relation->getLocalKeyName()),
                'target' => $this->column($relation->getForeignKeyName()),
                // The through model's table is only exposed qualified.
                'via' => Str::beforeLast($relation->getQualifiedFirstKeyName(), '.') ?: null,
            ],
            default => $keys,
        };
    }

    /** A bare column name; some getters hand back `table.column`. */
    protected function column(?string $name): ?string
    {
        return $name === null || $name === '' ? null : Str::afterLast($name, '.');
    }
}
