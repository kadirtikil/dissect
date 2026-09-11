<?php

namespace KdrDev\Dissect\Queue;

use Illuminate\Bus\Batch;
use Illuminate\Bus\BatchRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Throwable;

/**
 * What was on the queue.
 *
 * The honest answer is smaller than the question, and the surface says so:
 * **Laravel records nothing at all about a job that succeeded.** It is queued,
 * it runs, it is deleted, and no trace of it is kept anywhere — which is the
 * gap Horizon exists to fill. So "what was in the queue" is what went wrong
 * (the failed job provider) and what was batched (the batch repository), and an
 * empty history means "nothing failed", never "nothing ran".
 *
 * Both sources are read through the framework's own abstractions rather than
 * their tables, so an application that keeps failures in a file or in DynamoDB
 * is described the same way as one using the default table.
 */
class QueueHistory
{
    public function __construct(
        protected Container $container,
        protected PayloadDecoder $payloads,
    ) {}

    /**
     * Jobs that threw, newest first.
     *
     * A store that cannot be read reports **why**, rather than reporting
     * nothing. "No job has failed" and "the failed_jobs table is not there" are
     * different answers, and quietly showing the first for the second is the
     * same lie the surface refuses to tell about an unreadable driver.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int, unreadable: string|null}
     */
    public function failed(int $limit): array
    {
        $failer = $this->resolve(FailedJobProviderInterface::class);

        if ($failer === null) {
            return $this->unreadable('This application has no failed job store configured.');
        }

        try {
            $all = $failer->all();
        } catch (Throwable $e) {
            return $this->unreadable(
                'The failed jobs could not be read: '.$this->reason($e)
            );
        }

        return [
            'rows' => array_map($this->describeFailure(...), array_slice($all, 0, $limit)),
            'total' => count($all),
            'unreadable' => null,
        ];
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, total: int, unreadable: string}
     */
    protected function unreadable(string $reason): array
    {
        return ['rows' => [], 'total' => 0, 'unreadable' => $reason];
    }

    /**
     * The message without the stack trace or the SQL that produced it.
     *
     * A query exception's message carries the whole statement, which is noise
     * in a one-line explanation of why a section is empty.
     */
    protected function reason(Throwable $e): string
    {
        $message = strtok($e->getMessage(), "\n");

        return trim($message === false ? $e::class : (string) strtok($message, '('));
    }

    /**
     * Batches, newest first — the one place a *successful* job leaves a mark,
     * because a batch counts what it has finished even after each job is gone.
     *
     * @return array<int, array<string, mixed>>
     */
    public function batches(int $limit): array
    {
        $batches = $this->resolve(BatchRepository::class);

        if ($batches === null) {
            return [];
        }

        try {
            $found = $batches->get($limit, null);
        } catch (Throwable) {
            return [];
        }

        return array_map($this->describeBatch(...), $found);
    }

    /**
     * @return array<string, mixed>
     */
    protected function describeFailure(object $failure): array
    {
        return [
            'id' => (string) ($failure->id ?? ''),
            ...$this->payloads->decode($this->stringOrNull($failure->payload ?? null)),
            'queue' => $this->stringOrNull($failure->queue ?? null),
            'connection' => $this->stringOrNull($failure->connection ?? null),
            'failed_at' => $this->stringOrNull($failure->failed_at ?? null),
            'exception' => $this->summarise($this->stringOrNull($failure->exception ?? null)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function describeBatch(Batch $batch): array
    {
        return [
            'id' => $batch->id,
            'name' => $batch->name,
            'total' => $batch->totalJobs,
            'pending' => $batch->pendingJobs,
            'failed' => $batch->failedJobs,
            'created_at' => $batch->createdAt?->toIso8601String(),
            'finished_at' => $batch->finishedAt?->toIso8601String(),
            'cancelled_at' => $batch->cancelledAt?->toIso8601String(),
        ];
    }

    /**
     * The first line of a stack trace, which is the exception and its message.
     *
     * The rest is a hundred frames of vendor code: it belongs in the log, not
     * in a list somebody is scanning for what broke.
     */
    protected function summarise(?string $exception): ?string
    {
        if ($exception === null) {
            return null;
        }

        $first = strtok($exception, "\n");

        return $first === false ? null : trim($first);
    }

    /**
     * @template T
     *
     * @param  class-string<T>  $abstract
     * @return T|null
     */
    protected function resolve(string $abstract): mixed
    {
        try {
            // Bound by the framework's own service providers. An application
            // that has somehow not bound one still gets every other section.
            return $this->container->bound($abstract) ? $this->container->make($abstract) : null;
        } catch (Throwable) {
            return null;
        }
    }

    protected function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
