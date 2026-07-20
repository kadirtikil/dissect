<?php

namespace KdrDev\Dissect\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use KdrDev\Dissect\LayoutRepository;
use KdrDev\Dissect\SchemaExporter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DissectController
{
    public function __construct(
        protected SchemaExporter $exporter,
        protected LayoutRepository $layout,
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
            'fingerprint' => $this->exporter->fingerprint(),
            // When set, assets come from a running Vite dev server rather than
            // dist/ — see config('dissect.dev_server').
            'devServer' => $devServer,
        ]);
    }

    /**
     * Cheap polling endpoint. Returns the model-directory fingerprint so the
     * page can detect edits without rebuilding the graph on every check —
     * no file watcher process required.
     */
    public function fingerprint(): JsonResponse
    {
        return response()->json(['fingerprint' => $this->exporter->fingerprint()]);
    }

    public function schemaJson(): JsonResponse
    {
        return response()->json($this->schema());
    }

    public function saveLayout(Request $request): JsonResponse
    {
        $positions = $request->input('positions');

        if (! is_array($positions)) {
            return response()->json(['ok' => false, 'message' => 'positions must be an object'], 422);
        }

        return response()->json(['ok' => true, 'saved' => $this->layout->put($positions)]);
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

    /**
     * @return array<string, mixed>
     */
    protected function schema(): array
    {
        // Inspecting every model does reflection plus a schema query each; the
        // fingerprint means that work happens once per model-file change
        // rather than once per request.
        return Cache::remember(
            'dissect.schema.'.$this->exporter->fingerprint(),
            now()->addHour(),
            fn () => $this->exporter->export(),
        );
    }
}
