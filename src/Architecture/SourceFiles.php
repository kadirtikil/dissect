<?php

namespace KdrDev\Dissect\Architecture;

use KdrDev\Dissect\Routes\RouteFingerprint;
use KdrDev\Dissect\Support\Path;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * The application's own PHP files: the configured source folders plus every
 * domain's, walked once and the same way for everything that reads them.
 *
 * The domain list and the class index both read every file here. Two walks
 * that merely agreed today would put a class in a domain the index never saw.
 */
final class SourceFiles
{
    /** Folders no domain lives in, wherever they turn up. */
    public const SKIPPED = ['vendor', 'node_modules', 'tests', 'Tests', 'storage', 'bootstrap'];

    /**
     * @param  array<int, string>  $paths  Where classes are looked for, relative to the root
     *                                     or absolute. Domain roots are always added.
     */
    public function __construct(
        protected ContextResolver $resolver,
        protected array $paths = ['app', 'src', 'Modules', 'app-modules', 'packages'],
    ) {}

    /**
     * Every PHP file, by resolved path, sorted.
     *
     * @return array<int, string>
     */
    public function files(): array
    {
        $files = [];

        // A domain can be a single file, which no directory walk reaches.
        foreach ($this->contexts() as $context) {
            foreach ($context->roots as $root) {
                if (is_file($root)) {
                    $files[realpath($root) ?: $root] = true;
                }
            }
        }

        $directories = $this->directories();

        if ($directories !== []) {
            $finder = (new Finder)->in($directories)->files()->name('*.php')
                // Relative to the walked folder, so where the application
                // itself happens to live is never mistaken for one.
                ->filter(fn (SplFileInfo $file) => array_intersect(
                    explode('/', str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePath())),
                    self::SKIPPED,
                ) === []);

            foreach ($finder as $file) {
                $files[$file->getRealPath() ?: $file->getPathname()] = true;
            }
        }

        $files = array_keys($files);
        sort($files);

        return $files;
    }

    /** Cheap change signal: the newest file under the walked folders, and how many there are. */
    public function signal(): string
    {
        return (new RouteFingerprint($this->directories()))->signal();
    }

    /**
     * The configured folders that exist, plus every domain's, none inside another.
     *
     * @return array<int, string>
     */
    public function directories(): array
    {
        $directories = [];

        foreach ($this->paths as $path) {
            $directories[] = Path::isAbsolute($path)
                ? $path
                : rtrim($this->resolver->root(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$path;
        }

        foreach ($this->contexts() as $context) {
            array_push($directories, ...$context->roots);
        }

        $existing = [];

        foreach ($directories as $directory) {
            if (is_dir($directory)) {
                $existing[realpath($directory) ?: $directory] = true;
            }
        }

        // A domain root inside a walked folder would be walked twice.
        $directories = array_keys($existing);
        sort($directories);

        return array_values(array_filter($directories, function (string $directory) use ($directories) {
            foreach ($directories as $other) {
                if ($other !== $directory && str_starts_with($directory, $other.DIRECTORY_SEPARATOR)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /** A path as somebody would type it: relative to the root when under it. */
    public function relative(string $path): string
    {
        $root = rtrim(realpath($this->resolver->root()) ?: $this->resolver->root(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_replace(DIRECTORY_SEPARATOR, '/', str_starts_with($path, $root) ? substr($path, strlen($root)) : $path);
    }

    /** @return array<string, Context> */
    protected function contexts(): array
    {
        return $this->resolver->enabled() ? $this->resolver->contexts() : [];
    }
}
