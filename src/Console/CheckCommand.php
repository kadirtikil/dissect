<?php

namespace KdrDev\Dissect\Console;

use Illuminate\Console\Command;
use KdrDev\Dissect\Architecture\DomainFile;
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
    protected $signature = 'dissect:check';

    protected $description = 'Fail when a domain breaks the boundary rule in .dissect/domains.json';

    public function handle(Surfaces $surfaces, DomainFile $file): int
    {
        try {
            $payload = $surfaces->export('boundaries');
        } catch (Throwable $e) {
            $this->components->error("Could not check the boundaries: {$e->getMessage()}");

            return self::FAILURE;
        }

        $problems = [...$file->problems(), ...$payload['problems']];
        $violations = $payload['violations'];

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
            $this->components->info('No violations of '.$this->rules($rules).'.');
        }
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
