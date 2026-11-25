<?php

namespace KdrDev\Dissect\Console;

use Illuminate\Console\Command;
use KdrDev\Dissect\Architecture\ContextResolver;
use KdrDev\Dissect\Architecture\DomainFile;
use KdrDev\Dissect\Architecture\SourceFiles;
use KdrDev\Dissect\Support\Path;
use KdrDev\Dissect\Support\Surfaces;
use KdrDev\Dissect\Digest\Markdown;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
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
        {--files=* : Only report what these files break: relative to the app root, or absolute. Repeat it, or separate with commas}
        {--format=text : text, json for a script, or github for annotations on a pull request}
        {--hook : Run as a Claude Code PostToolUse hook: read the edited file from stdin, stay quiet unless it breaks a rule}';

    protected $description = 'Fail when a domain breaks the boundary rule in .dissect/domains.json';

    /** Exit code that makes Claude Code show stderr to the agent after an edit. */
    public const HOOK_FEEDBACK = 2;

    public function handle(Surfaces $surfaces, DomainFile $file, SourceFiles $sources, ContextResolver $resolver, Markdown $markdown): int
    {
        $format = (string) $this->option('format');
        $hook = (bool) $this->option('hook');
        $rulesFile = (string) config('dissect.domains.file', base_path('.dissect/domains.json'));
        $given = $this->given();

        if ($hook) {
            $edited = $this->edited();

            // Not a file edit, or not PHP: nothing this can say about it.
            if ($edited === null || ! $this->checks($edited, $rulesFile)) {
                return self::SUCCESS;
            }

            // An edit to the rules themselves is checked against the whole app.
            $given = $this->same($edited, $rulesFile) ? [] : [$edited];
        }

        if (! in_array($format, ['text', 'json', 'github'], true)) {
            $this->components->error("dissect:check cannot write \"{$format}\". Pick one of: text, json, github.");

            return self::INVALID;
        }

        try {
            $payload = $surfaces->export('boundaries');
        } catch (Throwable $e) {
            // A hook that cannot run must not stop the agent: exit 1 shows
            // the user what went wrong and leaves the edit alone. Claude
            // Code shows a hook's stderr, so as a hook it is said there.
            $message = "Could not check the boundaries: {$e->getMessage()}";

            $hook
                ? $this->output->getErrorStyle()->writeln($message, OutputInterface::OUTPUT_RAW)
                : $this->components->error($message);

            return self::FAILURE;
        }

        $problems = [...$file->problems(), ...$payload['problems']];
        $violations = $payload['violations'];

        // The whole app is still read: whether Customer may name Invoice
        // depends on which domain Invoice is in, wherever it lives. Only what
        // is reported narrows, so a hook hears about the file it just wrote.
        $only = $given === null ? null : $this->only($given, $sources, $resolver->root());

        if ($only !== null) {
            $violations = array_values(array_filter($violations, fn (array $v) => isset($only[$v['file']])));
            $payload['unchecked'] = array_values(array_filter($payload['unchecked'], fn (string $f) => isset($only[$f])));
        }

        $passed = $problems === [] && $violations === [];

        if ($hook) {
            if ($passed) {
                return self::SUCCESS;
            }

            $this->output->getErrorStyle()->write($markdown->check([
                'passed' => $passed,
                'rules' => $payload['rules'],
                'violations' => $violations,
                'problems' => $problems,
                'unchecked' => $payload['unchecked'],
            ]), false, OutputInterface::OUTPUT_RAW);

            return self::HOOK_FEEDBACK;
        }

        if ($format === 'json') {
            $this->json($payload, $problems, $violations, $passed);
        } elseif ($format === 'github') {
            $this->github($payload, $problems, $violations, $sources->relative($rulesFile));
            $this->summary($payload, $problems, $violations);
        } else {
            $this->report($payload, $problems, $violations);
        }

        return $passed ? self::SUCCESS : self::FAILURE;
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

        $this->summary($payload, $problems, $violations);
    }

    /**
     * One line saying how it went.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $problems
     * @param  array<int, array<string, mixed>>  $violations
     */
    protected function summary(array $payload, array $problems, array $violations): void
    {
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
     * The verdict and everything behind it, for a script.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $problems
     * @param  array<int, array<string, mixed>>  $violations
     */
    protected function json(array $payload, array $problems, array $violations, bool $passed): void
    {
        $this->output->write(json_encode([
            'passed' => $passed,
            'rules' => $payload['rules'],
            'violations' => $violations,
            'problems' => $problems,
            'unchecked' => $payload['unchecked'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n", false, OutputInterface::OUTPUT_RAW);
    }

    /**
     * Workflow commands GitHub Actions turns into annotations on the lines
     * of the pull request's diff.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $problems
     * @param  array<int, array<string, mixed>>  $violations
     */
    protected function github(array $payload, array $problems, array $violations, string $rulesFile): void
    {
        foreach ($problems as $problem) {
            $this->annotate('error', ['file' => $rulesFile, 'title' => 'dissect: domains.json'], $problem);
        }

        foreach ($payload['unchecked'] as $file) {
            $this->annotate('warning', ['file' => $file, 'title' => 'dissect: not checked'], 'This file does not parse, so its boundaries were not checked.');
        }

        foreach ($violations as $violation) {
            $this->annotate('error', [
                'file' => $violation['file'],
                'line' => (string) ($violation['lines'][0] ?? 1),
                'title' => 'dissect: '.$violation['domain'].' '.$violation['rule'],
            ], $violation['message']);
        }
    }

    /** @param  array<string, string>  $properties */
    protected function annotate(string $level, array $properties, string $message): void
    {
        $escape = fn (string $value) => str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $value);
        $property = fn (string $value) => str_replace([':', ','], ['%3A', '%2C'], $escape($value));

        $pairs = array_map(fn (string $key, string $value) => $key.'='.$property($value), array_keys($properties), $properties);

        $this->output->write('::'.$level.' '.implode(',', $pairs).'::'.$escape($message)."\n", false, OutputInterface::OUTPUT_RAW);
    }

    /**
     * The paths given with --files, split on commas; null when the option
     * was not given at all, so an empty `--files=` asks about no file.
     *
     * @return array<int, string>|null
     */
    protected function given(): ?array
    {
        if ($this->option('files') === []) {
            return null;
        }

        $given = [];

        foreach ((array) $this->option('files') as $option) {
            foreach (explode(',', (string) $option) as $path) {
                if (trim($path) !== '') {
                    $given[] = trim($path);
                }
            }
        }

        return $given;
    }

    /**
     * The given files as the payload names them: relative to the app root,
     * forward slashes.
     *
     * @param  array<int, string>  $given
     * @return array<string, true>|null  Null when every file is wanted.
     */
    protected function only(array $given, SourceFiles $sources, string $root): ?array
    {
        if ($given === [] && $this->option('files') === []) {
            return null;
        }

        $only = [];

        foreach ($given as $path) {
            $absolute = Path::isAbsolute($path) ? $path : rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$path;

            // A file just deleted has no real path; it still names itself.
            $only[$sources->relative(realpath($absolute) ?: $absolute)] = true;
        }

        return $only;
    }

    /**
     * The file a Claude Code hook says was just edited, from the event on
     * stdin, or null when there is none. Write and Edit both send an absolute
     * `tool_input.file_path`.
     */
    protected function edited(): ?string
    {
        $stream = $this->input instanceof StreamableInputInterface ? $this->input->getStream() : null;
        $stream ??= fopen('php://stdin', 'r');
        $event = $stream === false ? null : json_decode((string) stream_get_contents($stream), true);
        $path = is_array($event) ? ($event['tool_input']['file_path'] ?? null) : null;

        return is_string($path) && $path !== '' ? $path : null;
    }

    /** A PHP file, or the rules file itself. */
    protected function checks(string $edited, string $rulesFile): bool
    {
        return str_ends_with(strtolower($edited), '.php') || $this->same($edited, $rulesFile);
    }

    protected function same(string $a, string $b): bool
    {
        return (realpath($a) ?: $a) === (realpath($b) ?: $b);
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
