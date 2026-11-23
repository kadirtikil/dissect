<?php

namespace KdrDev\Dissect\Support;

/**
 * Whether a path stands on its own or is read from a directory.
 *
 * "Starts with the directory separator" is the test on Linux and macOS, and
 * wrong on Windows twice over: `C:\app` does not start with `\`, and a path
 * written with forward slashes (`C:/app`, the way PHP and Composer often
 * print them) does not either. Every such path read as relative, and was
 * joined onto the working directory.
 */
final class Path
{
    /** `/srv/app`, `C:\app`, `C:/app` or `\\server\share`. */
    public static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1;
    }
}
