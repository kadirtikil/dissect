<?php

namespace KdrDev\Dissect\ProviderTree;

use KdrDev\Dissect\Jobs\ProjectPath;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

/**
 * Reads the service provider files an application declares.
 *
 * The directory is configurable rather than hardcoded, for the same reason the
 * model and job scans are: `app/Providers` is the conventional home, not the
 * only one, and a package that only finds providers in the conventional place
 * reports an empty list for anybody who moved them.
 *
 * A directory that is not there is not an error. Under Workbench base_path() is
 * the throwaway skeleton, and in a real application the folder may simply not
 * exist — neither is worth an exception on a surface that is only describing
 * what it found.
 */
final class ProviderReader
{
    /**
     * Paths default to the configured ones, so the common case is `new
     * ProviderReader` and the argument is there for a caller that already
     * knows which directory it means — a test, or a tinker session.
     *
     * @param  array<int, string>|null  $paths
     */
    public function __construct(?array $paths = null)
    {
        $this->paths = $paths ?? config('dissect.providers.paths', ['app/Providers']);
    }

    /** @var array<int, string> */
    protected array $paths;

    /**
     * Every provider file found, as paths relative to the application root.
     *
     * @return array<int, string>
     */
    public function read(): array
    {
        $paths = [];

        foreach ($this->files() as $file) {
            $paths[] = ProjectPath::relative($file->getPathname());
        }

        sort($paths);

        return $paths;
    }

    /** @return iterable<SplFileInfo> */
    protected function files(): iterable
    {
        $directories = array_values(array_filter(
            array_map(ProjectPath::absolute(...), $this->paths),
            is_dir(...),
        ));

        // Finder throws on a directory that is not there, and `in([])` throws a
        // LogicException of its own, so the empty case never reaches it.
        if ($directories === []) {
            return [];
        }

        return (new Finder)->in($directories)->files()->name('*.php');
    }
}
