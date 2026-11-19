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
     * Sent by a client that has no revision to offer: the dev-server plugin,
     * or a page from before revisions existed. Its write is let through
     * unchecked, as every write was before.
     */
    public const ANY_REVISION = '*';

    /**
     * A fingerprint of the file as it is on disk, or null when there is none.
     *
     * Handed to the page with the contents, and handed back with every save:
     * a save based on contents that are no longer on disk is refused rather
     * than allowed to replace them. See save().
     */
    public function revision(): ?string
    {
        return $this->revisionOf($this->contents());
    }

    protected function revisionOf(?string $contents): ?string
    {
        return $contents === null ? null : substr(hash('sha256', $contents), 0, 16);
    }

    protected function contents(): ?string
    {
        if (! is_file($this->path)) {
            return null;
        }

        $contents = @file_get_contents($this->path);

        return $contents === false ? null : $contents;
    }

    /**
     * The decoded file, or null when it is missing, unreadable or not JSON.
     *
     * @return array<string, mixed>|null
     */
    protected function decode(?string $contents = null): ?array
    {
        $contents ??= $this->contents();

        if ($contents === null) {
            return null;
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
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
     * Replaces the file, provided it is still the one the client last read.
     *
     * Both files are rewritten whole, so a page that read the file an hour ago
     * would otherwise save its hour-old copy over whatever happened since — a
     * teammate's views arriving with a `git pull`, or a layout arranged in
     * another tab. Comparing revisions turns that into a refusal the page can
     * report. The check and the write share a lock, or two saves could both
     * pass the check and the second would still overwrite the first.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws StateFileException when the file changed, must not be
     *                            overwritten, or could not be written.
     */
    protected function save(array $payload, ?string $basedOn): void
    {
        $this->exclusively(function () use ($payload, $basedOn) {
            if ($basedOn !== self::ANY_REVISION && $basedOn !== $this->revision()) {
                throw StateFileException::changedSinceRead($this->path);
            }

            $this->guardWrite();
            $this->writeAtomically($payload);
        });
    }

    /**
     * Runs the callback holding an exclusive lock on this file.
     *
     * The lock file lives in the system temp directory rather than beside the
     * state file, which sits in the application and is often committed. If it
     * cannot be opened the write goes ahead unlocked, exactly as it did before
     * there was a lock: it guards a race, and a save that cannot happen at all
     * would be worse than the race.
     */
    protected function exclusively(callable $callback): void
    {
        $lock = @fopen(sys_get_temp_dir().'/dissect-'.sha1($this->path).'.lock', 'c');

        if ($lock === false) {
            $callback();

            return;
        }

        try {
            flock($lock, LOCK_EX);
            $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
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
            $this->backupCorrupt();

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
     * Copies an unparseable file aside before it is replaced.
     *
     * Unlike a format backup, every distinct one is kept. The first corrupt
     * file and the fifth are different hand edits, each somebody's work, and
     * `backup()`'s never-overwrite rule would have kept the first and silently
     * replaced the fifth with a fresh layout. So the name is numbered past
     * whatever exists — `.corrupt.bak`, then `.corrupt.2.bak` and on — and a
     * file identical to one already copied is not copied again, which is what
     * a retried save of the same broken file would otherwise do.
     */
    protected function backupCorrupt(): void
    {
        $hash = hash_file('sha256', $this->path);

        for ($n = 1; ; $n++) {
            $backup = $this->path.($n === 1 ? '.corrupt.bak' : '.corrupt.'.$n.'.bak');

            if (! is_file($backup)) {
                break;
            }

            if (hash_file('sha256', $backup) === $hash) {
                return;
            }
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
