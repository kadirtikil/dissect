<?php

namespace KdrDev\Dissect\Architecture;

use KdrDev\Dissect\Routes\RouteFingerprint;
use KdrDev\Dissect\Support\ClassFile;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * The domain list: every domain, how it was found, and the classes in it.
 *
 * Built by reading every PHP file under the application's source folders, so
 * it is the dearest thing in this namespace and is only built when asked for,
 * by the page that lists domains. Everything else asks
 * {@see ContextResolver} about one class at a time.
 *
 * Classes are read from tokens and never loaded.
 */
final class DomainMap
{
    /** Folders no domain lives in, wherever they turn up. */
    public const SKIPPED = ['vendor', 'node_modules', 'tests', 'Tests', 'storage', 'bootstrap'];

    /**
     * @param  array<int, string>  $paths  Where classes are looked for, relative to the root
     *                                     or absolute. Domain roots are always added.
     */
    public function __construct(
        protected ContextResolver $resolver,
        protected DomainFile $file,
        protected array $paths = ['app', 'src', 'Modules', 'app-modules', 'packages'],
    ) {}

    /**
     * @return array{
     *     enabled: bool,
     *     domains: array<int, array{name: string, sources: array<int, string>, roots: array<int, string>, classes: array<int, string>}>,
     *     unassigned: array<int, string>,
     *     findings: array<int, array{kind: string, class: string, file: string, context: string, folder: ?string, message: string}>,
     *     problems: array<int, string>,
     *     generated_at: string,
     * }
     */
    public function export(): array
    {
        $empty = [
            'enabled' => $this->resolver->enabled(),
            'domains' => [],
            'unassigned' => [],
            'findings' => [],
            'problems' => $this->resolver->problems(),
            'generated_at' => now()->toIso8601String(),
        ];

        if (! $this->resolver->enabled()) {
            return $empty;
        }

        $contexts = $this->resolver->contexts();
        $classes = [];
        $sources = [];
        $unassigned = [];
        $findings = [];

        foreach ($this->classFiles($contexts) as $path => $class) {
            $attribute = DomainAttribute::in($path);
            $folder = $this->resolver->fromFolder($path);
            $context = $attribute ?? $folder;

            if ($context === null) {
                $unassigned[] = $class;

                continue;
            }

            $classes[$context][] = $class;

            if ($attribute === null) {
                continue;
            }

            $sources[$attribute] = true;

            if (! isset($contexts[$attribute])) {
                $findings[] = $this->finding('unknown-domain', $class, $path, $attribute, $folder,
                    "#[Domain('{$attribute}')] names a domain no folder or domains.json entry has.");
            } elseif ($folder !== null && $folder !== $attribute) {
                $findings[] = $this->finding('override-disagrees', $class, $path, $attribute, $folder,
                    "#[Domain('{$attribute}')] on a class in {$folder}'s folder.");
            }
        }

        $domains = [];

        foreach (array_unique([...array_keys($contexts), ...array_keys($classes)]) as $name) {
            $context = $contexts[$name] ?? null;
            $found = array_map(fn (ContextSource $source) => $source->value, $context?->sources ?? []);

            if (isset($sources[$name])) {
                $found[] = ContextSource::Attribute->value;
            }

            $members = $classes[$name] ?? [];
            sort($members);

            $domains[] = [
                'name' => (string) $name,
                'sources' => array_values(array_unique($found)),
                'roots' => array_map($this->relative(...), $context?->roots ?? []),
                'classes' => $members,
            ];
        }

        usort($domains, fn (array $a, array $b) => strcmp($a['name'], $b['name']));
        sort($unassigned);
        usort($findings, fn (array $a, array $b) => strcmp($a['class'], $b['class']));

        return ['domains' => $domains, 'unassigned' => $unassigned, 'findings' => $findings] + $empty;
    }

    /**
     * Cheap change signal: the newest file under the walked folders, how many
     * there are, and when domains.json last changed.
     */
    public function fingerprint(): string
    {
        if (! $this->resolver->enabled()) {
            return 'off';
        }

        $directories = $this->directories($this->resolver->contexts());

        return substr(sha1(
            (new RouteFingerprint($directories))->signal().':'.$this->file->signal()
        ), 0, 16);
    }

    /**
     * When domains.json last changed, for surfaces that carry a domain per row
     * and are cached on signals that never look at that file.
     */
    public function fileSignal(): int
    {
        return $this->file->signal();
    }

    /**
     * Every class declared under the walked folders, by its resolved path.
     *
     * @param  array<string, Context>  $contexts
     * @return array<string, string>
     */
    protected function classFiles(array $contexts): array
    {
        $files = [];

        // A domain can be a single file, which no directory walk reaches.
        foreach ($contexts as $context) {
            foreach ($context->roots as $root) {
                if (is_file($root)) {
                    $files[$root] = true;
                }
            }
        }

        $directories = $this->directories($contexts);

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

        $classes = [];

        foreach (array_keys($files) as $path) {
            $class = ClassFile::classIn($path);

            if ($class !== null) {
                $classes[$path] = $class;
            }
        }

        return $classes;
    }

    /**
     * The configured folders that exist, plus every domain's.
     *
     * @param  array<string, Context>  $contexts
     * @return array<int, string>
     */
    protected function directories(array $contexts): array
    {
        $directories = [];

        foreach ($this->paths as $path) {
            $directories[] = str_starts_with($path, DIRECTORY_SEPARATOR)
                ? $path
                : rtrim($this->resolver->root(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$path;
        }

        foreach ($contexts as $context) {
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
    protected function relative(string $path): string
    {
        $root = rtrim(realpath($this->resolver->root()) ?: $this->resolver->root(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_replace(DIRECTORY_SEPARATOR, '/', str_starts_with($path, $root) ? substr($path, strlen($root)) : $path);
    }

    /** @return array{kind: string, class: string, file: string, context: string, folder: ?string, message: string} */
    protected function finding(string $kind, string $class, string $path, string $context, ?string $folder, string $message): array
    {
        return [
            'kind' => $kind,
            'class' => $class,
            'file' => $this->relative($path),
            'context' => $context,
            'folder' => $folder,
            'message' => $message,
        ];
    }
}
