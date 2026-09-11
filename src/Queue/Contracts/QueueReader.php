<?php

namespace KdrDev\Dissect\Queue\Contracts;

/**
 * Reads what is actually sitting on a queue right now.
 *
 * Everything else dissect shows is derived from source and cached against a
 * file-stat signal. This is the one thing that is not: it changes second to
 * second, no fingerprint can describe it, and what can be read at all depends
 * entirely on the driver. Each implementation answers for one driver and is
 * only ever built when that driver is the one in play.
 *
 * The three tenses are separate methods rather than one call with a flag,
 * because they are separate reads against separate structures — a list, a
 * sorted set and a table scan — and the surface asks for them independently.
 */
interface QueueReader
{
    /**
     * Every queue this connection knows about, with its depth.
     *
     * Counting is cheap where listing is not, so this is what the surface
     * leads with: the shape of the backlog before any of it is fetched.
     *
     * @return array<int, array{name: string, waiting: int, delayed: int, reserved: int}>
     */
    public function queues(): array;

    /**
     * Jobs waiting to be picked up — the "right now" tense.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function waiting(int $limit): array;

    /**
     * Jobs a worker has taken and not yet finished. Also "right now", but a
     * different thing to know: a reserved job that has been reserved a long
     * time is a job that is stuck.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function reserved(int $limit): array;

    /**
     * Jobs held until a time that has not arrived — the "going to be" tense.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function delayed(int $limit): array;
}
