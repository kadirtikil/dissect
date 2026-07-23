<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | The viewer exposes your full schema and writes a layout file, so it is
    | restricted to local environments by default. Override deliberately.
    |
    */

    'enabled' => env('DISSECT_ENABLED', null),

    /*
    |--------------------------------------------------------------------------
    | Route
    |--------------------------------------------------------------------------
    */

    'path' => env('DISSECT_PATH', 'dissect'),

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Directory scanned for Eloquent models. Relative paths resolve from the
    | application base path; absolute paths are used as given, so models can
    | live outside the application (a package, a Domain folder, a monorepo).
    |
    | The namespace is inferred from the path for the usual `app/Models` layout.
    | Set it explicitly when the mapping is not conventional — there is no
    | reliable way to guess it.
    |
    */

    'models_path' => env('DISSECT_MODELS_PATH', 'app/Models'),

    'models_namespace' => env('DISSECT_MODELS_NAMESPACE'),

    /*
    |--------------------------------------------------------------------------
    | Vite dev server
    |--------------------------------------------------------------------------
    |
    | Only relevant when working on this package itself. Set it to a running
    | Vite dev server (e.g. http://localhost:5199) and the page loads the
    | frontend from there instead of the compiled bundle, so edits hot-reload
    | while the PHP side stays real. Ignored outside local/testing/workbench.
    |
    */

    'dev_server' => env('DISSECT_DEV_SERVER'),

    /*
    |--------------------------------------------------------------------------
    | Layout file
    |--------------------------------------------------------------------------
    |
    | Where hand-arranged node positions are stored. Deliberately not inside
    | storage/ (gitignored) or vendor/ (wiped by composer install) — the layout
    | is meant to be committed and shared with the team.
    |
    */

    'layout_path' => base_path('.dissect/layout.json'),

    /*
    |--------------------------------------------------------------------------
    | Views file
    |--------------------------------------------------------------------------
    |
    | Saved views — named subsets of the graph, so a large schema can be read
    | one bounded context at a time. Committed and shared for the same reason
    | the layout is: "the billing models" is worth agreeing on once.
    |
    */

    'views_path' => base_path('.dissect/views.json'),

];
