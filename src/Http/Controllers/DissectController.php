<?php

namespace KdrDev\Dissect\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use KdrDev\Dissect\LayoutRepository;
use KdrDev\Dissect\Routes\RouteExporter;
use KdrDev\Dissect\SchemaExporter;
use KdrDev\Dissect\ViewRepository;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DissectController
{
    /** Computed at most once per request — index() needs it twice. */
    protected ?string $fingerprint = null;

    protected ?string $routeFingerprint = null;

    public function __construct(
        protected SchemaExporter $exporter,
        protected RouteExporter $routes,
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

    public function saveLayout(Request $request): JsonResponse
    {
        $positions = $request->input('positions');

        if (! is_array($positions)) {
            return response()->json(['ok' => false, 'message' => 'positions must be an object'], 422);
        }

        return response()->json(['ok' => true, 'saved' => $this->layout->put($positions)]);
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

        return response()->json(['ok' => true, 'saved' => $this->views->put($views)]);
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

        abort_unless(isset($allowed[$file]), 404);

        $path = dirname(__DIR__, 3).'/dist/'.$file;

        abort_unless(is_file($path), 404, 'Asset missing — the package was installed without its build output.');

        return response()->file($path, [
            'Content-Type' => $allowed[$file],
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
