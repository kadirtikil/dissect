<?php

namespace KdrDev\Dissect\Jobs;

/**
 * The two directions a path is written in, in one place.
 *
 * Not a convenience. A dispatch site inside a route file is addressed as
 * `routes/api.php:22`, and {@see RouteMap} has to produce the identical string
 * from the router's own view of that closure or the link between them silently
 * does not happen. Two copies of "relative to the application root" that agree
 * today is a join waiting to break the first time one of them is corrected.
 */
class ProjectPath
{
    /**
     * Absolute paths are used as-is; anything else is relative to the app root.
     *
     * Resolved, because a configured directory and a path reflection reports
     * are the same place spelled two ways — `a/tests/../workbench` and
     * `a/workbench` — and the join between a dispatch site and the route it
     * sits in is a string comparison. An unresolvable path is handed back
     * untouched, so a directory that does not exist is still filtered out by
     * the caller rather than silently becoming the working directory.
     */
    public static function absolute(string $path): string
    {
        $path = str_starts_with($path, DIRECTORY_SEPARATOR)
            ? rtrim($path, DIRECTORY_SEPARATOR)
            : rtrim(base_path($path), DIRECTORY_SEPARATOR);

        return realpath($path) ?: $path;
    }

    /**
     * Back the other way, with forward slashes whatever the platform.
     *
     * An absolute path is a machine's business; what belongs on the surface is
     * the path somebody would open in their editor.
     */
    public static function relative(string $path): string
    {
        $path = realpath($path) ?: $path;
        $root = (realpath(base_path()) ?: base_path()).DIRECTORY_SEPARATOR;

        $path = str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;

        return str_replace(DIRECTORY_SEPARATOR, '/', $path);
    }
}
