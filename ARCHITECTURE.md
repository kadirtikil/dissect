# Architecture

How the pieces fit together, for someone reading this fresh. For the history of
*why* things ended up this way, see `PROJECT-LOG.md`.

---

## Working on this package

The package develops against its own skeleton Laravel app (Orchestra Workbench),
with fixture models in `workbench/app/Models`. Nothing external is needed — no
host application, no Docker.

```bash
composer install
composer build          # create + migrate the workbench SQLite database
composer serve          # http://127.0.0.1:8000/dissect
```

That serves the **compiled** bundle from `dist/`, which is what users get. For
frontend work you want hot reload instead:

```bash
pnpm dev --port 5199    # terminal 1
composer serve:hmr      # terminal 2
```

`serve:hmr` writes a `.workbench-dev` file (gitignored) containing the dev-server
URL; the Blade view then loads the frontend from Vite instead of `dist/`, so
edits hot-reload while the PHP side stays real. Delete the file to go back.

`testbench serve` runs its PHP server with a controlled environment, so shell
variables do **not** reach it — hence the file rather than an env var.

The fixture models deliberately cover every relation family (including a
self-referencing tree, a polymorphic `MorphTo`, two relations between the same
pair, and a relation to a model outside the scan) and every column kind, so the
whole pipeline is exercised by `composer serve` alone.

`workbench/app/Http` and `workbench/routes` do the same job for the endpoint
list, and are chosen for the cases that are awkward rather than the ones that
are typical: a form request whose `rules()` calls `$this->route()` and so cannot
be run, a resource with no `@mixin` and no matching model, a resource nesting
another two levels deep, an invokable controller, a closure route, an optional
constrained parameter, and validation written inline in the action.

### A queue with something on it

An idle development queue is empty, which is the one state the live surface
cannot be judged from. `dissect:seed-queue` writes the shapes worth looking at
straight into the queue tables — waiting, reserved, delayed, two failures and a
half-finished batch:

```bash
php vendor/bin/testbench dissect:seed-queue
php vendor/bin/testbench dissect:seed-queue --clear
```

Rows are written rather than dispatched on purpose: dispatching `PublishPost`
needs a `Post` row for it to carry, and the command's job is to produce a queue,
not to arrange the database into a state where one can be produced.

The workbench points the `database` queue driver at its own SQLite connection —
including the failer and the batch repository, which each carry a connection of
their own and would otherwise read a database with no tables in it. Never under
`testing`, for the same reason the generated model fixture is not.

### A schema big enough to hurt

Seven models prove correctness but say nothing about how the viewer behaves at
the size real applications reach. `composer serve:huge` generates one that does
— 122 models and ~500 relations by default:

```bash
composer huge          # generate (models + their migration)
composer serve:huge    # generate, rebuild the database, migrate, serve
composer huge:clean    # remove it again
```

`workbench/scripts/generate-huge-models.php [count] [seed]` writes models into
`workbench/app/Huge` and a migration into `workbench/database/migrations-huge`.
Both are gitignored: the generator is deterministic, so the output is one
command away and does not belong in review.

The generated set is the switch — `WorkbenchServiceProvider` points the viewer
at `workbench/app/Huge` whenever that directory has models in it, **except
under `testing`**, where the suite's assertions depend on the small fixture.
The migration lives outside `testbench.yaml`'s migration path for the same
reason, so `composer test` and `composer build` never see it.

Names come from twelve domain vocabularies rather than `Model1..Model120`, so
the graph has clusters, hub models everything points at, and the uneven shape a
real schema has. Half the models declare `$fillable`, some declare `$hidden`,
and casts follow the column kinds — which is what gives an expanded card
something to show.

### The two frontend hosts

`pnpm dev` on its own still works and is the fastest loop for pure styling work,
but it reads fixture JSON from `public/` and never exercises the PHP.

---

## The shape of it

A Laravel package that renders an application's Eloquent models as an
interactive graph, and its HTTP endpoints as the surface on top of that graph.
The PHP side reads the application; the Vue side draws it. They meet at exactly
four data contracts, described below.

The whole thing runs in **two hosts**, and the same JavaScript bundle serves
both:

```
  PACKAGE (production path)              STANDALONE (development only)
  ─────────────────────────              ─────────────────────────────
  GET /dissect                      pnpm dev
        │                                      │
  SchemaExporter ─┐                      public/schema.json
  LayoutRepository│                      public/layout.json
  ViewRepository ─┘                      public/views.json
        │                                      │
  app.blade.php                          index.html
        │                                      │
  window.__DISSECT__  ◄── seam ──►  fetch()
        │                                      │
        └──────────► main.ts ◄─────────────────┘
                        │
  RouteExporter ────────┤ (fetched on demand)  public/routes.json
  JobExporter ──────────┤ (fetched on demand)  public/jobs.json
  QueueSnapshot ────────┘ (polled, never cached) public/queue.json
```

`routes.json` and `jobs.json` are the odd ones out: only their URLs are inlined,
and each payload is fetched the first time somebody opens the surface that needs
it. See "Routes" and "Jobs" below.

`resources/js/lib/bootstrap.ts` is that seam. It returns whatever the server
inlined, or an empty object; every consumer treats the fields as optional and
falls back to fetching. Nothing else in the app knows which host it is in.

---

## PHP side

```
DissectServiceProvider    wiring; registers routes only when enabled
  ├── SchemaExporter          orchestrates: discover → inspect → normalise
  │     ├── ModelInspector    (Laravel's own, needs ^11.33)
  │     ├── ColumnNormalizer  attribute rows → the viewer's column shape
  │     │     └── TypeNormalizerManager
  │     │           └── {Postgres,MySql,Sqlite,SqlServer,Generic}TypeNormalizer
  │     └── MigrationState    "has a migration actually run?" — half the fingerprint
  ├── RouteExporter           orchestrates: collect → resolve → analyse → link
  │     ├── RouteCollector      the router's table: verbs, uri, middleware, params
  │     ├── ActionResolver      what runs, and whose code it is (app/vendor/framework)
  │     ├── RequestAnalyzer     FormRequest::rules(), else inline validate()
  │     │     └── RuleNormalizer   rule strings/objects → type, required, table
  │     ├── ResponseAnalyzer    return type → JsonResource::toArray() / response()->json()
  │     ├── ModelLinker         table, class or convention → a schema.json node id
  │     ├── Ast\ClassSource     php-parser: a method's body, without running it
  │     └── RouteFingerprint    "has anything behind the route table been edited?"
  ├── JobExporter             orchestrates: discover → inspect → scan → link
  │     ├── JobDiscovery        queueable classes, found by interface not by folder
  │     ├── JobInspector        queue, retries, payload, middleware — read, never run
  │     │     └── Confidence      the weakest grade of everything it had to read
  │     ├── DispatchScanner     php-parser: every place a job is put on the queue
  │     ├── RouteMap            a dispatch site's surroundings → a route id
  │     └── ProjectPath         the one spelling of a path both sides join on
  ├── QueueSnapshot           what is on the queue *right now* — never cached
  │     ├── QueueReaderFactory  driver → a reader, or the reason there isn't one
  │     │     ├── DatabaseQueueReader  three predicates over the jobs table
  │     │     └── RedisQueueReader     a list and two sorted sets, per queue
  │     ├── PayloadDecoder      the envelope only — never the serialised command
  │     └── QueueHistory        the failed job provider and the batch repository
  ├── LayoutRepository        read/write/sanitise the layout file
  ├── ViewRepository          read/write/sanitise the saved views
  │     └── Concerns\VersionedStateFile   refuse newer formats, back up older ones
  └── DissectController   page, schema JSON, routes JSON, fingerprint, assets
```

### Request lifecycle

1. `DissectController::index()` asks for the schema.
2. The schema is cached under `dissect.schema.{fingerprint}`. Inspecting every
   model costs reflection plus a schema query each, so this happens once per
   *change* rather than once per request.
3. Schema, layout, save URL and CSRF token are inlined into the Blade view.
4. The page boots with **zero XHR**.

### Staying current

The graph has two sources with different lifecycles, and the fingerprint covers
both:

| Half of the graph | Comes from | Signal |
|---|---|---|
| Relations | the model files | newest mtime + file count across the model directory |
| Columns | the live database | `MigrationState` — row count and max id of the `migrations` table |

The migration half is deliberately **not** read from the migration *files*.
Columns are reported by the database, so a migration that has merely been
written describes columns that do not exist yet; re-exporting then would render
a schema nobody has. Laravel inserts the `migrations` row only after `up()`
returns, so the table is exactly "what ran successfully" — a migration that
threw part-way leaves no row and moves nothing.

The endpoint list has a third source with a third lifecycle:

| Half of the graph | Comes from | Signal |
|---|---|---|
| Routes | route, controller, form request and resource files | newest mtime + file count across `routes.watch_paths` |

The job list has a fourth, on the same mechanism as the third:

| Half of the graph | Comes from | Signal |
|---|---|---|
| Jobs | job, listener, mailable, controller and route files | newest mtime + file count across `jobs.watch_paths` |

That one is deliberately coarser than the other two: a route can move because
any of four kinds of file changed, so the signal is a file stat over whole
directories. It may move when nothing visible changed — which costs a cache
miss — but it must never sit still when something did, because that is a stale
page nobody knows is stale.

Both are also **only computed when asked for**. `/fingerprint` returns the route
half only for `?routes=1` and the job half only for `?jobs=1`, which the client
sends once the surface in question has been opened. A session that stays on the
graph never pays for either walk, and a session on one of them never pays for
the other.

The client polls `/fingerprint` every 3s (paused while the tab is hidden,
checked immediately when it returns) and re-fetches `schema.json` when the value
moves. The new payload goes through `applySchema`, the same ingest path as the
initial load, so slot order and saved positions survive: existing models keep
their place and only new ones are appended. A failed refresh leaves the working
graph on screen and does not advance the stored fingerprint, so it retries.

`MigrationState` reads the **default connection only**, and never throws — a
database that is down or unmigrated degrades to a constant signal rather than
an error page.

### Type normalisation

Databases report types in their own dialect: Postgres says
`character varying(255)`, MySQL says `varchar(255)`, SQLite says `TEXT`. Each
column is therefore expressed three ways:

| Field | Example | Purpose |
|---|---|---|
| `type` | `timestamp(0) without time zone` | Verbatim. What you want when chasing a migration issue |
| `label` | `timestamp` | Short form, what the node renders |
| `kind` | `datetime` | Portable meaning — the UI keys off this |

`kind` is the important one: it is why the viewer looks identical regardless of
database, and it is the hook for future colour-coding or filtering. An
unrecognised type degrades to `kind: unknown` with the native string as its
label — never an exception, never a guess.

The driver classes are deliberately **free of Illuminate imports**: they are
pure functions over strings, so they can be exercised without booting a
framework or touching a database. `RuleNormalizer` follows the same rule.

### Routes

The endpoint list exists to answer the question the graph cannot: how do you
reach this data over HTTP. What makes it part of dissect rather than a second
`route:list` is one field — `models` — carrying the same class-basename ids
`schema.json` uses as node keys. Three different things resolve to it, and
`ModelLinker` is where they meet:

| The endpoint says | Resolved by |
|---|---|
| `Post $post` on the action | the type hint, via implicit route binding |
| `exists:authors,id` in a rule | the table name |
| `PostResource` as the return type | `@mixin`, then the `XResource → X` convention |

The convention is only ever *accepted* when the graph actually holds a node by
that name, which keeps it a check rather than a guess.

**Shapes are read, not run.** Rules and resource bodies are array literals, and
`Ast\ClassSource` reads them with `nikic/php-parser` — no container, no
database, no chance of somebody's `rules()` bringing the page down. The one
exception is `FormRequest::rules()`, which is *tried* first because it is the
only way to see rules that are built rather than written. That call is wrapped
so PHP warnings become exceptions: a `rules()` written against a live request
usually does not throw without one, it reads a property on null and carries on,
which would produce a nonsense rule and a warning in the host application's log
blamed on a page that was only looking.

**`confidence` is load-bearing.** Nothing in Laravel can be asked what an
endpoint returns, so some of this is inference, and an API description that is
confidently wrong costs more than one that admits what it could not work out:

- `certain` — the framework produced it (`rules()` ran) or the source stated it
  outright (`@mixin`).
- `inferred` — read from source, or the model came from the naming convention.
- `unknown` — the class was found and its shape could not be read.

It is the same discipline as `kind: unknown` in `ColumnNormalizer`: degrade
honestly, never guess silently. The UI renders it.

`ResponseAnalyzer` follows nested resources two levels deep. Two answers "what
comes back and what is inside it" for almost every payload, and stops a pair of
resources that embed each other from unrolling forever.

### Jobs

The endpoint list answers "how do you reach this data". The job list answers the
question a 202 leaves behind: what is going to happen next, where, and carrying
what. It is the same kind of surface — derived from source, cached against a
file-stat signal, fetched when opened — and it joins to both of the others.

**Found by interface, never by folder.** A job, a queued listener, a mailable
and a notification are four different shapes that all implement `ShouldQueue`
and all arrive at the same worker. `jobs.paths` says where to look;
`ShouldQueue` decides what counts. `kind` is then read from what the class *is*
— its ancestry for a mailable or a notification, and for a listener the event
dispatcher's own registrations, since nothing about a listener class says it is
one. A mailable kept outside `app/Mail` is still a mailable.

The class behind each file is read from the file's **own `namespace`
declaration**, unlike model discovery, which infers it from the path. Four
directories in an application free to put any of them anywhere is four namespace
settings to get wrong, and the answer is already written at the top of every
file.

**`$queue` is usually not a property.** A job that uses `Queueable` *cannot*
declare `public $queue` — PHP rejects it as an incompatible redefinition of the
trait's own property — so almost every job names its queue with
`$this->onQueue('…')` in its constructor. Reading only the property would report
`default` for most real jobs, which is not a smaller answer but a wrong one. So
both are read, and `queue_source` says which answered:

| `queue_source` | Means |
|---|---|
| `property` | A declared `$queue` — the usual form for a listener, which inherits nothing that claims the name |
| `constructor` | `$this->onQueue('…')` among the constructor's own statements. Not one inside an `if`: a queue that depends on the arguments is not a queue this can report |
| `dispatch` | The class said nothing; exactly one dispatch site chained `->onQueue('…')` |
| `mixed` | Sites named two different queues. Picking one would invent a fact, and "it depends who dispatches it" is worth knowing |
| `default` | Nothing anywhere named one |

**Two node shapes, one rule.** Laravel has a great many ways to queue something
— `Job::dispatch()`, `dispatch(new Job)`, `Bus::batch([...])`, `->chain([...])`,
`Mail::to($u)->queue(...)`, `$user->notify(...)`. Enumerating them means missing
the next one, so `DispatchScanner` looks for only two things — a static
`dispatch*()` call and a `new` — and keeps a hit **only when the class is one
discovery already found**. That is `ModelLinker`'s discipline applied to call
sites: match against the set of things that exist rather than guess from the
shape of the call. The cost is that constructing a queueable without dispatching
it reads as a dispatch, which for a `ShouldQueue` class is almost always a
dispatch through an API this deliberately does not know about.

**Nothing is executed.** `backoff()` and `middleware()` are the two declarations
that can be written as methods, and both are read as syntax. `middleware()`
routinely returns middleware built from the job's own state —
`new WithoutOverlapping($this->order->id)` — which on an unconstructed instance
is a fatal error, not a catchable one. `confidence` grades the result on the
same ladder the request and response shapes use, and `Confidence` keeps the
weakest grade of everything that had to be read.

**The two joins are the point.** `models` carries the class basenames the graph
uses as node keys, read from the constructor signature — which is literally what
gets serialised onto the queue. `dispatched_by[].route` carries a route id from
`routes.json`. `RouteMap` builds that second one by addressing both sides the
same way: a controller by its fully qualified `Class@method`, a closure by where
it is written, which is the only identity a closure has. Both spellings run
through `ProjectPath`, because two copies of "relative to the application root"
that merely agree today is a join waiting to break.

A job with an empty `dispatched_by` is reported as exactly that — no site was
found — which is either dead code or a dynamic dispatch, and the surface must
not claim to know which.

### The queue

Everything above is derived from source: cached against a file-stat signal,
true until somebody edits a file, and re-read when a fingerprint moves. The
queue is not. It is runtime state — it changes second to second, no fingerprint
can describe it, and the honest response is to read it on every request and let
the client poll.

That is a **second freshness contract**, and it is kept visibly separate rather
than folded into the jobs surface. The job list says what *can* be queued; this
says what *is*. They answer to different clocks, so they are different pages,
and `/queue.json` is the one endpoint here with no cache behind it.

**Three tenses, because that is how anybody asks about a queue.**

| Tense | What it holds | Database | Redis |
|---|---|---|---|
| **now** | waiting to be picked up | `reserved_at is null` and its time has come | the list |
| **now** | reserved by a worker | `reserved_at is not null` | the `:reserved` sorted set |
| **next** | delayed until later | `available_at` in the future | the `:delayed` sorted set |
| **past** | failed, and batched | the failed job provider, the batch repository | the same |

**The past is the half that has to be honest.** Laravel records *nothing* about
a job that succeeded — it is queued, it runs, it is deleted. So "what was in the
queue" is what went wrong and what was batched, and `records_completions: false`
travels on the wire so the client does not have to know that on its own. An
empty history means nothing failed, never that nothing ran.

**The serialised command is never unserialised.** A payload carries
`data.command`, a serialised instance of the application's own job. Restoring it
would construct application objects, run their `__wakeup`, and on a
`SerializesModels` job go to the database for every model it carries — a viewer
that describes a queue must not be able to do any of that. The JSON envelope
answers every question the surface asks, and `displayName` in it is already the
class the Jobs surface lists, which is what joins a live row to its definition.
The payload is not reported either: the envelope is safe to describe, the
command is somebody's data. `tests/Feature/QueueTest.php` pins this with a
command whose `__wakeup` would set a flag.

**A driver that cannot be enumerated says so, with the reason.** Only `database`
and `redis` can be listed. SQS can report an approximate depth but cannot show a
message without receiving it, `sync` never queues anything, and `null` discards
— and a missing `jobs` table is a fourth case with something the reader can
actually do about it. Showing an empty queue for any of them would be a lie
about an empty queue. The same rule applies one level down: a failed job store
that cannot be read reports *why* rather than reporting nothing.

The history is read even when the queue is not, because failures are stored by
the application rather than by the driver — an SQS application still knows what
went wrong.

---

## Frontend side

```
main.ts                 mounts App; no router (see "Decisions")
└── App.vue             the shell: sidebar, page header, the open page
    ├── AppSidebar      brand, one nav button per registry entry, theme toggle
    ├── LandingPage     the overview: counts, entry points, freshness
    ├── GraphCanvas     Vue Flow wiring, loading/error states, drag handling
    │   ├── ViewMenu    saved views: switch, edit membership, create, delete
    │   ├── ModelNode   one model: name, table, relation count, columns
    │   └── GraphLegend relation families
    ├── RoutesPanel     list ▏ detail
    │   ├── RouteFilters   search + facet chips with counts
    │   ├── RouteList      grouped by controller, collapsible
    │   └── RouteDetail    verbs, middleware, params, request, response, touches
    │       └── FieldTree  a flat path list rendered as a tree
    ├── JobsPanel       list ▏ detail
    │   ├── JobFilters     search + kind/queue chips, and the undispatched count
    │   ├── JobList        grouped by queue, collapsible
    │   └── JobDetail      queue, retries, payload, middleware, dispatchers, carries
    └── QueuePanel      depth, then the three tenses; polls while it is open
        └── QueueSection   one tense as a table, or why it could not be read

stores/schema.ts        load → normalise → order → place → filter to the view
stores/layout.ts        saved positions, debounce, prune, persist
stores/views.ts         saved views, active view, persist
stores/routes.ts        lazy load, filter state, selection
stores/jobs.ts          the same, for the queue surface
stores/queue.ts         the odd one: polled runtime state, nothing to cache
stores/navigation.ts    which page is open: fragment, then storage, then default
pages/registry.ts       every page there is — the one file a new surface is added to
lib/toolbar.ts          where a page hangs its own header controls
lib/layout.ts           the grid: pure function of node index
lib/relations.ts        relation type → family → colour
lib/httpMethods.ts      verb → colour, on the same ramp
lib/routeFilters.ts     pure predicates + facet counts (the unit-tested part)
lib/jobFilters.ts       the same shape again: queue bucketing, facets, grouping
lib/jobKinds.ts         kind → colour, on the same ramp; and how a queue was named
lib/elapsed.ts          "2m ago" / "in 4h" — a queue is read in relative time
lib/bootstrap.ts        the host seam
```

### Pages

Every surface is an entry in `pages/registry.ts` — id, label, icon, and either a
lazy import or the `persistent` flag. The sidebar, the navigation store and the
shell all read from it, so adding a surface is that entry plus the page itself.

`GraphCanvas` is the exception the flag exists for: the shell holds it directly
and hides it with `v-show`. It owns the schema load and the change poller — the
one the routes surface also depends on — and remounting it would drop the
viewport somebody arranged as well as restarting both. Because it can therefore
finish loading while another page is on screen, and a hidden container has no
dimensions to fit against, its first `fitView` waits on Vue Flow's measured
dimensions rather than on mount. Every other page mounts on arrival and unmounts
on leaving, which is what keeps `routes.json` a fetch somebody asked for.

A page contributes its own header controls by teleporting them into one of the
two regions in `lib/toolbar.ts`. The shell owns the mount points and knows
nothing about what lands in them.

The cross-link runs in both directions and is what stops this being two screens
that happen to share a header:

- Selecting an endpoint puts its models in `schema.highlighted`. Clicking one
  centres it and switches surface. Highlight is kept **apart from Vue Flow's
  selection**, which is a gesture somebody made on the canvas and feeds view
  membership — conflating them would let opening an endpoint quietly rewrite
  what a new view would contain.
- An expanded `ModelNode` offers `Endpoints · N` and `Jobs · N`, which switch
  the other way with the list filtered to that model — what reaches this data
  over HTTP, and what reaches it from a worker.
- A job's dispatch site offers the endpoint it sits in, and a model card's job
  count is the same link read backwards. `focusEndpoint` and `focusJob` clear
  the filters *and* the view scope before selecting: landing on a surface with
  the thing you asked for filtered out of it is the one thing such a link must
  not do.

The watcher that mirrors the selection lives in `RoutesPanel`, not in the store:
`stores/schema.ts` already reaches for the routes store to poll the route
signal, and having them import each other would put a cycle between two
module-level `defineStore` calls.

Paths are rendered as a tree by splitting on `.`, but a row is only shortened to
its leaf when its parent is actually on screen above it. A rule written for
`tags.*.name` without one for `tags` renders as the whole path, because a bare
`name` under nothing is a field nobody can address.

### How a node gets its position

1. `layoutGraph()` assigns a grid slot — columns of `COLUMN_SIZE` (5), with row
   height derived from each node's column count so tall nodes push their
   neighbours down instead of overlapping.
2. Any position in `layout.json` **overrides** that slot.
3. `slotOrder` remembers which model held which slot, so a re-export adds new
   models at the end instead of reshuffling the board.

The grid deliberately ignores edges: position is a pure function of index, so
the arrangement never shifts because a relation was added.

### Views

A view is a named set of model ids. The active one filters what the canvas
draws — `visibleNodes` / `visibleEdges` in the schema store — and nothing else:
placement, slot order and the layout pruning all keep working from the complete
graph. An edge is drawn only when both of its models are in the view.

Membership is all a view holds. Position stays in `layout.json`, so a model sits
in the same spot whichever view is open, and there is still exactly one writer
for the layout. Switching therefore only refits the viewport; per-view
positions, if they are ever wanted, are an added optional field rather than a
change to any of this.

Which view is open is *not* in the file — it is per browser (localStorage). The
file is committed and shared; what somebody happens to be reading is not.

Membership is edited from a list of *every* model, not from the canvas alone.
That is a requirement rather than a convenience: an active view hides the models
it does not contain, so the one thing you cannot do on the canvas is add a model
that is missing from the view. The list ticks a model in or out and saves as it
goes; writes are queued, since ticking through a list produces one write per
click and two landing out of order would persist the earlier edit. The last
member cannot be unticked — an empty view is dropped on write, so that click
would silently delete the view instead of editing it.

Canvas selection remains the fast path: shift-drag, then either name the
selection as a new view or add it to the open one.

---

## Data contracts

### `schema.json` (generated — never hand-edit)

```jsonc
{
  "nodes": [{
    "id": "User",                     // class basename, the graph key
    "class": "App\\Models\\User",
    "table": "users",
    "connection": "pgsql",
    "columns": [{
      "name": "email",
      "type": "character varying(255)",  // verbatim
      "label": "varchar(255)",           // display
      "kind": "string",                  // portable
      "nullable": false, "unique": true, "increments": false,
      "fillable": true, "hidden": false, "cast": null, "virtual": false
    }]
  }],
  "edges": [{
    "source": "User", "target": "Team",
    "name": "teams",                  // the relation method
    "type": "BelongsToMany"
  }]
}
```

### `routes.json` (generated — never hand-edited, never written)

```jsonc
{
  "routes": [{
    "id": "POST:api/invoices",          // verbs + path; plenty of routes have no name
    "methods": ["POST"],
    "uri": "api/invoices",
    "name": "invoices.store",
    "domain": null,
    "group": "app",                     // app | vendor | framework — the facet
    "action": {
      "type": "controller",             // controller | closure | view | redirect
      "class": "App\\Http\\Controllers\\InvoiceController",
      "method": "store",
      "label": "InvoiceController@store"   // or "api.php:22" for a closure
    },
    "middleware": ["api", "auth:sanctum"],
    "parameters": [
      { "name": "invoice", "optional": false, "field": null,
        "pattern": null, "model": "Invoice" }
    ],
    "request": {
      "source": "form-request",         // form-request | inline-validate | none
      "class": "App\\Http\\Requests\\StoreInvoiceRequest",
      "confidence": "certain",          // certain | inferred | unknown
      "fields": [
        { "path": "customer_id", "type": "integer", "required": true,
          "rules": ["required", "integer", "exists:customers,id"],
          "model": "Customer", "column": "id" }
      ]
    },
    "response": {
      "source": "resource",             // resource | resource-collection | json | view | redirect | unknown
      "class": "App\\Http\\Resources\\InvoiceResource",
      "status": 201,
      "confidence": "certain",
      "fields": [
        { "path": "id", "kind": "scalar", "model": "Invoice",
          "column": "id", "conditional": false },
        { "path": "lines[]", "kind": "array", "model": "InvoiceLine",
          "column": null, "conditional": true }
      ]
    },
    "models": ["Customer", "Invoice", "InvoiceLine"]   // the join to the graph
  }],
  "generated_at": "…",
  "fingerprint": "…"                    // travels with the payload; nothing inlines it
}
```

Request and response field paths share one grammar — `tags[].name` on both
sides — so one component renders both. A `column` is only ever reported
alongside the `model` it belongs to; on its own it names nothing anybody can
follow.

There is no authored counterpart file. Routes are entirely derived, so nothing
is committed and nothing has to be sanitised on write.

### `jobs.json` (generated — never hand-edited, never written)

```jsonc
{
  "jobs": [{
    "id": "App\\Jobs\\SendInvoice",         // the FQCN; unlike a route it is unique on its own
    "class": "App\\Jobs\\SendInvoice",
    "name": "SendInvoice",
    "kind": "job",                        // job | listener | mailable | notification
    "queue": "invoices",
    "queue_source": "constructor",        // property | constructor | dispatch | mixed | default
    "connection": null,
    "retry": {
      "tries": 3, "timeout": 120, "maxExceptions": 2,
      "backoff": [10, 60],                // one int, a list or a method — all reported as a list
      "retryUntil": false                 // whether one is set, not when: it is computed at queue time
    },
    "traits": { "batchable": true, "unique": false,
                "encrypted": false, "afterCommit": true },
    "payload": [                          // the constructor signature — what goes on the wire
      { "name": "invoice", "type": "App\\Models\\Invoice", "model": "Invoice",
        "optional": false, "variadic": false, "default": null }
    ],
    "middleware": ["WithoutOverlapping"], // class basenames, read from source
    "events": [],                         // what a listener is registered against
    "confidence": "inferred",             // certain | inferred | unknown
    "dispatched_by": [{
      "file": "app/Http/Controllers/InvoiceController.php",
      "line": 61,
      "label": "InvoiceController@store", // or "api.php:22" for a closure
      "context": "App\\Http\\Controllers\\InvoiceController@store",  // the join key
      "method": "dispatch",               // the call that queued it: dispatch, queue, notify, batch…
      "queue": null, "connection": null,  // only when chained as a literal
      "delayed": false, "afterCommit": true,
      "route": "POST:api/invoices"        // ← the join to routes.json
    }],
    "models": ["Invoice"]                 // ← the join to schema.json
  }],
  "generated_at": "…",
  "fingerprint": "…"
}
```

`label` names the action the site sits in; `line` is where the dispatch itself
is written. For a closure those differ on purpose — the label addresses the
closure the way the routes surface does, and the line addresses the call.

Like `routes.json` there is no authored counterpart: jobs are entirely derived,
so nothing is committed and nothing has to be sanitised on write.

### `queue.json` (runtime state — never cached, never written)

```jsonc
{
  "connection": "database",
  "driver": "database",
  "connections": ["sync", "database", "redis"],   // for the switcher
  "readable": true,
  "refusal": null,                    // why not, when readable is false
  "queues": [
    { "name": "invoices", "waiting": 4, "delayed": 1, "reserved": 1 }
  ],
  "now":  { "waiting": {section}, "reserved": {section} },
  "next": { "delayed": {section} },
  "past": { "failed": {section}, "batches": [{batch}] },
  "limit": 50,
  "records_completions": false,       // Laravel keeps no trace of a job that worked
  "read_at": "…"
}
```

A section is `{ rows, total, truncated, unreadable }` — `total` is the real
depth and `rows` is the page of it that was fetched, which are not the same
number on a queue worth worrying about. `unreadable` is a sentence, and only the
history ever sets it.

A row carries the envelope and nothing else:

```jsonc
{
  "id": "1471", "uuid": "…",
  "job": "App\\Jobs\\SendInvoice",   // ← the join to jobs.json
  "name": "SendInvoice",
  "queue": "invoices",
  "attempts": 1, "maxTries": 3,
  "queued_at": "…", "available_at": "…", "reserved_at": "…",
  "failed_at": "…", "exception": "RuntimeException: …"  // failed rows only
}
```

There is deliberately no `payload` and no `batch` id: the first is somebody's
data, and the second only exists inside the serialised command, which is never
opened.

### `layout.json` (authored — safe to commit and review)

```json
{ "version": 1, "positions": { "User": { "x": 957, "y": 780 } } }
```

Kept separate from the schema on purpose: the schema is regenerated and would
clobber it. Stored at `base_path('.dissect/layout.json')` — not `vendor/`
(wiped by composer) and not `storage/` (gitignored, which would defeat the point
of a shareable layout).

### `views.json` (authored — safe to commit and review)

```json
{
  "version": 1,
  "views": [{ "id": "billing", "name": "Billing", "models": ["Invoice", "Payment"] }]
}
```

Stored at `base_path('.dissect/views.json')`, for the same reasons: "the billing
models" is worth agreeing on once and reviewing in a pull request. `id` is a
slug of the name and addresses the view; `models` are node ids (class
basenames). Both hosts validate against the same rules — `ViewRepository` on the
PHP side, `sanitiseViews()` in the dev-server plugin — so a file written by
either is one the other accepts.

### The `version` field is load-bearing

Both authored files are rewritten **in full** on every save, and both are read
through a `sanitise()` that silently drops anything it does not recognise. Those
two properties are individually reasonable and jointly dangerous: read a file
written by a future format, and the next save replaces it with whatever subset
the running version understood — once, without a warning, and the atomic rename
makes it stick.

`Concerns\VersionedStateFile` is what stops that, and both repositories use it:

| File's version | Behaviour |
|---|---|
| Newer than `VERSION` | Readable, **never writable**. `put()` throws `StateFileException`; the controller answers 409 and the page reports "not saved" |
| Older than `VERSION` | Copied to `<path>.v<n>.bak` before the first migrating write. Never overwritten — the first copy is the one taken before any new-format write |
| Equal | Ordinary write, no copy |
| Unparseable | Copied to `<path>.corrupt.bak`, then replaced |
| Missing or nonsense | Treated as format 1, so a typo cannot lock somebody out of their own file |

The refusal direction matters more than the migration one. A migration is code
you will write deliberately when you bump the format; the refusal protects
people you will never hear from — a teammate on an older install, someone who
rolled back a release — and it only works if it is already in the version they
are running. `tests/StateVersioningTest.php` pins all of it.

---

## Decisions worth not undoing

| Decision | Reason |
|---|---|
| **`dist/` is committed** | The package serves it directly. One `composer require`, no build step in the host app. `.gitignore` and Tailwind's `@source not` both encode this |
| **Assets served by a route, not `vendor:publish`** | Nothing to re-publish after `composer update` |
| **Standalone Blade page, not Inertia** | No dependency on the host's frontend build, Vue version or Tailwind version; the host's design tokens cannot collide with ours |
| **No vue-router** | The page is registered as exactly one GET route with no SPA fallback, so a reload on a path-matching route 404s however the mount prefix is resolved. Pages are addressed by fragment instead (`stores/navigation.ts`), which never reaches the server. Five flat pages with no params also buy nothing from a router |
| **The graph is not mounted by the page registry** | It starts the change poller the routes surface also depends on. Mount it lazily like the others and live updates elsewhere stop until somebody visits the graph. `pages/registry.ts` carries the warning next to the flag |
| **Routes local-only by default** | It exposes the full schema and writes a file. The gate is `APP_ENV`, not install-time: the provider is auto-discovered and boots wherever the package is installed, so `middleware` is the control that holds when the environment check does not |
| **A newer state file is never overwritten** | Both authored files are rewritten in full and sanitised on read, so saving over a format this version does not understand is silent data loss. Refusing has to ship *before* the format changes to be worth anything |
| **Relations folded into 4 families** | Eloquent has ~11 relation types; a categorical palette cannot carry that many. Polymorphic is also dashed, so family is never colour-alone |
| **Edge labels hidden until hover** | 75 labels at fit-view zoom is noise, not information |
| **Views hold membership, not positions** | One position per model means switching views never rearranges the board, and `layout.json` keeps a single writer |
| **Expanded nodes float rather than re-flow** | The grid is a pure function of node index; growing a card in place would move every model below it out from under the cursor |
| **Routes are a panel, not nodes on the canvas** | A real application has far more endpoints than models, and a payload shape is a tree — it reads badly as a graph node and well in a pane |
| **`routes.json` is fetched, not inlined** | Reflecting every controller costs more than inspecting every model, and the page opens on the graph. Boot stays zero-XHR |
| **Every route is exported, facets narrow it** | Hiding vendor routes on the PHP side means the one time you need to see one, there is no way to |
| **Shapes are parsed, not executed** | Running an application's `toArray()` or an arbitrary `rules()` to document it can have side effects and can fail. The one deliberate exception is `rules()`, tried first and caught |
| **`confidence` is on the wire, not smoothed over** | Response shapes cannot be obtained from the framework at all. An API description that is confidently wrong is worse than one that says what it could not work out |
| **Queueables are found by interface, not by folder** | A job, a queued listener, a mailable and a notification all implement `ShouldQueue` and all reach the same worker. A mailable kept outside `app/Mail` is still a mailable, and a `kind` read from the directory would be a lie |
| **A job's class is read from its own `namespace` line** | Model discovery infers the namespace from one configured path. Jobs scan four, in an application free to put any of them anywhere — four settings to get wrong, when the answer is written at the top of every file |
| **The dispatch scanner matches two node shapes, not an API list** | There are a dozen ways to queue something and there will be more. Looking for a static `dispatch*()` or a `new`, and keeping it only when the class is one discovery found, is `ModelLinker`'s rule: match what exists rather than guess from the call |
| **`queue_source` is on the wire** | A job using `Queueable` cannot declare `public $queue`, so the queue usually comes from the constructor or the dispatch site. Where the answer came from changes how much it can be trusted, and two sites disagreeing is reported as `mixed` rather than resolved |
| **Route ids and paths have one spelling each** | The jobs↔routes join is a string comparison. `RouteExporter::id()` is public and `ProjectPath` is shared for exactly that reason — two definitions that merely agree today is a cross-link that breaks silently |
| **The job list is grouped by queue, not by kind** | The queue is the unit a *worker* is configured in, so it is the one with an operational answer. Grouping by kind would sort the list by what the classes are rather than by where they run |
| **The queue is a page of its own, not a pane on Jobs** | They are two freshness contracts. One is cached against a file signal and true until somebody edits a file; the other is runtime state read on every request. A header reading "6 jobs · 3 waiting" would be mixing two clocks |
| **`/queue.json` is never cached** | A fingerprint cannot describe runtime state, and a queue that looked the same for an hour because a cache said so would be worse than no surface at all |
| **The serialised command is never unserialised** | Restoring it constructs the application's own objects, runs `__wakeup`, and on a `SerializesModels` job hits the database for every model it carries. The JSON envelope answers everything the surface asks |
| **The payload is never reported** | The envelope is safe to describe; the command is argument values and model ids that nobody decided to put on a page |
| **An unreadable driver is reported with its reason** | Two of Laravel's drivers can be enumerated and the rest cannot. Showing an empty queue for SQS would be a lie about an empty queue — and the same rule applies to a failed job store that cannot be read |
| **`records_completions` is on the wire** | Laravel records nothing about a job that succeeded. An empty history means nothing failed, and the client should not have to know that on its own |
| **Highlight is not selection** | Canvas selection is a gesture that feeds view membership; opening an endpoint must not quietly change what a new view would contain |

---

## Extension points

```php
// Support a driver we don't ship, or override how one displays:
TypeNormalizerManager::extend('firebird', fn () => new FirebirdTypes);

// Swap any piece wholesale:
$this->app->bind(ColumnNormalizer::class, MyColumnNormalizer::class);
```

Config (`config/dissect.php`): `enabled`, `path`, `middleware`,
`models_path`, `models_namespace`, `layout_path`, `views_path`,
`routes.watch_paths`, `jobs.paths`, `jobs.watch_paths`, `queue.rows`,
`queue.poll_interval`.

---

## Where to change what

| Task | File |
|---|---|
| Add a surface (page) | `resources/js/pages/registry.ts` — one entry, plus the page component |
| Change a page's own header controls | that page's component, teleporting into `resources/js/lib/toolbar.ts` |
| Change which page opens by default | `DEFAULT_PAGE` in `resources/js/stores/navigation.ts` |
| Support another database's types | `src/Types/` — add a normalizer, register it in `TypeNormalizerManager::make()` |
| Change what a column shows | `src/ColumnNormalizer.php`, then `ModelNode.vue` |
| Change how models are found | `SchemaExporter::discoverModels()` |
| Change what counts as a change | `SchemaExporter::fingerprint()`, `src/MigrationState.php` |
| Change the poll interval | `POLL_INTERVAL_MS` in `resources/js/stores/schema.ts` |
| Change the grid | `resources/js/lib/layout.ts` |
| Change what a view stores | `src/ViewRepository.php`, `sanitiseViews()` in `vite-plugin-persistence.ts`, `resources/js/stores/views.ts` |
| Change how a view is chosen or created | `resources/js/components/ViewMenu.vue` |
| Change what an expanded card shows | `resources/js/components/ModelNode.vue` |
| Change relation colours/families | `resources/js/lib/relations.ts` |
| Support another way of declaring request rules | `src/Routes/RequestAnalyzer.php` |
| Support another response type | `src/Routes/ResponseAnalyzer.php` |
| Change how a rule string is read | `src/Routes/RuleNormalizer.php` (no Illuminate imports) |
| Change how a name resolves to a node id | `src/Routes/ModelLinker.php` |
| Change what counts as a route change | `src/Routes/RouteFingerprint.php`, `routes.watch_paths` |
| Change how the endpoint list is filtered or grouped | `resources/js/lib/routeFilters.ts` |
| Change how the job list is filtered or grouped | `resources/js/lib/jobFilters.ts` |
| Change what a job's detail pane shows | `resources/js/components/JobDetail.vue` |
| Change kind colours, or how a queue's origin is worded | `resources/js/lib/jobKinds.ts` |
| Support another queue driver | `src/Queue/` — a `Contracts\QueueReader`, registered in `QueueReaderFactory::make()` |
| Change what a live queue row reports | `src/Queue/PayloadDecoder.php` |
| Change where the history comes from | `src/Queue/QueueHistory.php` |
| Change how often the queue is polled | `queue.poll_interval` in config |
| Change how many rows a queue section lists | `queue.rows` in config |
| Change verb colours | `resources/js/lib/httpMethods.ts` |
| Change where queueable classes are found | `jobs.paths` in config, then `src/Jobs/JobDiscovery.php` |
| Change what a job reports about itself | `src/Jobs/JobInspector.php` |
| Support another way of queueing something | `src/Jobs/DispatchScanner.php` — `DISPATCH_METHODS`, or the `new` rule |
| Change how a dispatch site links to an endpoint | `src/Jobs/RouteMap.php`, `src/Jobs/ProjectPath.php` |
| Change what counts as a job change | `jobs.watch_paths` in config |
| Change the theme | `resources/js/assets/main.css` (tokens), `vue-flow-theme.css` (canvas) |
| Change the page shell | `resources/views/app.blade.php` |

---

## Known rough edges

- `SchemaExporter` still does discovery, orchestration, relation normalising and
  fingerprinting. `ColumnNormalizer` has been extracted; `ModelDiscovery`,
  `RelationNormalizer` and `Fingerprint` have not.
- `stores/schema.ts` mixes loading, normalising, ordering and placement. The
  pure parts want extracting into `lib/` so they can be unit-tested.
- `stores/routes.ts` mixes loading, filter state and selection, but the pure
  parts are already out in `lib/routeFilters.ts` and unit-tested — which is the
  shape `stores/schema.ts` still wants.
- **Frontend unit tests are thin.** `lib/routeFilters.spec.ts` and
  `lib/jobFilters.spec.ts` are the two; the Playwright suite in `e2e/` is what
  covers the rest.
- Only two drivers have been exercised against a real database (Postgres, via
  `echodms`); the others are covered by string-level checks only.
- **Route parameter binding is read from type hints only.** An explicit
  `Route::model()` or a custom resolver is not followed, so those parameters
  report no model.
- **A rule built by a helper is invisible.** `RequestAnalyzer` reads array
  literals; rules assembled in a variable and returned, or merged in from a
  trait, fall back to whatever the literal says — which may be nothing.
- **`ResponseAnalyzer` does not follow a resource's `with()` or `additional()`**,
  so wrapper keys a payload actually carries are missing from the shape.
- **The Redis reader is not exercised by the suite.** It needs a running
  server, so `tests/Feature/QueueTest.php` covers the database driver and the
  contract both implement. The Redis path is string-level review only — the same
  position the less-common type normalizers are in.
- **A clustered Redis connection is reported as unreadable.** Laravel hash-tags
  the queue key on a cluster through a method that is not public, and reading
  the untagged names would quietly report an empty queue.
- **`RedisQueueReader` enumerates queues with `KEYS`**, which is what the
  framework does when it asks itself the same question, but it is not free on a
  large keyspace.
- **A live row does not say which batch it belongs to.** The batch id lives
  inside the serialised command, which is never opened.
- **A dispatch behind a variable is invisible.** `$job::dispatch()` and
  `dispatch($job)` name a class only the running application knows, so those
  sites are not found and the job may report as undispatched.
- **A queue chosen by a condition is not reported.** `JobInspector` reads only
  the constructor's own statements, so an `onQueue()` inside an `if` leaves the
  queue as `default` rather than claiming one branch of it.
- **Constructing a queueable counts as dispatching it.** The scanner's rule is
  deliberate — see "Jobs" — but a `new SendInvoice(...)` that is genuinely never
  dispatched still reports a site.
- **`DispatchScanner` parses every PHP file under `jobs.watch_paths`**, where
  the route fingerprint only stats them. It is the widest walk the package does,
  which is why the payload is fetched on open and cached against the signal.
- The route fingerprint stats every PHP file under `routes.watch_paths`. On a
  large `app/` that is thousands of stats per check — cheap, but not free, and
  the config exists because narrowing it is sometimes the right answer.
