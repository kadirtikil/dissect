<?php

namespace KdrDev\Dissect\Queue;

use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Str;
use KdrDev\Dissect\Queue\Contracts\QueueReader;
use Throwable;

/**
 * Reads a Redis-backed queue.
 *
 * Three structures rather than one table: a list of payloads waiting to be
 * popped, and two sorted sets — delayed, scored by when the job becomes
 * available, and reserved, scored by when the reservation expires.
 *
 * **Key names come from the framework, not from here.** `RedisQueue::getQueue()`
 * is what the queue itself uses, so a layout change is a layout change in one
 * place. The one thing it does not cover is a clustered connection, where
 * Laravel wraps the name in hash-tag braces through a method that is not public
 * — a cluster is reported as unreadable rather than read against keys that may
 * not be the right ones.
 *
 * **Scores are read through `eval`.** `WITHSCORES` is spelled differently by
 * phpredis and predis; a Lua script is the one form both connections take
 * identically, which is why the framework's own queue reaches for it too.
 */
class RedisQueueReader implements QueueReader
{
    /** Returns members and scores interleaved, on either client. */
    protected const ZRANGE = "return redis.call('zrange', KEYS[1], ARGV[1], ARGV[2], 'WITHSCORES')";

    public function __construct(
        protected RedisQueue $queue,
        protected PayloadDecoder $payloads,
        protected string $default = 'default',
    ) {}

    /**
     * @return array<int, array{name: string, waiting: int, delayed: int, reserved: int}>
     */
    public function queues(): array
    {
        $queues = [];

        foreach ($this->names() as $name) {
            $key = $this->queue->getQueue($name);

            $queues[] = [
                'name' => $name,
                'waiting' => (int) $this->connection()->llen($key),
                'delayed' => (int) $this->connection()->zcard($key.':delayed'),
                'reserved' => (int) $this->connection()->zcard($key.':reserved'),
            ];
        }

        return $queues;
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function waiting(int $limit): array
    {
        $rows = [];
        $total = 0;

        foreach ($this->names() as $name) {
            $key = $this->queue->getQueue($name);
            $total += (int) $this->connection()->llen($key);

            $remaining = $this->remaining($rows, $limit);

            if ($remaining <= 0) {
                continue;
            }

            // Laravel pushes with rpush and pops with lpop, so index 0 is the
            // next job off this queue — the list reads in the order it will run.
            foreach ((array) $this->connection()->lrange($key, 0, $remaining - 1) as $payload) {
                $rows[] = $this->describe($payload, $name);
            }
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function reserved(int $limit): array
    {
        // The score is when the reservation expires — after which the job is
        // released back and tried again.
        return $this->scored(':reserved', 'reserved_until', $limit);
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function delayed(int $limit): array
    {
        // The score is when the job becomes available. That is the whole
        // "going to be in the queue" tense, written down.
        return $this->scored(':delayed', 'available_at', $limit);
    }

    /**
     * One of the two sorted sets, across every queue.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    protected function scored(string $suffix, string $field, int $limit): array
    {
        $rows = [];
        $total = 0;

        foreach ($this->names() as $name) {
            $key = $this->queue->getQueue($name).$suffix;
            $total += (int) $this->connection()->zcard($key);

            $remaining = $this->remaining($rows, $limit);

            if ($remaining <= 0) {
                continue;
            }

            $flat = (array) $this->connection()->eval(self::ZRANGE, 1, $key, 0, $remaining - 1);

            // Members and scores arrive interleaved, soonest first.
            for ($i = 0; $i + 1 < count($flat); $i += 2) {
                $rows[] = $this->describe($flat[$i], $name) + [
                    $field => date(DATE_ATOM, (int) $flat[$i + 1]),
                ];
            }
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Every queue with something on it, plus the connection's default.
     *
     * Redis has no register of queues — a queue exists exactly as long as a key
     * does — so the names are read off the keyspace. The default is added
     * whether or not it has a key, because "the queue your workers are draining
     * is empty" is an answer, and its absence from a list is not.
     *
     * @return array<int, string>
     */
    protected function names(): array
    {
        $names = [$this->default];

        try {
            foreach ((array) $this->connection()->keys('queues:*') as $key) {
                // Tolerates whatever prefix the Redis connection is configured
                // with, and the braces a cluster adds — the same parsing the
                // framework does when it asks itself this question.
                $name = trim(Str::between((string) $key, 'queues:', ':'), '{}');

                if ($name !== '') {
                    $names[] = $name;
                }
            }
        } catch (Throwable) {
            // A connection that will not answer a KEYS scan still answers for
            // the default queue, which is the one that matters most.
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  array<int, mixed>  $rows
     */
    protected function remaining(array $rows, int $limit): int
    {
        return $limit - count($rows);
    }

    /**
     * @return array<string, mixed>
     */
    protected function describe(mixed $payload, string $queue): array
    {
        $decoded = $this->payloads->decode(is_string($payload) ? $payload : null);

        return [
            // Redis has no row id; the payload's uuid is what identifies a job,
            // and a payload written before Laravel added them has nothing else.
            'id' => $decoded['uuid'] ?? null,
            ...$decoded,
            'queue' => $queue,
            'queued_at' => $decoded['pushed_at'],
        ];
    }

    protected function connection(): mixed
    {
        return $this->queue->getConnection();
    }
}
