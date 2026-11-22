<?php

namespace KdrDev\Dissect\Mcp;

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
    ) {}

    /** @param  array<int, string>  $artisan */
    public static function fresh(array $artisan, string $cwd, float $timeout = 120): self
    {
        return new self($artisan, null, $cwd, $timeout);
    }

    public static function inProcess(Surfaces $surfaces): self
    {
        return new self(null, $surfaces, '', 0);
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
    protected function run(string $surface): array
    {
        $process = new Process(
            [...(array) $this->artisan, 'dissect:export', $surface, '--no-ansi', '--no-interaction'],
            $this->cwd,
            timeout: $this->timeout,
        );

        $process->run();

        if (! $process->isSuccessful()) {
            $said = trim($process->getOutput()."\n".$process->getErrorOutput());

            throw new RuntimeException('dissect:export '.$surface.' failed'.($said === '' ? '.' : ': '.$said));
        }

        $payload = json_decode($process->getOutput(), true);

        if (! is_array($payload)) {
            throw new RuntimeException('dissect:export '.$surface.' did not print JSON. Is something in the app writing to the console while it boots?');
        }

        return $payload;
    }
}
