<?php

namespace KdrDev\Dissect\Changes;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

/**
 * The snapshots a change can be read against.
 *
 * - **snapshot**: `.dissect/snapshot.json`, committed. "What changed since the
 *   team last agreed on it" — what CI and a reviewer compare against.
 * - **watch**: a snapshot in `storage/`, never committed, taken when someone
 *   starts watching (from the page, or `dissect:snapshot --watch`). "What
 *   changed since I sat down", or since an agent was let loose.
 */
final class Baselines
{
    public const SNAPSHOT = 'snapshot';

    public const WATCH = 'watch';

    /** In the order a reader without a preference is given them. */
    public const NAMES = [self::WATCH, self::SNAPSHOT];

    public function __construct(protected Filesystem $files) {}

    public static function has(string $name): bool
    {
        return in_array($name, self::NAMES, true);
    }

    public function path(string $name): string
    {
        return match ($name) {
            self::SNAPSHOT => (string) config('dissect.snapshot_path', base_path('.dissect/snapshot.json')),
            self::WATCH => (string) config('dissect.watch_path', storage_path('dissect/watch.json')),
            default => throw new RuntimeException("There is no baseline called \"{$name}\". Pick one of: ".implode(', ', self::NAMES).'.'),
        };
    }

    public function exists(string $name): bool
    {
        return is_file($this->path($name));
    }

    /** Watching if somebody started to, else the committed snapshot, else none. */
    public function preferred(): ?string
    {
        foreach (self::NAMES as $name) {
            if ($this->exists($name)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws UnreadableSnapshot
     */
    public function read(string $name): array
    {
        if ($name === self::WATCH && ! $this->exists($name)) {
            throw new UnreadableSnapshot('Nothing is being watched. Start watching on the page, or with: php artisan dissect:snapshot --watch');
        }

        return Snapshot::read($this->path($name), $this->shown($this->path($name)));
    }

    /** When it was taken: the file's own time, so the format needs no clock in it. */
    public function takenAt(string $name): ?string
    {
        $time = $this->exists($name) ? filemtime($this->path($name)) : false;

        return $time === false ? null : date(DATE_ATOM, $time);
    }

    /** What each baseline is, for a reader choosing one. @return array<string, array{exists: bool, path: string, taken_at: ?string}> */
    public function describe(): array
    {
        $described = [];

        foreach (self::NAMES as $name) {
            $described[$name] = [
                'exists' => $this->exists($name),
                'path' => $this->shown($this->path($name)),
                'taken_at' => $this->takenAt($name),
            ];
        }

        return $described;
    }

    /** Moves whenever a baseline is taken, replaced or dropped. */
    public function signal(): string
    {
        clearstatcache();

        return implode('|', array_map(fn (string $name) => $this->exists($name) ? filemtime($this->path($name)).':'.filesize($this->path($name)) : '-', self::NAMES));
    }

    /**
     * Written beside the old one, then moved over it: a baseline is what
     * every later diff is read against, and half of one would be read as
     * everything having been removed.
     */
    public function write(string $path, string $json): void
    {
        $this->files->ensureDirectoryExists(dirname($path));

        // tempnam() falls back to the system's temp directory when this one
        // is not writable, and a rename from there may cross disks.
        $temporary = @tempnam(dirname($path), '.snapshot-');

        if ($temporary === false || dirname($temporary) !== (realpath(dirname($path)) ?: dirname($path))) {
            $temporary === false || @unlink($temporary);

            throw new RuntimeException('cannot create a file in '.dirname($path).'.');
        }

        try {
            if (file_put_contents($temporary, $json) !== strlen($json)) {
                throw new RuntimeException('the disk took only part of it.');
            }

            chmod($temporary, 0644);

            if (! rename($temporary, $path)) {
                throw new RuntimeException('cannot move it into place.');
            }
        } finally {
            @unlink($temporary);
        }
    }

    /** Stops watching. The committed snapshot is never dropped from here. */
    public function forget(string $name): void
    {
        if ($name !== self::WATCH) {
            throw new RuntimeException('Only watching can be stopped. The committed snapshot is removed like any other file in the repository.');
        }

        @unlink($this->path($name));
    }

    /** Relative to the app when it is inside it, as a reader would type it. */
    public function shown(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
