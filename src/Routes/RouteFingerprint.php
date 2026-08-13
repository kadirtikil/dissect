<?php

namespace KdrDev\Dissect\Routes;

use SplFileInfo;
use Symfony\Component\Finder\Finder;

/**
 * "Has anything that could change the route table been edited?"
 *
 * The schema half of the graph has two precise signals — model file mtimes and
 * the applied-migration table. The route half has no equivalent: a route can
 * move because a route file changed, or a controller signature changed, or a
 * FormRequest gained a rule. All of those are source files, so the signal is
 * the same file stat over a configured set of directories.
 *
 * Deliberately coarse in one direction only. It can move when nothing the
 * viewer shows has actually changed (a re-export costs a cache miss); it must
 * never sit still when something has, because that is a stale page nobody knows
 * is stale.
 */
class RouteFingerprint
{
    /** @param  array<int, string>  $paths */
    public function __construct(protected array $paths = ['app', 'routes']) {}

    public function signal(): string
    {
        $latest = 0;
        $count = 0;

        foreach ($this->files() as $file) {
            $latest = max($latest, $file->getMTime());
            $count++;
        }

        return substr(sha1($latest.':'.$count), 0, 16);
    }

    /** @return iterable<SplFileInfo> */
    protected function files(): iterable
    {
        $directories = array_values(array_filter(
            array_map(fn (string $path) => $this->absolute($path), $this->paths),
            is_dir(...),
        ));

        if ($directories === []) {
            return [];
        }

        // One Finder over every directory: the cost is the stat calls, and
        // walking them separately would not save any.
        return (new Finder)->in($directories)->files()->name('*.php');
    }

    /** Absolute paths are used as-is; anything else is relative to the app root. */
    protected function absolute(string $path): string
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            ? rtrim($path, DIRECTORY_SEPARATOR)
            : rtrim(base_path($path), DIRECTORY_SEPARATOR);
    }
}
