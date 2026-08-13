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
  RouteExporter ────────┘ (fetched on demand)  public/routes.json
```

`routes.json` is the odd one out: only its URL is inlined, and the payload is
fetched the first time somebody opens the endpoint list. See "Routes" below.

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
  ├── LayoutRepository        read/write/sanitise the layout file
  ├── ViewRepository          read/write/sanitise the saved views
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

That one is deliberately coarser than the other two: a route can move because
any of four kinds of file changed, so the signal is a file stat over whole
directories. It may move when nothing visible changed — which costs a cache
miss — but it must never sit still when something did, because that is a stale
page nobody knows is stale.

It is also **only computed when asked for**. `/fingerprint` returns the route
half only for `?routes=1`, which the client sends once the endpoint list has
been opened. A session that stays on the graph never pays for the wider walk.

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

---

## Frontend side

```
main.ts                 mounts App; no router (see "Decisions")
└── App.vue             header, mode switch, stats, theme toggle, reset button
    ├── ViewMenu        saved views: switch, edit membership, create, delete
    ├── GraphCanvas     Vue Flow wiring, loading/error states, drag handling
    │   ├── ModelNode   one model: name, table, relation count, columns
    │   └── GraphLegend relation families
    └── RoutesPanel     list ▏ detail
        ├── RouteFilters   search + facet chips with counts
        ├── RouteList      grouped by controller, collapsible
        └── RouteDetail    verbs, middleware, params, request, response, touches
            └── FieldTree  a flat path list rendered as a tree

stores/schema.ts        load → normalise → order → place → filter to the view
stores/layout.ts        saved positions, debounce, prune, persist
stores/views.ts         saved views, active view, persist
stores/routes.ts        lazy load, filter state, selection
stores/ui.ts            which surface is open, persisted per browser
lib/layout.ts           the grid: pure function of node index
lib/relations.ts        relation type → family → colour
lib/httpMethods.ts      verb → colour, on the same ramp
lib/routeFilters.ts     pure predicates + facet counts (the unit-tested part)
lib/bootstrap.ts        the host seam
```

### The two surfaces

`GraphCanvas` stays mounted while the routes panel is open (`v-show`): it owns
the schema load and the change poller, and remounting it would drop the viewport
somebody arranged as well as restarting both. `RoutesPanel` is `v-if`, which is
what makes `routes.json` a fetch somebody asked for.

The cross-link runs in both directions and is what stops this being two screens
that happen to share a header:

- Selecting an endpoint puts its models in `schema.highlighted`. Clicking one
  centres it and switches surface. Highlight is kept **apart from Vue Flow's
  selection**, which is a gesture somebody made on the canvas and feeds view
  membership — conflating them would let opening an endpoint quietly rewrite
  what a new view would contain.
- An expanded `ModelNode` offers `Endpoints · N`, which switches the other way
  with the list filtered to that model.

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

---

## Decisions worth not undoing

| Decision | Reason |
|---|---|
| **`dist/` is committed** | The package serves it directly. One `composer require`, no build step in the host app. `.gitignore` and Tailwind's `@source not` both encode this |
| **Assets served by a route, not `vendor:publish`** | Nothing to re-publish after `composer update` |
| **Standalone Blade page, not Inertia** | No dependency on the host's frontend build, Vue version or Tailwind version; the host's design tokens cannot collide with ours |
| **No vue-router** | The package mounts at an arbitrary prefix, so a path-matching router finds no route and renders nothing |
| **Routes local-only by default** | It exposes the full schema and writes a file |
| **Relations folded into 4 families** | Eloquent has ~11 relation types; a categorical palette cannot carry that many. Polymorphic is also dashed, so family is never colour-alone |
| **Edge labels hidden until hover** | 75 labels at fit-view zoom is noise, not information |
| **Views hold membership, not positions** | One position per model means switching views never rearranges the board, and `layout.json` keeps a single writer |
| **Expanded nodes float rather than re-flow** | The grid is a pure function of node index; growing a card in place would move every model below it out from under the cursor |
| **Routes are a panel, not nodes on the canvas** | A real application has far more endpoints than models, and a payload shape is a tree — it reads badly as a graph node and well in a pane |
| **`routes.json` is fetched, not inlined** | Reflecting every controller costs more than inspecting every model, and the page opens on the graph. Boot stays zero-XHR |
| **Every route is exported, facets narrow it** | Hiding vendor routes on the PHP side means the one time you need to see one, there is no way to |
| **Shapes are parsed, not executed** | Running an application's `toArray()` or an arbitrary `rules()` to document it can have side effects and can fail. The one deliberate exception is `rules()`, tried first and caught |
| **`confidence` is on the wire, not smoothed over** | Response shapes cannot be obtained from the framework at all. An API description that is confidently wrong is worse than one that says what it could not work out |
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
`routes.watch_paths`.

---

## Where to change what

| Task | File |
|---|---|
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
| Change verb colours | `resources/js/lib/httpMethods.ts` |
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
- **Frontend unit tests barely exist.** `lib/routeFilters.spec.ts` is the first
  one; the Playwright suite in `e2e/` is what covers the rest.
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
- The route fingerprint stats every PHP file under `routes.watch_paths`. On a
  large `app/` that is thousands of stats per check — cheap, but not free, and
  the config exists because narrowing it is sometimes the right answer.
