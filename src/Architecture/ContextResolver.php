<?php

namespace KdrDev\Dissect\Architecture;

use ReflectionClass;
use Throwable;

/**
 * Which domain a class or file belongs to.
 *
 * In order, the first that answers wins:
 *
 *  1. a `#[Domain('…')]` attribute on the class,
 *  2. a path in `.dissect/domains.json`,
 *  3. a folder convention,
 *  4. none: the class is unassigned, never guessed.
 *
 * Within 2 and 3 the longest matching root wins, so `app/Models/Billing`
 * claims an invoice before a wider `app/Models` mapping does.
 *
 * Cheap enough to ask for every row of every payload: the domain list is built
 * once, from directory listings, and each file is answered once.
 */
final class ContextResolver
{
    /** @var array<string, Context>|null */
    protected ?array $configured = null;

    /** @var array<string, Context>|null */
    protected ?array $detected = null;

    /** @var array<string, ?string> */
    protected array $byFile = [];

    public function __construct(
        protected bool $enabled,
        protected string $root,
        protected DomainFile $file,
        protected Conventions $conventions,
    ) {}

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Every domain the folders and the file name, by name.
     *
     * Domains that only an attribute names are not here: they are found by
     * reading classes, which this does not do.
     *
     * @return array<string, Context>
     */
    public function contexts(): array
    {
        $contexts = $this->detected();

        foreach ($this->configured() as $name => $context) {
            $contexts[$name] = isset($contexts[$name]) ? $context->merge($contexts[$name]) : $context;
        }

        ksort($contexts);

        return $contexts;
    }

    /** @return array<int, string> */
    public function problems(): array
    {
        return $this->enabled ? $this->file->problems() : [];
    }

    /** The domain a loaded class belongs to, by the file it was declared in. */
    public function forClass(?string $class): ?string
    {
        if (! $this->enabled || $class === null || $class === '') {
            return null;
        }

        try {
            $file = (new ReflectionClass($class))->getFileName();
        } catch (Throwable) {
            return null;
        }

        return $file === false ? null : $this->forFile($file);
    }

    public function forFile(string $file): ?string
    {
        if (! $this->enabled) {
            return null;
        }

        $file = realpath($file) ?: $file;

        if (! array_key_exists($file, $this->byFile)) {
            $this->byFile[$file] = DomainAttribute::in($file) ?? $this->fromFolder($file);
        }

        return $this->byFile[$file];
    }

    /**
     * The domain the file's location gives it, ignoring any attribute.
     *
     * Asked separately so an attribute can be checked against it.
     */
    public function fromFolder(string $file): ?string
    {
        if (! $this->enabled) {
            return null;
        }

        $file = realpath($file) ?: $file;

        return $this->deepest($this->configured(), $file) ?? $this->deepest($this->detected(), $file);
    }

    /** The directory conventions are read under. */
    public function root(): string
    {
        return $this->root;
    }

    /** @param  array<string, Context>  $contexts */
    protected function deepest(array $contexts, string $file): ?string
    {
        $best = null;
        $depth = -1;

        foreach ($contexts as $name => $context) {
            $match = $context->depth($file);

            if ($match !== null && $match > $depth) {
                [$best, $depth] = [$name, $match];
            }
        }

        return $best;
    }

    /** @return array<string, Context> */
    protected function configured(): array
    {
        if ($this->configured === null) {
            $this->configured = [];

            if ($this->enabled) {
                foreach ($this->file->contexts() as $context) {
                    $this->configured[$context->name] = $context;
                }
            }
        }

        return $this->configured;
    }

    /** @return array<string, Context> */
    protected function detected(): array
    {
        if ($this->detected === null) {
            $this->detected = [];

            if ($this->enabled && $this->file->conventions()) {
                foreach ($this->conventions->detect($this->root) as $context) {
                    $this->detected[$context->name] = $context;
                }
            }
        }

        return $this->detected;
    }
}
