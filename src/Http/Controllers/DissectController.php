<?php

namespace KdrDev\Dissect\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use KdrDev\Dissect\Exceptions\StateFileException;
use KdrDev\Dissect\Jobs\JobExporter;
use KdrDev\Dissect\LayoutRepository;
use KdrDev\Dissect\Queue\QueueSnapshot;
use KdrDev\Dissect\Routes\RouteExporter;
use KdrDev\Dissect\SchemaExporter;
use KdrDev\Dissect\ViewRepository;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DissectController
{
    /** Computed at most once per request — index() needs it twice. */
    protected ?string $fingerprint = null;

    protected ?string $routeFingerprint = null;

    protected ?string $jobFingerprint = null;

    public function __construct(
        protected SchemaExporter $exporter,
        protected RouteExporter $routes,
        protected JobExporter $jobs,
        protected QueueSnapshot $queue,
        protected LayoutRepository $layout,
        protected ViewRepository $views,
    ) {}

    /** The single page. Schema and layout are inlined, so it makes no XHR on boot. */
    public function index(): Response
    {
        $devServer = $this->devServer();

        return response()->view('dissect::app', [
            // Assembled here rather than in the template: the HMR client path
            // contains a token Blade compiles as a directive.
            'devClient' => $devServer ? $devServer.'/@vite/client' : null,
            'devEntry' => $devServer ? $devServer.'/resources/js/main.ts' : null,
            'schema' => $this->schema(),
            'layout' => $this->layout->get(),
            'views' => $this->views->get(),
            'fingerprint' => $this->fingerprint(),
            // When set, assets come from a running Vite dev server rather than
            // dist/ — see config('dissect.dev_server').
            'devServer' => $devServer,
        ]);
    }

    /**
     * Cheap polling endpoint. Returns a hash of the model files plus the
     * applied-migration state, so the page can detect both an edited relation
     * and a migration that has actually run — without rebuilding the graph on
     * every check, and with no file watcher process required.
     */
    public function fingerprintJson(Request $request): JsonResponse
    {
        $payload = ['fingerprint' => $this->fingerprint()];

        // The route signal stats a much wider set of files than the model one,
        // so it is only computed for a client that has opened the endpoint list
        // and has something to do with the answer. A page that never leaves the
        // graph pays nothing for it.
        if ($request->boolean('routes')) {
            $payload['routes'] = $this->routeFingerprint();
        }

        // Same bargain for the job list, and worth making separately: the two
        // walk overlapping directories but a session sitting on one of them
        // should not pay for the other.
        if ($request->boolean('jobs')) {
            $payload['jobs'] = $this->jobFingerprint();
        }

        // The whole point is to see the current value; a cached 200 would make
        // the page believe nothing had changed for as long as the browser
        // decided to hold on to it.
        return response()
            ->json($payload)
            ->header('Cache-Control', 'no-store');
    }

    public function schemaJson(): JsonResponse
    {
        return response()
            ->json($this->schema())
            ->header('Cache-Control', 'no-store');
    }

    /**
     * The endpoint list.
     *
     * Fetched when the routes surface is first opened rather than inlined
     * alongside the schema: reflecting every controller costs more than
     * inspecting every model, and the page's promise of booting without a round
     * trip is about the graph, which is what it opens on.
     */
    public function routesJson(): JsonResponse
    {
        // The signal travels with the payload it describes. Nothing inlines it
        // at render time the way the schema fingerprint is inlined, so without
        // this the client would have no baseline to poll against.
        return response()
            ->json($this->routes() + ['fingerprint' => $this->routeFingerprint()])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * The job list.
     *
     * Fetched on first open like the endpoint list, and for a sharper version
     * of the same reason: describing jobs means parsing every file under the
     * watched paths looking for dispatch sites, which is the widest walk this
     * package does.
     */
    public function jobsJson(): JsonResponse
    {
        return response()
            ->json($this->jobs() + ['fingerprint' => $this->jobFingerprint()])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * What is on the queue right now.
     *
     * The one endpoint here that is **never cached**, because it is the one
     * thing dissect shows that is not derived from source. A fingerprint cannot
     * describe runtime state, and a queue that looked the same for an hour
     * because a cache said so would be worse than no surface at all — so this
     * is read on every request and the client polls it.
     */
    public function queueJson(Request $request): JsonResponse
    {
        $connection = $request->string('connection')->toString();

        return response()
            ->json($this->queue->take($connection === '' ? null : $connection, $this->rows()))
            ->header('Cache-Control', 'no-store');
    }

    public function saveLayout(Request $request): JsonResponse
    {
        $positions = $request->input('positions');

        if (! is_array($positions)) {
            return response()->json(['ok' => false, 'message' => 'positions must be an object'], 422);
        }

        try {
            return response()->json(['ok' => true, 'saved' => $this->layout->put($positions)]);
        } catch (StateFileException $e) {
            return $this->refused($e);
        }
    }

    public function viewsJson(): JsonResponse
    {
        return response()
            ->json($this->views->get())
            ->header('Cache-Control', 'no-store');
    }

    public function saveViews(Request $request): JsonResponse
    {
        $views = $request->input('views');

        if (! is_array($views)) {
            return response()->json(['ok' => false, 'message' => 'views must be an array'], 422);
        }

        try {
            return response()->json(['ok' => true, 'saved' => $this->views->put($views)]);
        } catch (StateFileException $e) {
            return $this->refused($e);
        }
    }

    /**
     * A write the repository declined to make, because making it would have
     * destroyed what was already in the file.
     *
     * 409 rather than 500: nothing is broken and there is nothing to retry —
     * the file on disk simply is not this version's to rewrite. The page keeps
     * the edit on screen and reports that it was not saved.
     */
    protected function refused(StateFileException $e): JsonResponse
    {
        return response()->json(['ok' => false, 'message' => $e->getMessage()], 409);
    }

    /**
     * Serves the compiled bundle straight from the package.
     *
     * Deliberately not a `vendor:publish` step: the whole promise is that one
     * `composer require` is enough, with nothing to re-publish after an update.
     */
    public function asset(string $file): BinaryFileResponse
    {
        // Allow-list rather than path handling — no user input reaches the
        // filesystem, so ../ traversal is impossible by construction.
        $allowed = [
            'dissect.js' => 'application/javascript',
            'dissect.css' => 'text/css',
        ];

        // The lazily loaded pages are split into their own chunks, which the
        // entry imports by name at runtime. Their names are decided by the
        // build, not by the caller, so they are matched by shape rather than
        // enumerated — still an allow-list: no separator can appear in it.
        //
        // Hyphens and underscores are in the class because Vite names a shared
        // chunk after its contents, and an icon called `arrow-up-right` gets a
        // chunk to match. A pattern narrower than what the build actually emits
        // is a 404 on a page that only loads once somebody visits it.
        $type = $allowed[$file]
            ?? (preg_match('/^dissect-[A-Za-z0-9_-]+\.js$/', $file) === 1
                ? 'application/javascript'
                : null);

        abort_unless($type !== null, 404);

        $path = dirname(__DIR__, 3).'/dist/'.$file;

        abort_unless(is_file($path), 404, 'Asset missing — the package was installed without its build output.');

        return response()->file($path, [
            'Content-Type' => $type,
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    /**
     * Base URL of a Vite dev server, or null for the shipped bundle.
     *
     * Guarded by the same environment check as the routes: pointing a
     * production page at a developer's laptop would be an outage, not a
     * convenience.
     */
    protected function devServer(): ?string
    {
        $url = config('dissect.dev_server');

        if (! is_string($url) || $url === '' || ! app()->environment(['local', 'testing', 'workbench'])) {
            return null;
        }

        return rtrim($url, '/');
    }

    /** Memoised: index() renders it and keys the schema cache with it. */
    protected function fingerprint(): string
    {
        return $this->fingerprint ??= $this->exporter->fingerprint();
    }

    protected function routeFingerprint(): string
    {
        return $this->routeFingerprint ??= $this->routes->fingerprint();
    }

    /**
     * @return array<string, mixed>
     */
    protected function routes(): array
    {
        // Dearer to build than the schema — every controller, form request and
        // resource behind the table gets reflected and parsed — so the cache
        // matters more here, not less.
        return Cache::remember(
            'dissect.routes.'.$this->routeFingerprint(),
            now()->addHour(),
            fn () => $this->routes->export(),
        );
    }

    /**
     * How many jobs to list per section.
     *
     * Clamped rather than trusted: the value is a cap on a query against a
     * table that can be enormous, and a configuration typo should not become a
     * request that reads a million rows.
     */
    protected function rows(): int
    {
        return max(1, min(500, (int) config('dissect.queue.rows', 50)));
    }

    protected function jobFingerprint(): string
    {
        return $this->jobFingerprint ??= $this->jobs->fingerprint();
    }

    /**
     * @return array<string, mixed>
     */
    protected function jobs(): array
    {
        // The dearest of the three. Every file under the watched paths is
        // parsed, not just stat'd, so this wants the cache more than either of
        // the others — and gets exactly the same one.
        return Cache::remember(
            'dissect.jobs.'.$this->jobFingerprint(),
            now()->addHour(),
            fn () => $this->jobs->export(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function schema(): array
    {
        // Inspecting every model does reflection plus a schema query each; the
        // fingerprint means that work happens once per model edit or applied
        // migration rather than once per request.
        return Cache::remember(
            'dissect.schema.'.$this->fingerprint(),
            now()->addHour(),
            fn () => $this->exporter->export(),
        );
    }
}
