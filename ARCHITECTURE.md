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

### The two frontend hosts

`pnpm dev` on its own still works and is the fastest loop for pure styling work,
but it reads fixture JSON from `public/` and never exercises the PHP.

---

## The shape of it

A Laravel package that renders an application's Eloquent models as an
interactive graph. The PHP side reads the schema; the Vue side draws it. They
meet at exactly two data contracts, described below.

The whole thing runs in **two hosts**, and the same JavaScript bundle serves
both:

```
  PACKAGE (production path)              STANDALONE (development only)
  ─────────────────────────              ─────────────────────────────
  GET /dissect                      pnpm dev
        │                                      │
  SchemaExporter ─┐                      public/schema.json
  LayoutRepository┘                      public/layout.json
        │                                      │
  app.blade.php                          index.html
        │                                      │
  window.__DISSECT__  ◄── seam ──►  fetch()
        │                                      │
        └──────────► main.ts ◄─────────────────┘
```

`resources/js/lib/bootstrap.ts` is that seam. It returns whatever the server
inlined, or an empty object; every consumer treats the fields as optional and
falls back to fetching. Nothing else in the app knows which host it is in.

---

## PHP side

```
DissectServiceProvider    wiring; registers routes only when enabled
  ├── SchemaExporter          orchestrates: discover → inspect → normalise
  │     ├── ModelInspector    (Laravel's own, needs ^11.33)
  │     └── ColumnNormalizer  attribute rows → the viewer's column shape
  │           └── TypeNormalizerManager
  │                 └── {Postgres,MySql,Sqlite,SqlServer,Generic}TypeNormalizer
  ├── LayoutRepository        read/write/sanitise the layout file
  └── DissectController   page, schema JSON, fingerprint, asset serving
```

### Request lifecycle

1. `DissectController::index()` asks for the schema.
2. The schema is cached under `dissect.schema.{fingerprint}`, where the
   fingerprint is the newest mtime across the model directory. Inspecting every
   model costs reflection plus a schema query each, so this happens once per
   model-file change rather than once per request.
3. Schema, layout, save URL and CSRF token are inlined into the Blade view.
4. The page boots with **zero XHR**.

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
framework or touching a database.

---

## Frontend side

```
main.ts                 mounts App; no router (see "Decisions")
└── App.vue             header, stats, theme toggle, reset button
    └── GraphCanvas     Vue Flow wiring, loading/error states, drag handling
        ├── ModelNode   one model: name, table, relation count, columns
        └── GraphLegend relation families

stores/schema.ts        load → normalise → order → place
stores/layout.ts        saved positions, debounce, prune, persist
lib/layout.ts           the grid: pure function of node index
lib/relations.ts        relation type → family → colour
lib/bootstrap.ts        the host seam
```

### How a node gets its position

1. `layoutGraph()` assigns a grid slot — columns of `COLUMN_SIZE` (5), with row
   height derived from each node's column count so tall nodes push their
   neighbours down instead of overlapping.
2. Any position in `layout.json` **overrides** that slot.
3. `slotOrder` remembers which model held which slot, so a re-export adds new
   models at the end instead of reshuffling the board.

The grid deliberately ignores edges: position is a pure function of index, so
the arrangement never shifts because a relation was added.

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

### `layout.json` (authored — safe to commit and review)

```json
{ "version": 1, "positions": { "User": { "x": 957, "y": 780 } } }
```

Kept separate from the schema on purpose: the schema is regenerated and would
clobber it. Stored at `base_path('.dissect/layout.json')` — not `vendor/`
(wiped by composer) and not `storage/` (gitignored, which would defeat the point
of a shareable layout).

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

---

## Extension points

```php
// Support a driver we don't ship, or override how one displays:
TypeNormalizerManager::extend('firebird', fn () => new FirebirdTypes);

// Swap any piece wholesale:
$this->app->bind(ColumnNormalizer::class, MyColumnNormalizer::class);
```

Config (`config/dissect.php`): `enabled`, `path`, `middleware`,
`models_path`, `layout_path`.

---

## Where to change what

| Task | File |
|---|---|
| Support another database's types | `src/Types/` — add a normalizer, register it in `TypeNormalizerManager::make()` |
| Change what a column shows | `src/ColumnNormalizer.php`, then `ModelNode.vue` |
| Change how models are found | `SchemaExporter::discoverModels()` |
| Change the grid | `resources/js/lib/layout.ts` |
| Change relation colours/families | `resources/js/lib/relations.ts` |
| Change the theme | `resources/js/assets/main.css` (tokens), `vue-flow-theme.css` (canvas) |
| Change the page shell | `resources/views/app.blade.php` |

---

## Known rough edges

- `SchemaExporter` still does discovery, orchestration, relation normalising and
  fingerprinting. `ColumnNormalizer` has been extracted; `ModelDiscovery`,
  `RelationNormalizer` and `Fingerprint` have not.
- `stores/schema.ts` mixes loading, normalising, ordering and placement. The
  pure parts want extracting into `lib/` so they can be unit-tested.
- **No unit tests exist**, in either language, despite Vitest and Testbench both
  being installed. The extractions above are what make them writable.
- Only two drivers have been exercised against a real database (Postgres, via
  `echodms`); the others are covered by string-level checks only.
