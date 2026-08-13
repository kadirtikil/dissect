<?php

namespace KdrDev\Dissect;

use KdrDev\Dissect\Concerns\VersionedStateFile;
use KdrDev\Dissect\Exceptions\StateFileException;

/**
 * Reads and writes the saved views — named subsets of the graph.
 *
 * Same bargain as LayoutRepository: plain JSON in the application rather than
 * storage/ or vendor/, so "the billing models" is something a team commits,
 * reviews and shares instead of each developer re-selecting it.
 *
 * A view is membership only. Where a model sits is layout.json's business, and
 * keeping one position per model means switching views never moves anything —
 * you see the same board with fewer models on it.
 */
class ViewRepository
{
    use VersionedStateFile;

    /** Format of the file this version reads and writes. See VersionedStateFile. */
    public const VERSION = 1;

    /** Bounds on what will be accepted, so the file cannot be grown without limit. */
    protected const MAX_VIEWS = 100;

    protected const MAX_MODELS_PER_VIEW = 500;

    protected const MAX_NAME_LENGTH = 64;

    public function __construct(protected string $path) {}

    /** @return array{version: int, views: array<int, array{id: string, name: string, models: array<int, string>}>} */
    public function get(): array
    {
        // A missing or corrupt views file must never blank the page — the graph
        // is perfectly usable with no views at all.
        $decoded = $this->decode();

        return [
            'version' => self::VERSION,
            'views' => $this->sanitise($decoded['views'] ?? []),
        ];
    }

    /**
     * @param  array<mixed>  $views
     * @return int Number of views written.
     *
     * @throws StateFileException when the existing file must not be overwritten.
     */
    public function put(array $views): int
    {
        $this->guardWrite();

        $clean = $this->sanitise($views);

        $this->writeAtomically(['version' => self::VERSION, 'views' => $clean]);

        return count($clean);
    }

    /**
     * Everything the page consumes is validated here, so neither a hand-edited
     * file nor a malformed request can put junk into the payload.
     *
     * @return array<int, array{id: string, name: string, models: array<int, string>}>
     */
    protected function sanitise(mixed $views): array
    {
        if (! is_array($views)) {
            return [];
        }

        $clean = [];
        $seen = [];

        foreach ($views as $view) {
            if (! is_array($view)) {
                continue;
            }

            $name = $this->cleanName($view['name'] ?? null);
            $id = $this->cleanId($view['id'] ?? null) ?: $this->cleanId($name);
            $models = $this->cleanModels($view['models'] ?? null);

            // A view with no name, no id or nothing in it cannot be selected
            // or displayed, so it is not worth persisting.
            if ($name === '' || $id === null || $models === []) {
                continue;
            }

            // Ids address a view; a duplicate would make the second one
            // unreachable. First definition wins.
            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $clean[] = ['id' => $id, 'name' => $name, 'models' => $models];

            if (count($clean) >= self::MAX_VIEWS) {
                break;
            }
        }

        return $clean;
    }

    protected function cleanName(mixed $name): string
    {
        if (! is_string($name)) {
            return '';
        }

        // Control characters would corrupt the menu rendering them.
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name));

        return mb_substr($name, 0, self::MAX_NAME_LENGTH);
    }

    /** Slug form, so an id is safe to put in a URL or a localStorage key. */
    protected function cleanId(mixed $id): ?string
    {
        if (! is_string($id)) {
            return null;
        }

        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $id));
        $slug = trim($slug, '-');

        return $slug === '' ? null : mb_substr($slug, 0, self::MAX_NAME_LENGTH);
    }

    /**
     * Model ids are class basenames — the graph key, as exported by
     * SchemaExporter. Anything that could not be one is dropped rather than
     * silently rendering a member that matches no node.
     *
     * @return array<int, string>
     */
    protected function cleanModels(mixed $models): array
    {
        if (! is_array($models)) {
            return [];
        }

        $clean = [];

        foreach ($models as $model) {
            if (! is_string($model) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $model)) {
                continue;
            }

            $clean[$model] = true;

            if (count($clean) >= self::MAX_MODELS_PER_VIEW) {
                break;
            }
        }

        // Keys deduplicate; the list is what the contract promises.
        return array_keys($clean);
    }
}
