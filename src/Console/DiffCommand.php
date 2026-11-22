<?php

namespace KdrDev\Dissect\Console;

use Illuminate\Console\Command;
use KdrDev\Dissect\Changes\Baselines;
use KdrDev\Dissect\Changes\Report;
use KdrDev\Dissect\Changes\Snapshot;
use KdrDev\Dissect\Changes\UnreadableSnapshot;
use KdrDev\Dissect\Digest\Markdown;
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
        {--against=snapshot : snapshot (the committed one), watch (since watching started), or a snapshot file}
        {--format=text : text, json for a script, or md for a pull request comment or a prompt}
        {--fail-on= : Exit 1 on a change this bad or worse: breaking, risky, or any}';

    protected $description = 'Show what changed in the models, routes, jobs and providers since the snapshot';

    protected const COLORS = ['breaking' => 'red', 'risky' => 'yellow', 'additive' => 'green'];

    public function handle(Snapshot $snapshot, Markdown $markdown, Baselines $baselines): int
    {
        $format = (string) $this->option('format');
        $failOn = $this->option('fail-on');

        if (! in_array($format, ['text', 'json', 'md'], true)) {
            $this->components->error("dissect:diff cannot write \"{$format}\". Pick one of: text, json, md.");

            return self::INVALID;
        }

        if ($failOn !== null && ! in_array($failOn, ['breaking', 'risky', 'any'], true)) {
            $this->components->error("dissect:diff cannot fail on \"{$failOn}\". Pick one of: breaking, risky, any.");

            return self::INVALID;
        }

        $path = $this->path($baselines);
        $shown = $baselines->shown($path);

        try {
            $against = (string) $this->option('against');
            $before = Baselines::has($against) ? $baselines->read($against) : Snapshot::read($path, $shown);
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

        match ($format) {
            'json' => $this->output->write(json_encode(['against' => $shown, ...$report], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n", false, OutputInterface::OUTPUT_RAW),
            'md' => $this->output->write($markdown->changes($report, $shown), false, OutputInterface::OUTPUT_RAW),
            default => $this->text($report, $shown),
        };

        return $this->fails($report['summary'], $failOn) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Whether a change at least as bad as --fail-on was found.
     *
     * @param  array<string, int>  $summary
     */
    protected function fails(array $summary, ?string $failOn): bool
    {
        return match ($failOn) {
            'breaking' => $summary['breaking'] > 0,
            'risky' => $summary['breaking'] + $summary['risky'] > 0,
            'any' => array_sum($summary) > 0,
            default => false,
        };
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
        $this->components->info(Report::summary($report['summary'], $shown).'.');
    }

    /** The severity in its colour, padded so every line's text starts in one column. */
    protected function entry(string $severity, string $text): void
    {
        $color = self::COLORS[$severity] ?? 'default';

        $this->output->writeln("  <fg={$color}>".str_pad($severity, 9).'</>'.OutputFormatter::escape($text));
    }

    protected function path(Baselines $baselines): string
    {
        $against = (string) $this->option('against');

        if (Baselines::has($against)) {
            return $baselines->path($against);
        }

        return str_starts_with($against, DIRECTORY_SEPARATOR) ? $against : getcwd().DIRECTORY_SEPARATOR.$against;
    }
}
