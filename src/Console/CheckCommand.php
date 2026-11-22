<?php

namespace KdrDev\Dissect\Console;

use Illuminate\Console\Command;
use KdrDev\Dissect\Architecture\ContextResolver;
use KdrDev\Dissect\Architecture\DomainFile;
use KdrDev\Dissect\Architecture\SourceFiles;
use KdrDev\Dissect\Support\Surfaces;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;

/**
 * `php artisan dissect:check` — fails when a domain breaks its boundary rule.
 *
 * For CI and for an agent's hook. It reads the same payload as
 * `/boundaries.json`, through the same cache, and exits 1 on any violation or
 * on anything wrong with `.dissect/domains.json`. A broken rules file must not
 * pass as "no rules": that is the one way a check can fail by succeeding.
 */
class CheckCommand extends Command
{
    protected $signature = 'dissect:check
        {--files=* : Only report what these files break: relative to the app root, or absolute. Repeat it, or separate with commas}';

    protected $description = 'Fail when a domain breaks the boundary rule in .dissect/domains.json';

    public function handle(Surfaces $surfaces, DomainFile $file, SourceFiles $sources, ContextResolver $resolver): int
    {
        try {
            $payload = $surfaces->export('boundaries');
        } catch (Throwable $e) {
            $this->components->error("Could not check the boundaries: {$e->getMessage()}");

            return self::FAILURE;
        }

        $problems = [...$file->problems(), ...$payload['problems']];
        $violations = $payload['violations'];

        // The whole app is still read: whether Customer may name Invoice
        // depends on which domain Invoice is in, wherever it lives. Only what
        // is reported narrows, so a hook hears about the file it just wrote.
        $only = $this->only($sources, $resolver->root());

        if ($only !== null) {
            $violations = array_values(array_filter($violations, fn (array $v) => isset($only[$v['file']])));
            $payload['unchecked'] = array_values(array_filter($payload['unchecked'], fn (string $f) => isset($only[$f])));
        }

        $this->report($payload, $problems, $violations);

        return $problems === [] && $violations === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $problems
     * @param  array<int, array<string, mixed>>  $violations
     */
    protected function report(array $payload, array $problems, array $violations): void
    {
        foreach ($problems as $problem) {
            $this->entry('red', 'domains.json', $problem);
        }

        foreach ($payload['unchecked'] as $file) {
            $this->entry('yellow', 'not checked', "{$file} does not parse.");
        }

        foreach ($violations as $violation) {
            $this->entry('red', $violation['file'].':'.($violation['lines'][0] ?? 0), $violation['message']);
        }

        $rules = count($payload['rules']);

        if (! $payload['enabled']) {
            $this->components->info('Domain detection is switched off (dissect.domains.enabled), so there is nothing to check.');
        } elseif ($rules === 0 && $problems === []) {
            $this->components->info('No boundary rules in .dissect/domains.json, so there is nothing to check.');
        } elseif ($violations !== []) {
            $this->components->error(count($violations).' '.(count($violations) === 1 ? 'violation' : 'violations').' of '.$this->rules($rules).'.');
        } elseif ($problems !== []) {
            $this->components->error('domains.json has '.(count($problems) === 1 ? 'a problem' : 'problems').', so not every rule was checked.');
        } else {
            $this->components->info('No violations of '.$this->rules($rules).($this->option('files') !== [] ? ' in the files given' : '').'.');
        }
    }

    /**
     * The files asked about, as the payload names them (relative to the app
     * root, forward slashes), or null for every file.
     *
     * @return array<string, true>|null
     */
    protected function only(SourceFiles $sources, string $root): ?array
    {
        $given = [];

        foreach ((array) $this->option('files') as $option) {
            foreach (explode(',', (string) $option) as $path) {
                if (trim($path) !== '') {
                    $given[] = trim($path);
                }
            }
        }

        if ($given === [] && $this->option('files') === []) {
            return null;
        }

        $only = [];

        foreach ($given as $path) {
            $absolute = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$path;

            // A file just deleted has no real path; it still names itself.
            $only[$sources->relative(realpath($absolute) ?: $absolute)] = true;
        }

        return $only;
    }

    protected function rules(int $count): string
    {
        return $count.' '.($count === 1 ? 'rule' : 'rules');
    }

    /**
     * A coloured label and its text. Only the colour is markup: a message
     * quotes class names, whose backslashes must print as they are.
     */
    protected function entry(string $color, string $label, string $text): void
    {
        $this->output->writeln("<fg={$color}>".OutputFormatter::escape($label).'</> '.OutputFormatter::escape($text));
    }
}
