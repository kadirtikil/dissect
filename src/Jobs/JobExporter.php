<?php

namespace KdrDev\Dissect\Jobs;

use KdrDev\Dissect\Routes\RouteFingerprint;
use Throwable;

/**
 * Builds the job list — the third surface, and the one that says what happens
 * *after* a request has been answered.
 *
 * The graph says what the data looks like and the endpoint list says how you
 * reach it. Neither can answer the question a queue raises: this endpoint
 * returned 202, so what is going to run, where, and carrying what. That is one
 * join in each direction — `models` to the graph, `dispatched_by[].route` to
 * the endpoint list — and building those two is most of what this class is for.
 *
 * Orchestration only. Finding the classes is {@see JobDiscovery}, reading one
 * is {@see JobInspector}, finding the call sites is {@see DispatchScanner}.
 */
class JobExporter
{
    public function __construct(
        protected JobDiscovery $discovery,
        protected JobInspector $inspector,
        protected DispatchScanner $scanner,
        protected RouteMap $routes,
        protected RouteFingerprint $fingerprint,
    ) {}

    /**
     * @return array{jobs: array<int, array<string, mixed>>, generated_at: string}
     */
    public function export(): array
    {
        $classes = $this->discovery->all();

        // One scan of the source tree for every job, rather than one per job:
        // the walk is the expensive part and the answer for all of them is in
        // the same files.
        $sites = $this->scanner->sites($classes);
        $jobs = [];

        foreach ($classes as $class) {
            $described = $this->describe($class, $sites[$class] ?? []);

            if ($described !== null) {
                $jobs[] = $described;
            }
        }

        // By the name the list shows, which is the order it reads in.
        usort($jobs, fn ($a, $b) => [$a['name'], $a['class']] <=> [$b['name'], $b['class']]);

        return [
            'jobs' => $jobs,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Cheap change signal.
     *
     * Deliberately the same mechanism as the route half — a file stat over
     * configured directories — because it is the same kind of question. A job
     * can change because the job class changed, or because a controller started
     * dispatching it, and both are source files under the watched paths. See
     * {@see RouteFingerprint} for why it is coarse in only one direction.
     */
    public function fingerprint(): string
    {
        return $this->fingerprint->signal();
    }

    /**
     * One job, or null if it could not be read at all.
     *
     * A class that cannot be reflected costs its own row and nothing else — the
     * same rule the graph and the endpoint list follow.
     *
     * @param  array<int, array<string, mixed>>  $sites
     * @return array<string, mixed>|null
     */
    protected function describe(string $class, array $sites): ?array
    {
        try {
            $job = $this->inspector->describe($class);
        } catch (Throwable) {
            return null;
        }

        if ($job === null) {
            return null;
        }

        $sites = $this->linked($sites);

        return [
            ...$job,
            ...$this->queue($job, $sites),
            'dispatched_by' => $sites,
            'models' => $this->models($job['payload']),
        ];
    }

    /**
     * Each dispatch site with the endpoint it belongs to, where there is one.
     *
     * @param  array<int, array<string, mixed>>  $sites
     * @return array<int, array<string, mixed>>
     */
    protected function linked(array $sites): array
    {
        foreach ($sites as $index => $site) {
            $sites[$index]['route'] = $this->routes->forContext($site['context'] ?? null);
        }

        return $sites;
    }

    /**
     * Which queue this job actually lands on.
     *
     * What the class says about itself wins — a `$queue` property, or the
     * `$this->onQueue('…')` that a job using the `Queueable` trait has to write
     * instead. When it says nothing, the dispatch sites are the next best source — `dispatch()->onQueue('mail')`
     * is a queue somebody chose just as deliberately, it is simply written
     * somewhere else.
     *
     * Two sites naming two different queues is reported as `mixed` rather than
     * resolved. Picking one would be inventing a fact, and "this job goes to
     * different queues depending on who dispatches it" is worth knowing on its
     * own.
     *
     * @param  array<string, mixed>  $job
     * @param  array<int, array<string, mixed>>  $sites
     * @return array{queue: string|null, queue_source: string}
     */
    protected function queue(array $job, array $sites): array
    {
        if ($job['queue'] !== null) {
            // The class named it, whether as a property or in its constructor.
            // Which of those it was is the inspector's finding, not this one's
            // to restate.
            return ['queue' => $job['queue'], 'queue_source' => $job['queue_source']];
        }

        $named = array_values(array_unique(array_filter(array_column($sites, 'queue'))));

        return match (count($named)) {
            0 => ['queue' => null, 'queue_source' => 'default'],
            1 => ['queue' => $named[0], 'queue_source' => 'dispatch'],
            default => ['queue' => null, 'queue_source' => 'mixed'],
        };
    }

    /**
     * Models this job carries, deduplicated and sorted.
     *
     * The join to the graph, and the same class-basename ids `schema.json` uses
     * as node keys — the field the endpoint list already carries under the same
     * name, so both surfaces address the graph identically.
     *
     * @param  array<int, array{model: string|null}>  $payload
     * @return array<int, string>
     */
    protected function models(array $payload): array
    {
        $models = array_values(array_unique(array_filter(array_column($payload, 'model'))));
        sort($models);

        return $models;
    }
}
