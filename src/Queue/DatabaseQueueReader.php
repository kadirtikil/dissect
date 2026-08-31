<?php

namespace KdrDev\Dissect\Queue;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use KdrDev\Dissect\Queue\Contracts\QueueReader;

/**
 * Reads the `jobs` table.
 *
 * The most legible of the drivers: everything is rows, the three tenses are
 * three predicates over the same table, and counting is an indexed query rather
 * than a scan.
 *
 *  - **waiting** — not reserved, and its time has come
 *  - **reserved** — a worker has it
 *  - **delayed** — not reserved, and `available_at` is still in the future
 *
 * Timestamps are stored as unix integers, which is why they are converted here
 * rather than left to the client: an integer that is sometimes seconds and
 * sometimes a date string is the kind of thing that reads fine until a queue is
 * empty on one of them.
 */
class DatabaseQueueReader implements QueueReader
{
    public function __construct(
        protected ConnectionInterface $database,
        protected PayloadDecoder $payloads,
        protected string $table = 'jobs',
    ) {}

    /**
     * @return array<int, array{name: string, waiting: int, delayed: int, reserved: int}>
     */
    public function queues(): array
    {
        $now = $this->now();

        $rows = $this->query()
            ->selectRaw('queue')
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when reserved_at is not null then 1 else 0 end) as reserved')
            ->selectRaw("sum(case when reserved_at is null and available_at > {$now} then 1 else 0 end) as delayed")
            ->groupBy('queue')
            ->orderBy('queue')
            ->get();

        $queues = [];

        foreach ($rows as $row) {
            $reserved = (int) $row->reserved;
            $delayed = (int) $row->delayed;

            $queues[] = [
                'name' => (string) $row->queue,
                // Derived rather than summed a third time: whatever is neither
                // reserved nor delayed is waiting, by definition.
                'waiting' => (int) $row->total - $reserved - $delayed,
                'delayed' => $delayed,
                'reserved' => $reserved,
            ];
        }

        return $queues;
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function waiting(int $limit): array
    {
        // Oldest first, which is the order a worker will take them in — so the
        // top of the list is the next thing that will happen.
        return $this->read(
            fn (Builder $query) => $query->whereNull('reserved_at')->where('available_at', '<=', $this->now()),
            'available_at',
            'asc',
            $limit,
        );
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function reserved(int $limit): array
    {
        // Longest-held first: a job reserved a long time ago is the one worth
        // looking at, because it is either slow or stuck.
        return $this->read(
            fn (Builder $query) => $query->whereNotNull('reserved_at'),
            'reserved_at',
            'asc',
            $limit,
        );
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function delayed(int $limit): array
    {
        // Soonest first: the front of this list is what the queue becomes next.
        return $this->read(
            fn (Builder $query) => $query->whereNull('reserved_at')->where('available_at', '>', $this->now()),
            'available_at',
            'asc',
            $limit,
        );
    }

    /**
     * @param  callable(Builder): Builder  $constrain
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    protected function read(callable $constrain, string $order, string $direction, int $limit): array
    {
        // Counted separately from the page that is fetched: a queue with
        // 40,000 jobs on it has a depth worth reporting and a first page worth
        // reading, and they are not the same query.
        $total = (int) $constrain($this->query())->count();

        $rows = $constrain($this->query())
            ->orderBy($order, $direction)
            ->limit($limit)
            ->get();

        return [
            'rows' => array_map($this->describe(...), $rows->all()),
            'total' => $total,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function describe(object $row): array
    {
        return [
            'id' => (string) $row->id,
            ...$this->payloads->decode($row->payload ?? null),
            'queue' => (string) $row->queue,
            // The table's own attempt count is authoritative: the envelope's
            // copy is whatever it was when the payload was written.
            'attempts' => (int) $row->attempts,
            'queued_at' => $this->timestamp($row->created_at ?? null),
            'available_at' => $this->timestamp($row->available_at ?? null),
            'reserved_at' => $this->timestamp($row->reserved_at ?? null),
        ];
    }

    protected function query(): Builder
    {
        return $this->database->table($this->table);
    }

    /** Unix seconds, the way the table stores them. */
    protected function now(): int
    {
        return time();
    }

    protected function timestamp(mixed $value): ?string
    {
        return is_numeric($value) ? date(DATE_ATOM, (int) $value) : null;
    }
}
