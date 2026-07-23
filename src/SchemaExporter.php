<?php

namespace KdrDev\Dissect;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelInspector;
use Illuminate\Support\Str;
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
    public function __construct(
        protected ModelInspector $inspector,
        protected ColumnNormalizer $columns,
        protected MigrationState $migrations,
        protected string $modelsPath = 'app/Models',
        protected ?string $modelsNamespace = null,
    ) {}

    /**
     * @return array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>, generated_at: string}
     */
    public function export(): array
    {
        $nodes = [];
        $edges = [];

        foreach ($this->discoverModels() as $class) {
            $id = class_basename($class);

            try {
                // ModelInspector::inspect() returns a plain array on Laravel
                // ≤12 and a ModelInfo object (the @internal value) on 13+.
                // Fold both to the array we normalise, or every model on 12
                // would fall into the catch below and render columnless.
                $inspected = $this->inspector->inspect($class);
                $info = is_array($inspected) ? $inspected : $inspected->toArray();
            } catch (Throwable) {
                // A model whose table is missing, or whose connection is down,
                // must not take down the whole page — show it without columns.
                $nodes[] = [
                    'id' => $id,
                    'class' => $class,
                    'table' => $this->tableFor($class),
                    'columns' => [],
                ];

                continue;
            }

            $nodes[] = [
                'id' => $id,
                'class' => $class,
                'table' => $info['table'],
                'connection' => $info['database'],
                // The connection is passed through so columns are read with the
                // normalizer for that model's driver, not the default one.
                'columns' => $this->columns->normalize($info['attributes'], $info['database']),
            ];

            foreach ($this->normaliseRelations($info['relations']) as $relation) {
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

    /** @return array<int, class-string<Model>> */
    public function discoverModels(): array
    {
        $directory = $this->modelsDirectory();
        $namespace = $this->modelsNamespace();
        $models = [];

        foreach ($this->modelFiles() as $file) {
            // Resolved relative to the configured directory rather than
            // app_path(), so models can live anywhere — a Domain folder, a
            // package, or the workbench app used to develop this package.
            $class = $namespace.Str::of($file->getPathname())
                ->after($directory.DIRECTORY_SEPARATOR)
                ->replace([DIRECTORY_SEPARATOR, '.php'], ['\\', '']);

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }

            $models[] = $class;
        }

        return $models;
    }

    /** @return iterable<SplFileInfo> */
    protected function modelFiles(): iterable
    {
        $directory = $this->modelsDirectory();

        if (! is_dir($directory)) {
            return [];
        }

        return (new Finder)->in($directory)->files()->name('*.php');
    }

    /** Absolute paths are used as-is; anything else is relative to the app root. */
    protected function modelsDirectory(): string
    {
        return str_starts_with($this->modelsPath, DIRECTORY_SEPARATOR)
            ? rtrim($this->modelsPath, DIRECTORY_SEPARATOR)
            : rtrim(base_path($this->modelsPath), DIRECTORY_SEPARATOR);
    }

    /**
     * Namespace the discovered files live under.
     *
     * Defaults to the convention: `app/Models` under the application namespace
     * gives `App\Models\`. Anything unconventional (or outside app/) has to say
     * so explicitly, since there is no reliable way to infer it.
     */
    protected function modelsNamespace(): string
    {
        if ($this->modelsNamespace !== null) {
            return rtrim($this->modelsNamespace, '\\').'\\';
        }

        $relative = trim(str_replace(app_path(), '', $this->modelsDirectory()), DIRECTORY_SEPARATOR);

        return app()->getNamespace().str_replace(DIRECTORY_SEPARATOR, '\\', $relative).'\\';
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
     * @return array<int, array{target: string, type: string, name: string}>
     */
    protected function normaliseRelations(iterable $relations): array
    {
        $edges = [];

        foreach ($relations as $relation) {
            $edges[] = [
                'target' => class_basename($relation['related']),
                'type' => $relation['type'],
                'name' => $relation['name'],
            ];
        }

        return $edges;
    }
}
