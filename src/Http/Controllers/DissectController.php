<?php

namespace KdrDev\Dissect\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use KdrDev\Dissect\Architecture\Boundaries;
use KdrDev\Dissect\Architecture\ClassIndex;
use KdrDev\Dissect\Architecture\DomainMap;
use KdrDev\Dissect\Changes\Baselines;
use KdrDev\Dissect\Changes\Report;
use KdrDev\Dissect\Changes\Snapshot;
use KdrDev\Dissect\Changes\UnreadableSnapshot;
use KdrDev\Dissect\Exceptions\StateFileException;
use KdrDev\Dissect\Jobs\JobExporter;
use KdrDev\Dissect\LayoutRepository;
use KdrDev\Dissect\ProviderTree\ProviderExporter;
use KdrDev\Dissect\Queue\QueueSnapshot;
use KdrDev\Dissect\Routes\RouteExporter;
use KdrDev\Dissect\SchemaExporter;
use KdrDev\Dissect\Support\PayloadCache;
use KdrDev\Dissect\ViewRepository;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class DissectController
{
    /** Computed at most once per request — index() needs it twice. */
    protected ?string $fingerprint = null;

    protected ?string $routeFingerprint = null;

    protected ?string $jobFingerprint = null;

    protected ?string $providerFingerprint = null;

    protected ?string $domainFingerprint = null;

    public function __construct(
        protected SchemaExporter $exporter,
        protected RouteExporter $routes,
        protected JobExporter $jobs,
        protected ProviderExporter $providers,
        protected QueueSnapshot $queue,
        protected LayoutRepository $layout,
        protected ViewRepository $views,
        protected DomainMap $domains,
        protected PayloadCache $cache,
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
            'domains' => $this->domains->names(),
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

        // And again for the provider trees, whose signal watches all of `app`
        // because a constructor anywhere down a tree can change it.
        if ($request->boolean('providers')) {
            $payload['providers'] = $this->providerFingerprint();
        }

        // And for the domain list, which reads every source folder.
        if ($request->boolean('domains')) {
            $payload['domains'] = $this->domainFingerprint();
        }

        // The class index walks the same folders, so its signal is the same
        // walk; asked for separately so the domain list alone never pays for
        // resolving the index.
        if ($request->boolean('classes')) {
            $payload['classes'] = app(ClassIndex::class)->fingerprint();
        }

        // And the boundary check, which also moves when a rule is edited.
        if ($request->boolean('boundaries')) {
            $payload['boundaries'] = app(Boundaries::class)->fingerprint();
        }

        // And what changed since a baseline, which moves with any of the four
        // surfaces a snapshot is made of, or when watching starts or stops.
        if ($request->boolean('changes')) {
            $payload['changes'] = $this->changesFingerprint();
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
     * The provider list, every tree inline.
     *
     * One payload rather than a list plus one request per provider: a tree is
     * a few dozen nodes at most, the caps keep it that way, and selecting a
     * provider should not be a round trip.
     */
    public function providersJson(): JsonResponse
    {
        return response()
            ->json($this->providers() + ['fingerprint' => $this->providerFingerprint()])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Every domain, how it was found, and the classes in it.
     *
     * Fetched when the domain list is opened, never inlined: building it
     * reads every PHP file under the application's source folders.
     */
    public function domainsJson(): JsonResponse
    {
        return response()
            ->json($this->domains() + ['fingerprint' => $this->domainFingerprint()])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Every class in the application and the others it names.
     *
     * Fetched when asked for, never inlined: it parses every PHP file under
     * the application's source folders. Resolved here rather than in the
     * constructor so no other request builds one.
     */
    public function classesJson(ClassIndex $index): JsonResponse
    {
        $fingerprint = $index->fingerprint();

        return response()
            ->json($this->cache->remember('classes', $fingerprint, fn () => $index->export()) + ['fingerprint' => $fingerprint])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Every place a domain breaks the rule written for it in domains.json.
     *
     * Fetched when asked for, like the class index it reads. An app with no
     * rules gets an empty list without the index being built.
     */
    public function boundariesJson(Boundaries $boundaries): JsonResponse
    {
        $fingerprint = $boundaries->fingerprint();

        return response()
            ->json($this->cache->remember('boundaries', $fingerprint, fn () => $boundaries->export()) + ['fingerprint' => $fingerprint])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * What changed since a baseline: watching if somebody started to, else
     * the committed snapshot, or the one asked for with ?against=.
     *
     * Built per request rather than cached: the snapshot it takes reads each
     * surface through the payload cache, so with nothing changed it costs a
     * comparison, and a baseline taken a second ago must count at once.
     */
    public function changesJson(Request $request, Baselines $baselines, Snapshot $snapshot): JsonResponse
    {
        $asked = (string) $request->query('against', '');
        $against = Baselines::has($asked) ? $asked : $baselines->preferred();
        $report = ['summary' => ['breaking' => 0, 'risky' => 0, 'additive' => 0], 'changes' => []];
        $problem = null;

        if ($against !== null) {
            try {
                $report = Report::between($baselines->read($against), $snapshot->take());
            } catch (UnreadableSnapshot $e) {
                $problem = $e->getMessage();
            }
        }

        return response()
            ->json([
                'against' => $against,
                'baselines' => $baselines->describe(),
                'problem' => $problem,
                ...$report,
                'fingerprint' => $this->changesFingerprint(),
            ])
            ->header('Cache-Control', 'no-store');
    }

    /** Starts watching: a snapshot of the app as it is now, in storage/. */
    public function startWatching(Baselines $baselines, Snapshot $snapshot): JsonResponse
    {
        try {
            $baselines->write($baselines->path(Baselines::WATCH), Snapshot::encode($snapshot->take()));
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'message' => 'Could not start watching: '.$e->getMessage()], 500);
        }

        return response()->json(['ok' => true, 'baselines' => $baselines->describe()]);
    }

    public function stopWatching(Baselines $baselines): JsonResponse
    {
        $baselines->forget(Baselines::WATCH);

        return response()->json(['ok' => true, 'baselines' => $baselines->describe()]);
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

        $revision = $request->input('revision', LayoutRepository::ANY_REVISION);

        if ($revision !== null && ! is_string($revision)) {
            return response()->json(['ok' => false, 'message' => 'revision must be a string or null'], 422);
        }

        try {
            $saved = $this->layout->put($positions, $revision, $this->format($request, LayoutRepository::VERSION));

            // Read back rather than computed: the page's next save is based on
            // this, so it has to describe the file as it now is.
            return response()->json(['ok' => true, 'saved' => $saved, 'revision' => $this->layout->revision()]);
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

        $revision = $request->input('revision', ViewRepository::ANY_REVISION);

        if ($revision !== null && ! is_string($revision)) {
            return response()->json(['ok' => false, 'message' => 'revision must be a string or null'], 422);
        }

        try {
            $saved = $this->views->put($views, $revision, $this->format($request, ViewRepository::VERSION));

            return response()->json(['ok' => true, 'saved' => $saved, 'revision' => $this->views->revision()]);
        } catch (StateFileException $e) {
            return $this->refused($e);
        }
    }

    /**
     * The file format the page says it sent.
     *
     * Every page so far has sent one, so it matters after an upgrade: a tab
     * opened before it still sends version 1 ids, and they are migrated
     * rather than stored as if they were current. A client that sends none is
     * not one of ours and is taken to mean the current format.
     */
    protected function format(Request $request, int $current): int
    {
        $version = $request->input('version');

        return is_int($version) && $version > 0 ? min($version, $current) : $current;
    }

    /**
     * A write the repository declined or failed to make.
     *
     * Usually 409: nothing is broken and there is nothing to retry — the file
     * on disk simply is not this version's to rewrite. A filesystem that would
     * not take the write is a 500, with the reason in the body either way. The
     * page keeps the edit on screen and reports that it was not saved.
     */
    protected function refused(StateFileException $e): JsonResponse
    {
        return response()->json(['ok' => false, 'message' => $e->getMessage()], $e->status);
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

        // Chunks carry a content hash in their name, so a cached copy is
        // never wrong. The entry and the stylesheet do not — the view names
        // them directly — so they are revalidated on every load instead: a
        // 304 when nothing changed, the new file after an update.
        $hashed = ! isset($allowed[$file]);

        $response = response()->file($path, [
            'Content-Type' => $type,
            'Cache-Control' => $hashed ? 'public, max-age=31536000, immutable' : 'no-cache',
        ]);

        if (! $hashed) {
            $response->setAutoEtag();
            $response->isNotModified(request());
        }

        return $response;
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
    /** Every surface a snapshot is made of, and the baselines themselves. */
    protected function changesFingerprint(): string
    {
        return substr(sha1(implode('|', [
            $this->cache->key('changes', ''),
            $this->fingerprint(),
            $this->routeFingerprint(),
            $this->jobFingerprint(),
            $this->providerFingerprint(),
            app(Baselines::class)->signal(),
        ])), 0, 16);
    }

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
        return $this->cache->remember(
            'routes',
            $this->routeFingerprint(),
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
        return $this->cache->remember(
            'jobs',
            $this->jobFingerprint(),
            fn () => $this->jobs->export(),
        );
    }

    protected function domainFingerprint(): string
    {
        return $this->domainFingerprint ??= $this->domains->fingerprint();
    }

    /**
     * @return array<string, mixed>
     */
    protected function domains(): array
    {
        return $this->cache->remember(
            'domains',
            $this->domainFingerprint(),
            fn () => $this->domains->export(),
        );
    }

    protected function providerFingerprint(): string
    {
        return $this->providerFingerprint ??= $this->providers->fingerprint();
    }

    /**
     * @return array<string, mixed>
     */
    protected function providers(): array
    {
        // Every provider parsed and every constructor down its tree reflected —
        // cached on the signal like the other source-derived surfaces.
        return $this->cache->remember(
            'providers',
            $this->providerFingerprint(),
            fn () => $this->providers->export(),
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
        return $this->cache->remember(
            'schema',
            $this->fingerprint(),
            fn () => $this->exporter->export(),
        );
    }
}
