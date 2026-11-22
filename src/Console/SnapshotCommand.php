<?php

namespace KdrDev\Dissect\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use KdrDev\Dissect\Changes\Snapshot;
use Throwable;

/**
 * `php artisan dissect:snapshot`: write down what the app looks like now.
 *
 * Committed, it is the baseline `dissect:diff` compares against: what changed
 * since the snapshot, in models, routes, jobs and providers. Run it again
 * once a change is accepted, and the snapshot's own diff in the pull request
 * says what was accepted.
 */
class SnapshotCommand extends Command
{
    protected $signature = 'dissect:snapshot
        {--output= : Write it here instead of the snapshot file in config/dissect.php}';

    protected $description = 'Write a snapshot of the models, routes, jobs and providers, for dissect:diff to compare against';

    public function handle(Snapshot $snapshot, Filesystem $files): int
    {
        $path = $this->path();

        try {
            $taken = $snapshot->take();
        } catch (Throwable $e) {
            $this->components->error("Could not take a snapshot: {$e->getMessage()}");

            return self::FAILURE;
        }

        $json = Snapshot::encode($taken);
        $shown = $this->shown($path);

        if ($files->exists($path) && $files->get($path) === $json) {
            $this->components->info("Nothing changed since the snapshot in {$shown}.");

            return self::SUCCESS;
        }

        try {
            $this->write($files, $path, $json);
        } catch (Throwable $e) {
            $this->components->error("Could not write {$shown}: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->components->info('Wrote a snapshot of '.$this->counted(Snapshot::counts($taken))." to {$shown}.");

        return self::SUCCESS;
    }

    protected function path(): string
    {
        $output = $this->option('output');

        if (is_string($output) && $output !== '') {
            return str_starts_with($output, DIRECTORY_SEPARATOR) ? $output : getcwd().DIRECTORY_SEPARATOR.$output;
        }

        return (string) config('dissect.snapshot_path', base_path('.dissect/snapshot.json'));
    }

    /**
     * Written beside the old one, then moved over it: a snapshot is the
     * baseline every later diff is read against, and half of one would be
     * read as everything having been removed.
     */
    protected function write(Filesystem $files, string $path, string $json): void
    {
        $files->ensureDirectoryExists(dirname($path));

        // tempnam() falls back to the system's temp directory when this one
        // is not writable, and a rename from there may cross disks.
        $temporary = @tempnam(dirname($path), '.snapshot-');

        if ($temporary === false || dirname($temporary) !== (realpath(dirname($path)) ?: dirname($path))) {
            $temporary === false || @unlink($temporary);

            throw new \RuntimeException('cannot create a file in '.dirname($path).'.');
        }

        try {
            if (file_put_contents($temporary, $json) !== strlen($json)) {
                throw new \RuntimeException('the disk took only part of it.');
            }

            chmod($temporary, 0644);

            if (! rename($temporary, $path)) {
                throw new \RuntimeException('cannot move it into place.');
            }
        } finally {
            @unlink($temporary);
        }
    }

    /** Relative to the app when it is inside it, as a reader would type it. */
    protected function shown(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    /** @param  array<string, int>  $counts */
    protected function counted(array $counts): string
    {
        $words = [
            'models' => ['model', 'models'],
            'routes' => ['route', 'routes'],
            'jobs' => ['job', 'jobs'],
            'providers' => ['provider', 'providers'],
        ];

        $parts = array_map(fn (string $part, int $count) => $count.' '.$words[$part][$count === 1 ? 0 : 1], array_keys($counts), $counts);
        $last = array_pop($parts);

        return $parts === [] ? (string) $last : implode(', ', $parts).' and '.$last;
    }
}
