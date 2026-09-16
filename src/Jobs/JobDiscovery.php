<?php

namespace KdrDev\Dissect\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use KdrDev\Dissect\Support\ClassFile;
use ReflectionClass;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * Finds the classes that can end up on a queue.
 *
 * "Queueable" is not a directory, it is an interface — a job, a queued
 * listener, a mailable and a notification are four different shapes that all
 * implement {@see ShouldQueue} and all arrive at the same worker. So the scan
 * is over configured directories and the filter is the interface, rather than
 * the other way round.
 *
 * The class behind a file is read from the file's **own** `namespace`
 * declaration rather than inferred from where it sits. Model discovery infers,
 * because it scans one directory and can be told the namespace for it; this
 * scans four by default, in an application that may keep any of them somewhere
 * unconventional, and four namespace settings to get wrong is worse than
 * reading the answer that is already written at the top of every file.
 */
class JobDiscovery
{
    /** @param  array<int, string>  $paths */
    public function __construct(protected array $paths = []) {}

    /**
     * Every queueable class found, deduplicated and sorted.
     *
     * @return array<int, class-string>
     */
    public function all(): array
    {
        $classes = [];

        foreach ($this->files() as $file) {
            $class = $this->classIn($file->getPathname());

            if ($class === null || ! $this->isQueueable($class)) {
                continue;
            }

            // Configured paths are allowed to overlap — `app` and `app/Jobs`
            // both being listed is a reasonable thing to write, and should not
            // report every job twice.
            $classes[$class] = true;
        }

        $classes = array_keys($classes);
        sort($classes);

        return $classes;
    }

    /**
     * Concrete, loadable, and queueable.
     *
     * An abstract base job is real code somebody wrote, but it is not a thing
     * that can sit on a queue, and listing it would put a row on the surface
     * that no dispatch site will ever name.
     */
    protected function isQueueable(string $class): bool
    {
        // class_exists() first, so a file whose class cannot be autoloaded is
        // skipped rather than fatal — the same guard RouteCollector uses before
        // asking about a type hint.
        if (! class_exists($class)) {
            return false;
        }

        try {
            $reflection = new ReflectionClass($class);
        } catch (Throwable) {
            return false;
        }

        return ! $reflection->isAbstract()
            && $reflection->implementsInterface(ShouldQueue::class);
    }

    /** @return iterable<SplFileInfo> */
    protected function files(): iterable
    {
        $directories = array_values(array_filter(
            array_map(ProjectPath::absolute(...), $this->paths),
            is_dir(...),
        ));

        if ($directories === []) {
            return [];
        }

        return (new Finder)->in($directories)->files()->name('*.php');
    }

    /**
     * The first class declared in a file, fully qualified.
     *
     * Read from the file's own `namespace` declaration — see {@see ClassFile},
     * which the provider scan reads the same way.
     */
    protected function classIn(string $file): ?string
    {
        return ClassFile::classIn($file);
    }
}
