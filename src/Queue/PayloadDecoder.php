<?php

namespace KdrDev\Dissect\Queue;

/**
 * Reads the envelope Laravel wraps around a queued job.
 *
 * **The serialised command is never unserialised.** A queue payload carries
 * `data.command`, which is a serialised instance of the application's own job —
 * restoring it would construct application objects, run their `__wakeup`, and
 * on a `SerializesModels` job hit the database to resolve every model it
 * carries. A viewer that describes a queue must not be able to do any of that,
 * and the JSON envelope around it already answers every question this surface
 * asks.
 *
 * **The payload itself is never reported either.** The envelope is safe to
 * read; the serialised command is somebody's data — argument values, model ids,
 * whatever a constructor was handed — and putting it on a page is a decision
 * nobody made deliberately.
 */
class PayloadDecoder
{
    /**
     * One queued job, described from its envelope.
     *
     * `job` is the field that matters: it is the class name the Jobs surface
     * lists, so a row on a live queue and a row in the job list address the
     * same thing. Laravel writes it as `displayName` — already unwrapped for a
     * queued listener, mailable or notification, where `commandName` would only
     * ever say `CallQueuedListener`.
     *
     * @return array<string, mixed>
     */
    public function decode(?string $payload): array
    {
        $envelope = $this->envelope($payload);

        $class = $this->stringOrNull($envelope['displayName'] ?? null)
            ?? $this->stringOrNull($envelope['data']['commandName'] ?? null);

        return [
            'uuid' => $this->stringOrNull($envelope['uuid'] ?? null),
            'job' => $class,
            'name' => $class === null ? 'Unknown job' : class_basename($class),
            'attempts' => is_int($envelope['attempts'] ?? null) ? $envelope['attempts'] : 0,
            'maxTries' => is_int($envelope['maxTries'] ?? null) ? $envelope['maxTries'] : null,
            // Written by Laravel 11+; absent on a payload queued by an older
            // release, which is a queue this can still describe otherwise.
            'pushed_at' => $this->stringOrNull($envelope['pushedAt'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function envelope(?string $payload): array
    {
        if ($payload === null || $payload === '') {
            return [];
        }

        $decoded = json_decode($payload, true);

        // A payload this cannot read is one row that says less, not a failed
        // page — the same rule a model with no table follows.
        return is_array($decoded) ? $decoded : [];
    }

    protected function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
