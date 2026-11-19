<?php

namespace KdrDev\Dissect\Architecture;

/**
 * Domains an application already has, read off its folder layout.
 *
 * Folders are the source of truth because they cannot drift from where a file
 * actually lives: a class is in `app/Domain/Billing` or it is not. Each layout
 * here is one the Laravel ecosystem already uses, so an application that
 * follows one gets its domains with nothing to configure, and one that follows
 * none gets no domains at all, which is the point. A plain Laravel app looks
 * exactly as it did.
 *
 * Only directory listings are read. Nothing is parsed or loaded.
 */
final class Conventions
{
    /**
     * Folders that hold one domain per child, and what they are called.
     *
     * @var array<string, ContextSource>
     */
    public const CONTAINERS = [
        'Modules' => ContextSource::Modules,
        'app-modules' => ContextSource::AppModules,
        'app/Domain' => ContextSource::DomainFolder,
        'app/Domains' => ContextSource::DomainFolder,
        'src/Domain' => ContextSource::DomainFolder,
        'src/Domains' => ContextSource::DomainFolder,
    ];

    /** The layers a hexagonal context is split into; two of them make one. */
    public const HEXAGONAL_LAYERS = ['Domain', 'Application', 'Infrastructure'];

    /**
     * Where a layer-first app keeps each kind of class, under `app/`.
     *
     * A domain in such an app is a sub-folder name that turns up under
     * `app/Models` *and* under at least one of these.
     */
    public const LAYERS = [
        'Actions',
        'Console/Commands',
        'Data',
        'Events',
        'Http/Controllers',
        'Http/Requests',
        'Http/Resources',
        'Jobs',
        'Listeners',
        'Mail',
        'Notifications',
        'Observers',
        'Policies',
        'Providers',
        'Repositories',
        'Services',
    ];

    /**
     * Sub-folders of `app/Models` that hold a kind of thing, not a domain.
     *
     * Each also turns up under other layers often enough (`Concerns` beside
     * controllers) to be mistaken for one.
     */
    public const NOT_DOMAINS = [
        'Attributes', 'Builders', 'Casts', 'Concerns', 'Contracts', 'Enums',
        'Pivots', 'Relations', 'Scopes', 'Traits',
    ];

    /**
     * Every domain the layout implies, sorted by name.
     *
     * @return array<int, Context>
     */
    public function detect(string $root): array
    {
        $root = rtrim(realpath($root) ?: $root, DIRECTORY_SEPARATOR);
        $found = [];

        $add = function (string $name, ContextSource $source, array $roots) use (&$found) {
            $context = new Context($name, [$source], $roots);
            $found[$name] = isset($found[$name]) ? $found[$name]->merge($context) : $context;
        };

        foreach (self::CONTAINERS as $container => $source) {
            foreach ($this->children($root.'/'.$container) as $name => $path) {
                $add($name, $source, [$path]);
            }
        }

        foreach ($this->packages($root.'/packages') as $name => $path) {
            $add($name, ContextSource::Packages, [$path]);
        }

        foreach ($this->hexagonal($root) as $name => $path) {
            $add($name, ContextSource::Hexagonal, [$path]);
        }

        foreach ($this->layerFolders($root.'/app') as $name => $paths) {
            $add($name, ContextSource::LayerFolders, $paths);
        }

        ksort($found);

        return array_values($found);
    }

    /**
     * One package per child of `packages/`, or per grandchild when the
     * children are vendor names (`packages/acme/billing`).
     *
     * A package is a folder with a composer.json. Anything else under
     * `packages/` is not one and names nothing.
     *
     * @return array<string, string>
     */
    protected function packages(string $directory): array
    {
        $packages = [];

        foreach ($this->children($directory) as $name => $path) {
            if (is_file($path.'/composer.json')) {
                $packages[$name] = $path;

                continue;
            }

            foreach ($this->children($path) as $child => $childPath) {
                if (is_file($childPath.'/composer.json')) {
                    $packages[$child] = $childPath;
                }
            }
        }

        return $packages;
    }

    /**
     * Folders under `app/` or `src/` that are split into hexagonal layers.
     *
     * `src/Billing/Domain` alone could be anything. Two of the three layers
     * side by side is a pattern, and the folder holding them is the context.
     *
     * @return array<string, string>
     */
    protected function hexagonal(string $root): array
    {
        $contexts = [];

        foreach (['app', 'src'] as $base) {
            foreach ($this->children($root.'/'.$base) as $name => $path) {
                // A container of domains, not a domain.
                if (in_array($name, ['Domain', 'Domains'], true)) {
                    continue;
                }

                $layers = array_filter(self::HEXAGONAL_LAYERS, fn (string $layer) => is_dir($path.'/'.$layer));

                if (count($layers) >= 2) {
                    $contexts[$name] = $path;
                }
            }
        }

        return $contexts;
    }

    /**
     * Domains in a layer-first app: `app/Models/Billing` beside
     * `app/Http/Controllers/Billing`.
     *
     * The models folder has to be one of them. Starter kits put `Auth`
     * folders under both controllers and requests, and an `Auth` domain made
     * of login controllers is not what anybody means.
     *
     * @return array<string, array<int, string>>
     */
    protected function layerFolders(string $app): array
    {
        $domains = [];

        foreach ($this->children($app.'/Models') as $name => $models) {
            if (in_array($name, self::NOT_DOMAINS, true)) {
                continue;
            }

            $siblings = [];

            foreach (self::LAYERS as $layer) {
                $path = $app.'/'.$layer.'/'.$name;

                if (is_dir($path)) {
                    $siblings[] = realpath($path) ?: $path;
                }
            }

            if ($siblings !== []) {
                $domains[$name] = [$models, ...$siblings];
            }
        }

        return $domains;
    }

    /**
     * The visible sub-directories of a directory, by name.
     *
     * @return array<string, string>
     */
    protected function children(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $children = [];

        foreach (scandir($directory) ?: [] as $name) {
            $path = $directory.'/'.$name;

            if ($name[0] !== '.' && is_dir($path)) {
                $children[$name] = realpath($path) ?: $path;
            }
        }

        return $children;
    }
}
