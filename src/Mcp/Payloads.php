<?php

namespace KdrDev\Dissect\Mcp;

use Illuminate\Support\Facades\Artisan;
use KdrDev\Dissect\Support\Surfaces;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * The payloads an MCP tool answers from, as they are on disk right now.
 *
 * An MCP server is one PHP process that lives as long as the agent's session.
 * PHP cannot unload a class, so a model that process has already reflected
 * keeps its old relations however often it is edited. Each call therefore
 * reads its payload from a fresh `dissect:export`, the same way the page reads
 * a fresh request. The exporters' fingerprint caches keep that cheap when
 * nothing changed.
 *
 * In process is for tests, where the app under test is the one already booted.
 */
final class Payloads
{
    /**
     * @param  array<int, string>|null  $artisan  The command that starts artisan, or null to read in process.
     */
    protected function __construct(
        protected ?array $artisan,
        protected ?Surfaces $surfaces,
        protected string $cwd,
        protected float $timeout,
        protected array $env = [],
    ) {}

    /**
     * @param  array<int, string>  $artisan
     * @param  array<string, string|false>  $env  Over the inherited environment; false leaves one out. See LoadedEnvironment.
     */
    public static function fresh(array $artisan, string $cwd, float $timeout = 120, array $env = []): self
    {
        return new self($artisan, null, $cwd, $timeout, $env);
    }

    public static function inProcess(Surfaces $surfaces): self
    {
        return new self(null, $surfaces, '', 0);
    }

    /** @param  array<int, string>  $arguments */
    protected function process(array $arguments): Process
    {
        return new Process([...(array) $this->artisan, ...$arguments, '--no-ansi', '--no-interaction'], $this->cwd, $this->env, timeout: $this->timeout);
    }

    /** @return array<string, mixed> */
    public function get(string $surface): array
    {
        if ($this->surfaces !== null) {
            return $this->surfaces->export($surface);
        }

        return $this->run($surface);
    }

    /**
     * Every surface by name, read once.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        if ($this->surfaces !== null) {
            return array_combine(Surfaces::NAMES, array_map($this->surfaces->export(...), Surfaces::NAMES));
        }

        return $this->run('all');
    }

    /** @return array<string, mixed> */
    /**
     * What `dissect:check --format=json` says, for these files or all of
     * them. Read fresh like every payload, so the rules and the code are
     * both as they are now.
     *
     * @param  array<int, string>  $files
     * @return array{passed: bool, rules: array<int, array<string, mixed>>, violations: array<int, array<string, mixed>>, problems: array<int, string>, unchecked: array<int, string>}
     */
    public function check(array $files = []): array
    {
        $options = array_map(fn (string $file) => '--files='.$file, $files);

        if ($this->surfaces !== null) {
            Artisan::call('dissect:check', ['--format' => 'json', '--files' => $files]);

            return $this->decode(Artisan::output(), 'dissect:check');
        }

        $process = $this->process(['dissect:check', '--format=json', ...$options]);

        $process->run();

        // 1 is a verdict, not a failure: something broke a rule.
        if (! in_array($process->getExitCode(), [0, 1], true)) {
            $said = trim($process->getOutput()."\n".$process->getErrorOutput());

            throw new RuntimeException('dissect:check failed'.($said === '' ? '.' : ': '.$said));
        }

        return $this->decode($process->getOutput(), 'dissect:check');
    }

    /**
     * What `dissect:diff --format=json` says against a baseline: read fresh,
     * so the app is compared as it is now.
     *
     * @return array{against: string, summary: array<string, int>, changes: array<int, array<string, mixed>>}
     */
    public function changes(string $against): array
    {
        if ($this->surfaces !== null) {
            $code = Artisan::call('dissect:diff', ['--format' => 'json', '--against' => $against]);
            $output = Artisan::output();
        } else {
            $process = $this->process(['dissect:diff', '--format=json', '--against='.$against]);

            $process->run();
            $code = $process->getExitCode();
            $output = $code === 0 ? $process->getOutput() : trim($process->getOutput()."\n".$process->getErrorOutput());
        }

        // A baseline it cannot read is said in words, and passed on as such.
        if ($code !== 0) {
            throw new RuntimeException(trim(preg_replace('/^\s*ERROR\s+/m', '', $output) ?? $output));
        }

        return $this->decode($output, 'dissect:diff');
    }

    /** @return array<string, mixed> */
    protected function decode(string $output, string $command): array
    {
        $payload = json_decode($output, true);

        if (! is_array($payload)) {
            throw new RuntimeException($command.' did not print JSON. Is something in the app writing to the console while it boots?');
        }

        return $payload;
    }

    protected function run(string $surface): array
    {
        $process = $this->process(['dissect:export', $surface]);

        $process->run();

        if (! $process->isSuccessful()) {
            $said = trim($process->getOutput()."\n".$process->getErrorOutput());

            throw new RuntimeException('dissect:export '.$surface.' failed'.($said === '' ? '.' : ': '.$said));
        }

        return $this->decode($process->getOutput(), 'dissect:export '.$surface);
    }
}
