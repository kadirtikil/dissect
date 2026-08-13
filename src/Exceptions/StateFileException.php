<?php

namespace KdrDev\Dissect\Exceptions;

use RuntimeException;

/**
 * Raised when a write to one of the files dissect keeps in the application
 * (.dissect/layout.json, .dissect/views.json) is refused to protect what is
 * already in it.
 *
 * Refusing is the whole point: both files are rewritten wholesale on every
 * save, so a write that cannot be made safely is a write that would replace a
 * developer's work with a subset of it.
 */
class StateFileException extends RuntimeException
{
    public static function newerFormat(string $path, int $found, int $supported): self
    {
        return new self(sprintf(
            '%s was written by a newer version of dissect (format %d; this version understands %d). '
            .'It has been left unchanged — update the package to edit it again.',
            basename($path),
            $found,
            $supported,
        ));
    }

    public static function backupFailed(string $path, string $backup): self
    {
        return new self(sprintf(
            'Could not copy %s to %s before rewriting it, so nothing was written. '
            .'Check the directory is writable.',
            basename($path),
            basename($backup),
        ));
    }
}
