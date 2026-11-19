<?php

namespace KdrDev\Dissect\Concerns;

use JsonException;
use KdrDev\Dissect\Exceptions\StateFileException;

/**
 * Shared handling for the two JSON files dissect keeps in the application.
 *
 * Both are authored by hand — nodes dragged into place, views named and
 * curated — and both are rewritten in full on every save. That combination is
 * what makes the recorded format version load-bearing rather than decorative:
 * sanitising silently drops everything it does not recognise, so reading a file
 * written by a newer dissect and then saving would quietly replace it with
 * whatever subset this version happens to understand. Once, without a warning,
 * and the atomic rename below would make it stick.
 *
 * So the rule is: a file from the future is readable but never writable, and a
 * file from the past is copied aside before this version rewrites it.
 *
 * The using class must declare a `VERSION` constant and hold the file location
 * in `$this->path`.
 */
trait VersionedStateFile
{
    /**
     * The decoded file, or null when it is missing, unreadable or not JSON.
     *
     * @return array<string, mixed>|null
     */
    protected function decode(): ?array
    {
        if (! is_file($this->path)) {
            return null;
        }

        try {
            $decoded = json_decode((string) file_get_contents($this->path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * The format version recorded in the file.
     *
     * Defaults to 1 — the first format, which predates anything reading this
     * field — and treats a nonsense value the same way, since a hand-edited
     * file that says "banana" is likelier to be a typo than a message from the
     * future.
     *
     * @param  array<string, mixed>  $decoded
     */
    protected function versionOf(array $decoded): int
    {
        $version = $decoded['version'] ?? 1;

        return is_numeric($version) ? (int) $version : 1;
    }

    /**
     * Decides whether the pending write may proceed, and takes a copy first
     * when it is about to change the file's format.
     *
     * @throws StateFileException when the file must not be overwritten.
     */
    protected function guardWrite(): void
    {
        if (! is_file($this->path)) {
            return;
        }

        $decoded = $this->decode();

        // Unparseable, so there is no version to compare and no data to
        // preserve in the payload — but a file somebody was midway through
        // editing by hand is worth more than the milliseconds a copy costs.
        if ($decoded === null) {
            $this->backup('corrupt');

            return;
        }

        $version = $this->versionOf($decoded);

        if ($version > static::VERSION) {
            throw StateFileException::newerFormat($this->path, $version, static::VERSION);
        }

        if ($version < static::VERSION) {
            $this->backup('v'.$version);
        }
    }

    /**
     * Copies the file aside before a rewrite that changes its format.
     *
     * Never overwrites an existing backup: the first copy is the one taken
     * before any write in the new format touched the file, which is the only
     * one worth keeping. A failed copy aborts the write rather than proceeding
     * without a net.
     */
    protected function backup(string $suffix): void
    {
        $backup = $this->path.'.'.$suffix.'.bak';

        if (is_file($backup)) {
            return;
        }

        if (! @copy($this->path, $backup)) {
            throw StateFileException::backupFailed($this->path, $backup);
        }
    }

    /**
     * Write-then-rename: an interrupted write leaves the previous file intact
     * rather than a truncated one nothing can parse.
     *
     * The temporary file gets a name of its own on every call. A fixed one
     * (`layout.json.tmp`) is shared by two saves that overlap — a drag and a
     * view edit a few milliseconds apart — and the first rename can then move
     * a file the second is still halfway through writing. It lives beside the
     * target rather than in the system temp directory, because rename() is
     * only atomic within one filesystem.
     *
     * Every step is checked. A full disk or a read-only checkout used to
     * surface as a PHP warning — a 500 with nothing in it about which file —
     * and a short write would have been renamed over the good file.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws StateFileException when the file could not be written.
     */
    protected function writeAtomically(array $payload): void
    {
        $directory = dirname($this->path);

        // Re-checked after a failed mkdir: another request may have created it
        // in between, which is not a failure.
        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw StateFileException::unwritable($this->path, 'the directory could not be created');
        }

        $encoded = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ).PHP_EOL;

        $temp = $this->path.'.'.bin2hex(random_bytes(6)).'.tmp';

        if (@file_put_contents($temp, $encoded) !== strlen($encoded)) {
            @unlink($temp);

            throw StateFileException::unwritable($this->path, 'the new contents could not be written in full');
        }

        if (! @rename($temp, $this->path)) {
            @unlink($temp);

            throw StateFileException::unwritable($this->path, 'the new contents could not replace the old');
        }
    }
}
