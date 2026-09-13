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
    | Be clear about what the default actually checks: unset means "on when
    | APP_ENV is local". It is not a build-time or install-time guarantee — the
    | service provider is auto-discovered and boots on every request wherever
    | the package is installed, so a production box running APP_ENV=local, or a
    | deploy that installs dev dependencies, serves the viewer.
    |
    | Treat the middleware below as the control that holds when this one does
    | not.
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
    | Routes
    |--------------------------------------------------------------------------
    |
    | The endpoint list is read from the router itself, so there is nothing to
    | configure about which routes exist. What does need saying is where the
    | code behind them lives: a route can change because a route file changed,
    | or a controller did, or a form request gained a rule, and these are the
    | directories watched for that.
    |
    | Widening this costs a file stat per file on each check; narrowing it to
    | the directories that actually hold HTTP code is the cheaper end.
    |
    */

    'routes' => [

        'watch_paths' => ['app', 'routes'],

    ],

    /*
    |--------------------------------------------------------------------------
    | Jobs
    |--------------------------------------------------------------------------
    |
    | Queueable classes are found by interface, not by folder: a job, a queued
    | listener, a mailable and a notification all implement ShouldQueue and all
    | end up on the same worker. These are the directories scanned for them.
    |
    | The class behind each file is read from the file's own namespace
    | declaration, so unlike the model path there is nothing to configure when
    | they live somewhere unconventional — add the directory and it works.
    |
    | `watch_paths` is the wider set searched for dispatch sites, and doubles as
    | the change signal for this surface. It is the same trade-off the route
    | list makes: a job can change because the job changed or because something
    | started dispatching it, and both are source files.
    |
    */

    'jobs' => [

        'paths' => ['app/Jobs', 'app/Listeners', 'app/Mail', 'app/Notifications'],

        'watch_paths' => ['app', 'routes'],

    ],

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | Directories scanned for service provider classes. `app/Providers` is the
    | conventional home, not the only one — a package split into domains keeps
    | a provider per domain, and a monorepo may keep them outside the
    | application entirely.
    |
    | Relative paths resolve from the application base path, absolute paths are
    | used as given, and a directory that is not there is skipped rather than
    | reported as an error.
    |
    */

    'providers' => [

        'paths' => ['app/Providers'],

    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | The live half of the queue surface: what is actually on a queue right now.
    | Unlike everything else here it is runtime state, so it is never cached and
    | the client polls it.
    |
    | Only the `database` and `redis` drivers can be enumerated. SQS cannot list
    | a message without receiving it, `sync` never queues anything, and `null`
    | discards. Those are reported with the reason rather than as an empty
    | queue, which would be a lie.
    |
    | `rows` caps how many jobs are listed per section. The counts are always
    | exact — a queue 40,000 deep reports 40,000 and shows you the first page.
    |
    */

    'queue' => [

        'rows' => 50,

        'poll_interval' => 5000,

    ],

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
