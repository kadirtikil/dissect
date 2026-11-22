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
    | Each model's class name is read from its file, so a directory does not
    | have to match its namespace.
    |
    */

    'models_path' => env('DISSECT_MODELS_PATH', 'app/Models'),

    /*
    | Several directories, for models that are not all in one place: one
    | Models folder per domain, or a package's. A `*` stands for one directory
    | name, so 'app/Domain/*' followed by '/Models' covers every domain in one
    | entry. When this lists anything, `models_path` is not read.
    |
    | Class names are read from the files themselves, so no namespace has to
    | be configured. `models_namespace` is no longer read; it is kept so an
    | existing config that sets it is not an error.
    */

    'models' => [
        'paths' => [],
    ],

    'models_namespace' => env('DISSECT_MODELS_NAMESPACE'),

    /*
    |--------------------------------------------------------------------------
    | Domains
    |--------------------------------------------------------------------------
    |
    | The bounded contexts an application is split into, and which one each
    | class belongs to. Nothing here needs setting for an application that
    | follows a common layout: domains are read off the folders, from
    | `Modules/*`, `app-modules/*`, `app/Domain/*`, `src/Domain/*`,
    | `packages/*`, hexagonal `src/{Name}/{Domain,Application,Infrastructure}`
    | and `app/Models/{Name}` beside `app/Http/Controllers/{Name}`.
    |
    | `file` is where domains are written down by hand, for an application
    | with no layout to follow or a folder in the wrong place. A class can also
    | carry its own `#[Domain('Billing')]` attribute; the attribute class is
    | the application's, matched by its short name.
    |
    | An application that follows no convention, has no file and no attribute
    | has no domains, and nothing on the page changes. `enabled` false turns
    | all of it off.
    |
    | `root` is where the folders are read from. `paths` is where classes are
    | looked for when listing every domain's members; each domain's own
    | folders are always included.
    |
    */

    'domains' => [

        'enabled' => env('DISSECT_DOMAINS', true),

        'root' => base_path(),

        'file' => base_path('.dissect/domains.json'),

        'paths' => ['app', 'src', 'Modules', 'app-modules', 'packages'],

    ],

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
    | Each provider's tree follows the classes it binds into their constructors,
    | by reflection and never by resolving anything. `max_depth` is how many
    | hops from the provider that walk goes, and `max_nodes` caps one tree
    | outright; a tree cut by either says so. Both exist for the export cost on
    | a large application, not for the drawing.
    |
    | `watch_paths` is the change signal. It is wider than `paths` because a
    | tree changes when a constructor anywhere down it does, not only when a
    | provider file is edited.
    |
    */

    'providers' => [

        'paths' => ['app/Providers'],

        'max_depth' => 4,

        'max_nodes' => 150,

        'watch_paths' => ['app'],

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

    /*
    |--------------------------------------------------------------------------
    | Snapshot file
    |--------------------------------------------------------------------------
    |
    | Where `php artisan dissect:snapshot` writes down the models, routes, jobs
    | and providers as they are, for `dissect:diff` to compare against. Commit
    | it: it is the "before" that a change is read against, and taking a new
    | one when a change is accepted puts what was accepted in the PR's diff.
    |
    */

    'snapshot_path' => base_path('.dissect/snapshot.json'),

    /*
    |--------------------------------------------------------------------------
    | Watch file
    |--------------------------------------------------------------------------
    |
    | A snapshot taken when someone starts watching, from the page or with
    | `dissect:snapshot --watch`: "what changed since I sat down", or since an
    | agent was let loose. Kept in storage/ because it is one person's session,
    | not something the team agreed on.
    |
    */

    'watch_path' => storage_path('dissect/watch.json'),

    /*
    |--------------------------------------------------------------------------
    | OpenAPI
    |--------------------------------------------------------------------------
    |
    | What `php artisan dissect:export routes --format=openapi` puts around the
    | operations it reads from the code. `title` defaults to the app's name.
    | `servers` are URLs (or OpenAPI server objects); left empty, a client
    | uses wherever it fetched the document from. `stacks` are the route
    | stacks described: `api` alone, since `web` routes answer with pages.
    |
    */

    'openapi' => [
        'title' => null,
        'version' => '1.0.0',
        'description' => null,
        'servers' => [],
        'stacks' => ['api'],
    ],

];
