<?php

namespace KdrDev\Dissect\Architecture;

use KdrDev\Dissect\Support\ClassFile;

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
    public function __construct(
        protected ContextResolver $resolver,
        protected DomainFile $file,
        protected SourceFiles $sources,
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

        foreach ($this->classFiles() as $path => $class) {
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
                'roots' => array_map($this->sources->relative(...), $context?->roots ?? []),
                'classes' => $members,
            ];
        }

        usort($domains, fn (array $a, array $b) => strcmp($a['name'], $b['name']));
        sort($unassigned);
        usort($findings, fn (array $a, array $b) => strcmp($a['class'], $b['class']));

        return ['domains' => $domains, 'unassigned' => $unassigned, 'findings' => $findings] + $empty;
    }

    /**
     * Every domain the folders and domains.json name, sorted.
     *
     * Cheap, because no class is read: it is what the page is handed up front
     * so every surface colours a domain the same way. Domains only an
     * attribute names are found by {@see export()} and come after these.
     *
     * @return array<int, string>
     */
    public function names(): array
    {
        return $this->resolver->enabled()
            ? array_map('strval', array_keys($this->resolver->contexts()))
            : [];
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

        return substr(sha1($this->sources->signal().':'.$this->file->signal()), 0, 16);
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
     * @return array<string, string>
     */
    protected function classFiles(): array
    {
        $classes = [];

        foreach ($this->sources->files() as $path) {
            $class = ClassFile::classIn($path);

            if ($class !== null) {
                $classes[$path] = $class;
            }
        }

        return $classes;
    }

    /** @return array{kind: string, class: string, file: string, context: string, folder: ?string, message: string} */
    protected function finding(string $kind, string $class, string $path, string $context, ?string $folder, string $message): array
    {
        return [
            'kind' => $kind,
            'class' => $class,
            'file' => $this->sources->relative($path),
            'context' => $context,
            'folder' => $folder,
            'message' => $message,
        ];
    }
}
