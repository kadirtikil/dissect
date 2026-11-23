<?php

namespace KdrDev\Dissect\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use KdrDev\Dissect\Changes\Baselines;
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
        {--watch : Start watching instead: a snapshot in storage/, never committed, for the page and agents to compare against}
        {--output= : Write it here instead of the snapshot file in config/dissect.php}';

    protected $description = 'Write a snapshot of the models, routes, jobs and providers, for dissect:diff to compare against';

    public function handle(Snapshot $snapshot, Filesystem $files, Baselines $baselines): int
    {
        $path = $this->path($baselines);

        try {
            $taken = $snapshot->take();
        } catch (Throwable $e) {
            $this->components->error("Could not take a snapshot: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->warnUnread($taken);

        $json = Snapshot::encode($taken);
        $shown = $baselines->shown($path);
        $watching = (bool) $this->option('watch');

        if (! $watching && $files->exists($path) && $files->get($path) === $json) {
            $this->components->info("Nothing changed since the snapshot in {$shown}.");

            return self::SUCCESS;
        }

        try {
            $baselines->write($path, $json);
        } catch (Throwable $e) {
            $this->components->error("Could not write {$shown}: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->components->info($watching
            ? 'Watching from now: '.$this->counted(Snapshot::counts($taken)).". The page and dissect_changes compare against it; php artisan dissect:diff --against=watch does too."
            : 'Wrote a snapshot of '.$this->counted(Snapshot::counts($taken))." to {$shown}.");

        return self::SUCCESS;
    }

    protected function path(Baselines $baselines): string
    {
        $output = $this->option('output');

        if (is_string($output) && $output !== '') {
            return str_starts_with($output, DIRECTORY_SEPARATOR) ? $output : getcwd().DIRECTORY_SEPARATOR.$output;
        }

        return $baselines->path($this->option('watch') ? Baselines::WATCH : Baselines::SNAPSHOT);
    }

    /**
     * Models whose columns could not be read are written down without them,
     * and compared without them. Said, because a snapshot taken against an
     * empty database looks like any other.
     */
    protected function warnUnread(array $taken): void
    {
        $unread = array_keys(array_filter((array) ($taken['models'] ?? []), fn (array $model) => isset($model['unreadable'])));

        if ($unread === []) {
            return;
        }

        $names = array_map(class_basename(...), array_slice($unread, 0, 5));
        $more = count($unread) - count($names);

        $this->components->warn(
            (count($unread) === 1 ? 'One model\'s columns' : count($unread).' models\' columns').' could not be read: '
            .implode(', ', $names).($more > 0 ? " and {$more} more" : '')
            .'. Their columns are left out and not compared. Is the database there and migrated?'
        );
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
