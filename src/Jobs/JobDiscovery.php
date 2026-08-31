<?php

namespace KdrDev\Dissect\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
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
     * Tokens rather than a regular expression: `class` is a word that appears
     * in `Foo::class`, in `new class`, and in every doc block and string
     * literal in the file. The tokeniser already knows which of those is a
     * declaration, and asking it costs less than being wrong.
     */
    protected function classIn(string $file): ?string
    {
        $contents = @file_get_contents($file);

        if ($contents === false) {
            return null;
        }

        try {
            $tokens = @token_get_all($contents);
        } catch (Throwable) {
            // A file this PHP version cannot tokenise is a file this package
            // has no opinion about.
            return null;
        }

        $namespace = '';

        foreach ($tokens as $i => $token) {
            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = $this->nameAfter($tokens, $i);

                continue;
            }

            if ($token[0] !== T_CLASS) {
                continue;
            }

            // `Foo::class` is a constant fetch and `new class` is an anonymous
            // declaration; neither names a class this can address.
            $previous = $this->significantBefore($tokens, $i);

            if ($previous === T_DOUBLE_COLON || $previous === T_NEW) {
                continue;
            }

            $name = $this->nameAfter($tokens, $i);

            if ($name === '') {
                return null;
            }

            return $namespace === '' ? $name : $namespace.'\\'.$name;
        }

        return null;
    }

    /**
     * The name token following position `$from`, joined.
     *
     * PHP 8 hands back a qualified name as a single token, but a namespace can
     * still arrive in pieces, so both forms are accumulated.
     *
     * @param  array<int, array{0: int, 1: string}|string>  $tokens
     */
    protected function nameAfter(array $tokens, int $from): string
    {
        $name = '';

        for ($i = $from + 1, $length = count($tokens); $i < $length; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                // Leading whitespace, but once a name has started a gap ends it:
                // `namespace App; class Foo` must not run together.
                if ($name === '') {
                    continue;
                }

                break;
            }

            if (! is_array($token)) {
                break;
            }

            if (in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR], true)) {
                $name .= $token[1];

                continue;
            }

            break;
        }

        return trim($name, '\\');
    }

    /**
     * The kind of the last token before `$from` that carries meaning, or null.
     *
     * @param  array<int, array{0: int, 1: string}|string>  $tokens
     */
    protected function significantBefore(array $tokens, int $from): ?int
    {
        for ($i = $from - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if (! is_array($token)) {
                return null;
            }

            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token[0];
        }

        return null;
    }
}
