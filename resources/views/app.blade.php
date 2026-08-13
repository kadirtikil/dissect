<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>dissect</title>

    {{-- In dev-server mode Vite injects the styles itself, so linking the built
         stylesheet as well would shadow hot updates with a stale copy.

         Standalone page either way: only this package's CSS loads, so the host
         application's design tokens can never collide with the viewer's. --}}
    @unless ($devServer)
        <link rel="stylesheet" href="{{ route('dissect.asset', 'dissect.css') }}">
    @endunless
</head>
<body>
    <div id="app"></div>

    {{-- Inlined so the page renders with zero round trips. The json directive
         escapes for a <script> context, so model or column names cannot break
         out of the string. Note: this must be a Blade comment — a JS comment
         mentioning that directive would itself be compiled. --}}
    <script>
        window.__DISSECT__ = {
            schema: @json($schema),
            layout: @json($layout),
            views: @json($views),
            saveUrl: @json(route('dissect.layout')),
            saveViewsUrl: @json(route('dissect.views.save')),
            schemaUrl: @json(route('dissect.schema')),
            {{-- Not the payload, only where to get it: the endpoint list is
                 fetched when somebody opens it. --}}
            routesUrl: @json(route('dissect.routes')),
            fingerprintUrl: @json(route('dissect.fingerprint')),
            fingerprint: @json($fingerprint),
            csrfToken: @json(csrf_token()),
        };
    </script>

    @if ($devServer)
        {{-- Hot reload inside the real Laravel host: the bundle is served by the
             Vite dev server instead of dist/, so frontend edits appear without a
             rebuild while the PHP side stays genuine. Enable with
             DISSECT_DEV_SERVER=http://localhost:5199 --}}
        {{-- These URLs are assembled in the controller on purpose: the Vite HMR
             client path contains a string Blade would otherwise compile as a
             directive, whatever quoting is used around it. --}}
        <script type="module" src="{{ $devClient }}"></script>
        <script type="module" src="{{ $devEntry }}"></script>
    @else
        <script type="module" src="{{ route('dissect.asset', 'dissect.js') }}"></script>
    @endif
</body>
</html>
