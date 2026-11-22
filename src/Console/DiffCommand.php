<?php

namespace KdrDev\Dissect\Console;

use Illuminate\Console\Command;
use KdrDev\Dissect\Changes\Report;
use KdrDev\Dissect\Changes\Snapshot;
use KdrDev\Dissect\Changes\UnreadableSnapshot;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * `php artisan dissect:diff`: what changed since the snapshot.
 *
 * Takes a snapshot of the app as it is now and compares it with the one in
 * .dissect/snapshot.json: each model, route, job or provider that is new,
 * gone or different, how much that can hurt whoever depends on it, and why.
 */
class DiffCommand extends Command
{
    protected $signature = 'dissect:diff
        {--against= : The snapshot to compare with, instead of the one in config/dissect.php}
        {--format=text : text, or json for a script}';

    protected $description = 'Show what changed in the models, routes, jobs and providers since the snapshot';

    protected const COLORS = ['breaking' => 'red', 'risky' => 'yellow', 'additive' => 'green'];

    public function handle(Snapshot $snapshot): int
    {
        $format = (string) $this->option('format');

        if (! in_array($format, ['text', 'json'], true)) {
            $this->components->error("dissect:diff cannot write \"{$format}\". Pick one of: text, json.");

            return self::INVALID;
        }

        $path = $this->path();
        $shown = $this->shown($path);

        try {
            $before = Snapshot::read($path, $shown);
        } catch (UnreadableSnapshot $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        try {
            $now = $snapshot->take();
        } catch (Throwable $e) {
            $this->components->error("Could not read the app as it is now: {$e->getMessage()}");

            return self::FAILURE;
        }

        $report = Report::between($before, $now);

        if ($format === 'json') {
            $this->output->write(json_encode(['against' => $shown, ...$report], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n", false, OutputInterface::OUTPUT_RAW);
        } else {
            $this->text($report, $shown);
        }

        return self::SUCCESS;
    }

    /** @param  array{summary: array<string, int>, changes: array<int, array<string, mixed>>}  $report */
    protected function text(array $report, string $shown): void
    {
        if ($report['changes'] === []) {
            $this->components->info("Nothing changed since {$shown}.");

            return;
        }

        $this->newLine();

        foreach ($report['changes'] as $change) {
            $this->entry($change['severity'], $change['text']);

            foreach ($change['facts'] as $fact) {
                $this->entry($fact['severity'], '    '.$fact['text']);
            }
        }

        $this->newLine();
        $this->components->info($this->summary($report['summary'])." since {$shown}.");
    }

    /** @param  array<string, int>  $summary */
    public static function summary(array $summary): string
    {
        $total = array_sum($summary);
        $counted = array_map(fn (string $severity, int $count) => "{$count} {$severity}", array_keys(array_filter($summary)), array_filter($summary));

        return $total.' '.($total === 1 ? 'change' : 'changes').($counted === [] ? '' : ': '.implode(', ', $counted));
    }

    /** The severity in its colour, padded so every line's text starts in one column. */
    protected function entry(string $severity, string $text): void
    {
        $color = self::COLORS[$severity] ?? 'default';

        $this->output->writeln("  <fg={$color}>".str_pad($severity, 9).'</>'.OutputFormatter::escape($text));
    }

    protected function path(): string
    {
        $against = $this->option('against');

        if (is_string($against) && $against !== '') {
            return str_starts_with($against, DIRECTORY_SEPARATOR) ? $against : getcwd().DIRECTORY_SEPARATOR.$against;
        }

        return (string) config('dissect.snapshot_path', base_path('.dissect/snapshot.json'));
    }

    /** Relative to the app when it is inside it, as a reader would type it. */
    protected function shown(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
