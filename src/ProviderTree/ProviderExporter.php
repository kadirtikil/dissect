<?php

namespace KdrDev\Dissect\ProviderTree;

use KdrDev\Dissect\Routes\RouteFingerprint;
use Throwable;

/**
 * Builds the provider list — every provider the application declares, each with
 * its tree inline.
 *
 * Orchestration only. Finding the providers is {@see ProviderReader}, reading
 * one is {@see ProviderInspector}, following its concretes into their
 * constructors is {@see DependencyResolver}.
 */
class ProviderExporter
{
    public function __construct(
        protected ProviderReader $reader,
        protected ProviderInspector $inspector,
        protected DependencyResolver $resolver,
        protected RouteFingerprint $fingerprint,
    ) {}

    /**
     * @return array{providers: array<int, array<string, mixed>>, generated_at: string}
     */
    public function export(): array
    {
        $providers = [];

        foreach ($this->reader->read() as $class => $file) {
            $described = $this->describe($class, $file);

            if ($described !== null) {
                $providers[] = $described;
            }
        }

        // By the name the list shows, which is the order it reads in.
        usort($providers, fn ($a, $b) => [$a['name'], $a['class']] <=> [$b['name'], $b['class']]);

        return [
            'providers' => $providers,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Cheap change signal — the same file stat the route and job halves use.
     *
     * Watched more widely than the providers directory on purpose: a tree
     * changes when a provider does, but also when a constructor three hops down
     * gains a parameter, and that constructor can be anywhere in the app. See
     * {@see RouteFingerprint} for why it is coarse in only one direction.
     */
    public function fingerprint(): string
    {
        return $this->fingerprint->signal();
    }

    /**
     * One provider, or null if it could not be read at all.
     *
     * A provider that throws on the way costs its own row and nothing else —
     * the rule every other surface follows.
     *
     * @return array<string, mixed>|null
     */
    protected function describe(string $class, string $file): ?array
    {
        try {
            $tree = $this->inspector->tree($class);

            if ($tree === null) {
                return null;
            }

            $this->resolver->resolve($tree);

            $description = $tree->build()->toArray();
        } catch (Throwable) {
            return null;
        }

        // The list needs a name and a file without digging the root out of
        // the node array, so they are hoisted beside the tree.
        return [
            'id' => $class,
            'class' => $class,
            'name' => class_basename($class),
            'file' => $file,
            ...$description,
        ];
    }
}
