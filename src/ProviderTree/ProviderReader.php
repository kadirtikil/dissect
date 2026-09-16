<?php

namespace KdrDev\Dissect\ProviderTree;

use Illuminate\Support\ServiceProvider;
use KdrDev\Dissect\Jobs\ProjectPath;
use KdrDev\Dissect\Support\ClassFile;
use ReflectionClass;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * Reads the service providers an application declares.
 *
 * The directory is configurable rather than hardcoded, for the same reason the
 * model and job scans are: `app/Providers` is the conventional home, not the
 * only one, and a package that only finds providers in the conventional place
 * reports an empty list for anybody who moved them.
 *
 * Which makes the directory a hint and not the filter. What comes back is every
 * class in those directories that is actually a registrable provider — a helper
 * somebody keeps beside their providers is not one, and neither is the abstract
 * base three of them extend. The filter is the type, the same way the job scan
 * filters on {@see \Illuminate\Contracts\Queue\ShouldQueue} rather than on
 * living in `app/Jobs`.
 *
 * Providers the *framework* and installed packages register are deliberately
 * not here. `bootstrap/providers.php` and the loaded-provider list run into the
 * hundreds in a real application, nearly all of it vendor code nobody is trying
 * to read — a separate surface with its own switch, not a longer version of
 * this one.
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
     * Every provider found, as class name to the file it was declared in.
     *
     * Keyed by class because that is what everything downstream addresses a
     * provider by — the inspector reflects it, the payload keys on it, and the
     * page selects one by it. The path rides along because a surface that names
     * a class should be able to say which file to open.
     *
     * @return array<class-string, string>
     */
    public function read(): array
    {
        $providers = [];

        foreach ($this->files() as $file) {
            $class = ClassFile::classIn($file->getPathname());

            if ($class === null || ! $this->isProvider($class)) {
                continue;
            }

            // Configured paths are allowed to overlap — `app` and
            // `app/Providers` both being listed is a reasonable thing to write,
            // and should not report every provider twice.
            $providers[$class] = ProjectPath::relative($file->getPathname());
        }

        ksort($providers);

        return $providers;
    }

    /**
     * Concrete, loadable, and a service provider.
     *
     * An abstract base provider is real code somebody wrote, but it can never be
     * registered, so listing it would put a provider on the surface that does
     * not exist at runtime.
     */
    protected function isProvider(string $class): bool
    {
        // class_exists() first, so a file whose class cannot be autoloaded is
        // skipped rather than fatal — the same guard the job scan uses.
        if (! class_exists($class)) {
            return false;
        }

        try {
            $reflection = new ReflectionClass($class);
        } catch (Throwable) {
            return false;
        }

        return ! $reflection->isAbstract()
            && $reflection->isSubclassOf(ServiceProvider::class);
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
