<?php

namespace KdrDev\Dissect\Support;

use Composer\Autoload\ClassLoader;
use ReflectionClass;
use Throwable;

/**
 * Whose code a file is: the application's, a dependency's, or the framework's.
 *
 * "Under vendor/" was the whole test, and it is wrong for one setup that is
 * common exactly where dissect is most useful: a modular application whose
 * domains are local packages, pulled in through a Composer `path` repository.
 *
 * Composer installs those into vendor/. By default as a symlink, which was
 * already fine: PHP reports a class's real file, back in `packages/billing`.
 * But with `"symlink": false`, the usual choice in Docker where a symlink out
 * of the mount does not resolve, it is a copy, and the application's own
 * billing module was reported as somebody else's code. The provider trees
 * stopped at its boundary, and the routes facet filed it under vendor.
 *
 * So a file inside a package Composer installed from a `path` repository
 * counts as the application's, wherever the install put it. Paths are still
 * resolved before comparing, so a symlinked install and a symlinked vendor/
 * compare as the same place. Everything else is decided as before.
 */
class CodeOwnership
{
    public const APP = 'app';

    public const VENDOR = 'vendor';

    public const FRAMEWORK = 'framework';

    /** @var array<int, string>|null directories of packages installed from a path repository */
    protected ?array $localPackages = null;

    /**
     * @param  string|null  $composerDirectory  vendor/composer, where installed.json
     *                                          is. Defaults to the one whose
     *                                          autoloader is running.
     */
    public function __construct(protected ?string $composerDirectory = null) {}

    /**
     * Who owns this class, given the file it is declared in.
     *
     * The name decides only for the framework, whose namespace is the one
     * thing it reliably has; everything else is decided by the file.
     */
    public function of(?string $class, string|false|null $file): string
    {
        if ($class !== null && str_starts_with(ltrim($class, '\\'), 'Illuminate\\')) {
            return self::FRAMEWORK;
        }

        // Nothing to inspect: an eval'd or generated class, or an internal
        // one. Not something the application wrote in a file.
        if ($file === null || $file === false || $file === '') {
            return self::VENDOR;
        }

        $path = $this->normalise(realpath($file) ?: $file);

        foreach ($this->localPackages() as $package) {
            if (str_starts_with($path, $package)) {
                return self::APP;
            }
        }

        if (str_contains($path, '/vendor/laravel/framework/')) {
            return self::FRAMEWORK;
        }

        return str_contains($path, '/vendor/') ? self::VENDOR : self::APP;
    }

    /**
     * Install directories of the packages that came from a `path` repository,
     * each with a trailing slash so `billing` does not also claim
     * `billing-legacy`.
     *
     * @return array<int, string>
     */
    protected function localPackages(): array
    {
        if ($this->localPackages !== null) {
            return $this->localPackages;
        }

        $this->localPackages = [];

        $directory = $this->composerDirectory ?? $this->runningComposerDirectory();

        if ($directory === null) {
            return $this->localPackages;
        }

        $installed = $this->read($directory.'/installed.json');

        // Composer 2 wraps the list; Composer 1 wrote it bare.
        $packages = $installed['packages'] ?? $installed;

        foreach (is_array($packages) ? $packages : [] as $package) {
            if (! is_array($package) || ($package['dist']['type'] ?? null) !== 'path') {
                continue;
            }

            $relative = $package['install-path'] ?? null;

            if (! is_string($relative) || $relative === '') {
                continue;
            }

            // Relative to vendor/composer. Resolved, so a symlinked install
            // lands on the same spelling as the resolved file being tested.
            $path = $directory.'/'.$relative;
            $this->localPackages[] = rtrim($this->normalise(realpath($path) ?: $path), '/').'/';
        }

        return $this->localPackages;
    }

    /** @return array<mixed> */
    protected function read(string $file): array
    {
        if (! is_file($file)) {
            return [];
        }

        $decoded = json_decode((string) @file_get_contents($file), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** vendor/composer of the autoloader this process is using. */
    protected function runningComposerDirectory(): ?string
    {
        try {
            $file = (new ReflectionClass(ClassLoader::class))->getFileName();
        } catch (Throwable) {
            return null;
        }

        return $file === false ? null : dirname($file);
    }

    /** Forward slashes, so one set of comparisons holds on every platform. */
    protected function normalise(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
