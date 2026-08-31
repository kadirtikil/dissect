<?php

namespace KdrDev\Dissect\Queue;

/**
 * What is on the queue, right now.
 *
 * The one surface in dissect that is **not** derived from source. Everything
 * else here is a fact about the code, cached against a file-stat signal and
 * true until somebody edits a file. This is runtime state: it changes second to
 * second, no fingerprint can describe it, and it is never cached — the client
 * polls it and the response says `no-store`.
 *
 * Keeping that distinction visible is deliberate. The job list says what *can*
 * be queued; this says what *is*, and the two answer to different clocks.
 *
 * Three tenses, as somebody actually asks about a queue:
 *
 *  - **now** — waiting to be picked up, and reserved by a worker
 *  - **next** — delayed until a time that has not arrived
 *  - **past** — what failed, and what was batched
 *
 * The past is the honest half. Laravel records **nothing** about a job that
 * succeeded — see {@see QueueHistory} — so an empty history means nothing
 * failed, not that nothing ran, and the payload says so in a field rather than
 * leaving the surface to imply it.
 */
class QueueSnapshot
{
    public function __construct(
        protected QueueReaderFactory $readers,
        protected QueueHistory $history,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function take(?string $connection = null, int $limit = 50): array
    {
        $connection = $this->connection($connection);
        $reader = $this->readers->make($connection);

        $base = [
            'connection' => $connection,
            'driver' => $this->readers->driver($connection),
            // Every configured connection, so the surface can offer a switcher.
            // An application with both a database and a Redis queue is ordinary,
            // and only one of them being visible would be the wrong default.
            'connections' => $this->connections(),
            'limit' => $limit,
            // Said on the wire rather than written into the UI: it is a fact
            // about Laravel, and the client should not have to know it.
            'records_completions' => false,
            'read_at' => now()->toIso8601String(),
        ];

        if ($reader === null) {
            return $base + [
                'readable' => false,
                'refusal' => $this->readers->refusal($connection),
                'queues' => [],
                'now' => ['waiting' => $this->empty(), 'reserved' => $this->empty()],
                'next' => ['delayed' => $this->empty()],
                // The history is stored by the application, not by the queue
                // driver, so it is readable even when the queue itself is not —
                // an SQS application still knows what failed.
                'past' => $this->past($limit),
            ];
        }

        return $base + [
            'readable' => true,
            'refusal' => null,
            'queues' => $reader->queues(),
            'now' => [
                'waiting' => $this->section($reader->waiting($limit), $limit),
                'reserved' => $this->section($reader->reserved($limit), $limit),
            ],
            'next' => [
                'delayed' => $this->section($reader->delayed($limit), $limit),
            ],
            'past' => $this->past($limit),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function past(int $limit): array
    {
        return [
            'failed' => $this->section($this->history->failed($limit), $limit),
            'batches' => $this->history->batches($limit),
        ];
    }

    /**
     * One section, with whether there is more of it than was fetched.
     *
     * A queue is a place where the number is often enormous and the first page
     * is what anybody reads, so the two are reported separately rather than the
     * list being taken as the count.
     *
     * @param  array{rows: array<int, array<string, mixed>>, total: int}  $section
     * @return array<string, mixed>
     */
    protected function section(array $section, int $limit): array
    {
        return $section + [
            'truncated' => $section['total'] > count($section['rows']),
            // Only the history can be unreadable on its own; the queue sections
            // carry the key so the client has one shape to render.
            'unreadable' => null,
        ];
    }

    /**
     * @return array{rows: array<int, mixed>, total: int, truncated: bool}
     */
    protected function empty(): array
    {
        return ['rows' => [], 'total' => 0, 'truncated' => false, 'unreadable' => null];
    }

    protected function connection(?string $connection): string
    {
        $configured = $this->connections();

        // A name that is not configured is a name that would throw on the first
        // read; falling back to the default answers the question that was
        // probably meant, rather than a 500.
        return $connection !== null && in_array($connection, $configured, true)
            ? $connection
            : (string) config('queue.default', 'sync');
    }

    /**
     * @return array<int, string>
     */
    protected function connections(): array
    {
        $connections = config('queue.connections');

        return is_array($connections) ? array_keys($connections) : [];
    }
}
