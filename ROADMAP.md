# 🧭 Roadmap: review fixes, domains, and what comes next

This plan comes from five read-only agent passes over the codebase (three code
reviews, one feature brainstorm, one DDD design). I opened the code myself to
check the high-severity findings, and they are marked ✔︎. Everything else is
the agents' reading. They verified it against the code but nobody ran it,
because no dependencies were installed. Line numbers are from `b7ab62e`.

Effort: **S** ≈ 1–3 days · **M** ≈ 1 week · **L** ≈ 2+ weeks.

---

## 🗺️ Suggested order

| #   | Milestone                                | Why now                                                                                                                       | Size |
| --- | ---------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------- | ---- |
| 1   | **Stability pass** (§1 A–E quick wins)   | Half-saved files, stale cached assets and the queue panel's unbounded reads hurt today, and most hurt *while Claude is editing*. | S–M  |
| 2   | **Identity migration** (§1 F)            | Basename ids block every DDD feature, and they already collide for sub-foldered models. Do it once, with v2 state files.       | M    |
| 3   | **DDD Phase 1: see it by domain** (§2)   | Domain detection plus a `context` field on every payload. This unlocks everything else.                                        | M    |
| 4   | **Snapshots, diff & history** (§3)       | "What did the agent just change, and when?" One snapshot format serves diff, review inbox, history and CI.                     | M    |
| 5   | **Headless CLI + MCP** (§4 #3, #4)       | Lets Claude query dissect and check its own work. Mostly wrapping existing exporters.                                          | M    |
| 6   | **DDD Phase 2: guardrails** (§2)         | Boundary rules plus `dissect:check --files` in a Claude Code hook catch an agent coupling domains as it happens.               | M–L  |
| 7   | **Domain Map + Findings**                | The headline visuals. They build on 3–6.                                                                                       | L    |

Milestones 4/5 and 6 can swap. Pick whichever matters more to you first.

---

## 1. 🧹 Fix first: code review findings

### A. A single broken file takes a whole panel down (HIGH)

`class_exists()` autoloads the file and throws `ParseError` / `Error` outside
any try. An editor autosave or a half-written file from Claude then returns 500
on that surface until the file is fixed, which breaks the "a broken file costs
its own row" rule.

| Where                                              | Fix                                                                                                                    |
| -------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| ✔︎ `src/Jobs/JobDiscovery.php:72`                   | Move `class_exists` inside the `try`, catch `Throwable`                                                                |
| `src/SchemaExporter.php:136`                       | Same                                                                                                                   |
| `src/ProviderTree/ProviderReader.php:99`           | Same                                                                                                                   |
| `src/Routes/RouteExporter.php:74-101`              | One try around everything *drops the route*. Guard each analyzer and emit `request: null` / `response: unknown` instead |
| `src/Routes/RequestAnalyzer.php` (`formRequestClass`) | `class_exists` on parameter types, unguarded                                                                         |
| `src/ProviderTree/ProviderExporter.php:82`         | A failing provider vanishes. Return a row with an `error` field                                                        |

Test: put a syntactically broken fixture under each scan path.

### B. Stale UI after upgrades (HIGH)

| Where                                                                                | Problem                                                                                                     | Fix                                                                                         |
| ------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------- |
| ✔︎ `vite.config.ts:45-47` + `DissectController.php:256-259`                           | Assets are `immutable` for a year, but the filenames carry no hash and Blade emits no `?v=`                  | `dissect-[name]-[hash].js` (the route regex already allows it), plus `?v=` on entry and CSS |
| `DissectController.php:299-369`, `SchemaExporter.php:108-119`                         | Cache keys ignore the package version and config, so changing `DISSECT_MODELS_NAMESPACE` serves the old empty graph for an hour | Add `InstalledVersions` reference + config hash to keys                                     |
| `config/dissect.php`                                                                 | `providers.paths` outside `providers.watch_paths` never moves the signal                                    | Always fold `paths` into the watch set                                                      |
| `resources/js/App.vue:27-31`                                                         | Async pages have no error component, so a chunk mismatch leaves the page blank                              | `errorComponent` with a "reload" hint                                                       |

### C. Queue panel (HIGH → LOW)

| Sev  | Where                                                    | Problem                                                                                                     | Fix                                                                                   |
| ---- | -------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------- |
| HIGH | ✔︎ `src/Queue/QueueHistory.php:50`                        | `$failer->all()` loads every failed job, payload and trace included, every 5 s per tab. Out of memory on real tables | `count()` + a limited, column-selected query for DB failers; `all()` only for file/Dynamo |
| HIGH | ✔︎ `resources/js/pages/QueuePanel.vue:24-29`              | Leave the page before the first read finishes and the 5 s poller starts anyway, never stopped               | Start the watcher synchronously, or check an `unmounted` flag after the `await`       |
| MED  | ✔︎ `src/Queue/RedisQueueReader.php:156`                   | `KEYS queues:*` runs 4× per snapshot and blocks a shared Redis                                              | Compute once per snapshot; use `SCAN`                                                 |
| MED  | same, `Str::between`                                     | Queue names containing `:` get truncated                                                                    | Strip known suffixes instead                                                          |
| MED  | `resources/js/stores/queue.ts:75-113`                    | Switching connection can show the old connection's data for up to 5 s                                       | Request sequence number / `AbortController`                                           |
| MED  | `QueuePanel.vue:34,73`                                   | "Read just now" stays forever when polls fail; the error is never shown after first load                    | Ticking `now` ref + "last read failed" badge                                          |
| MED  | `QueuePanel.vue:141` vs `RedisQueueReader.php:96`        | Redis sends `reserved_until`, the UI reads `reserved_at`, so the column is always "—"                        | Read per row and label it correctly                                                   |
| LOW  | `RedisQueueReader.php:195`, `QueueHistory.php:119`, `lib/elapsed.ts` | `pushedAt` is a microtime string (and possibly a Horizon-only field, unverified); `failed_at` has no timezone, so it is off by the UTC offset | Normalise everything to ISO 8601 on the server |

### D. Saved layout & views

| Sev  | Where                                                         | Problem                                                                                                         | Fix                                                                       |
| ---- | ------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------- |
| HIGH | ✔︎ `resources/js/stores/layout.ts:77-106`                      | `positions.value = next` after the `await` erases drags made during the request; saves aren't serialized      | Don't write back; queue writes like `views.ts` does                       |
| HIGH | `GraphCanvas.vue:289-291`, `stores/schema.ts:114-125`         | Switching or editing a view snaps every dragged node back (Vue Flow re-applies stale `schema.nodes` positions) | Overlay `layout.positions` in `visibleNodes`, or use `hidden: true`       |
| MED  | `src/Concerns/VersionedStateFile.php:125-141`                 | Fixed `.tmp` name, no lock, unchecked writes, so a full disk replaces the good file with a truncated one       | `tempnam` + byte check + `flock`; map I/O errors to `StateFileException`  |
| MED  | `ViewRepository.php:54-63`, `LayoutRepository.php:44-53`      | Whole-file replace: after a `git pull`, an open tab wipes a teammate's new views                               | Content hash round-trip, 409 on mismatch (or merge views by id)           |
| LOW  | `VersionedStateFile.php:106-117`                              | A second corrupt hand-edit is overwritten without a backup                                                     | Timestamp corrupt backups                                                 |
| LOW  | `LayoutRepository.php:62-90`                                  | No caps or id validation; invalid UTF-8 keys cause a 500                                                       | Same regex and caps as views                                              |

### E. Security hardening (MEDIUM)

Path traversal, XSS and container side effects all look fine. The provider
inspection never calls `make()`, there is no `v-html`, and state-file paths come
only from config.

- **No authorization gate.** Add an `Authorize` middleware: always allowed in
  local, otherwise `Gate::check('viewDissect')`, the same way Telescope does it.
- **SQL bindings leak.** `QueueHistory::summarise()` keeps the whole exception line. For a `QueryException`
  that is the SQL **with bound values** (emails, tokens). Cut at `(Connection:` / `SQL:`.
- **Host header is never checked**, so DNS rebinding against `php artisan serve` is plausible. Optionally
  reject requests whose Host doesn't match `APP_URL`.
- **Dev persistence endpoints accept cross-origin POSTs.** `vite-plugin-persistence.ts`
  with `cors: true` lets any site write to them. Check `Origin`, and `req.destroy()` on oversize bodies.
- **The README understates exposure.** It doesn't mention live queue contents or failed-job exceptions.

### F. Identity: blocks DDD (HIGH, do as one migration)

| Where                                                          | Problem                                                                                             |
| -------------------------------------------------------------- | --------------------------------------------------------------------------------------------------- |
| ✔︎ `src/SchemaExporter.php:43,230`, `ViewRepository.php:153`    | Node id = `class_basename`. `Billing\Invoice` and `Sales\Invoice` collide already today, because Finder recurses |
| `config/dissect.php:51-53`                                     | One `models_path` and one namespace, so models under `app/Domain/*/Models` can't be configured       |
| `src/Routes/ModelLinker.php:66-70,92-94`                       | Basename fallback even for a fully qualified hint links a `Billing\Data\Invoice` DTO to the `Invoice` model |
| `src/Routes/RouteExporter.php:119-122`                         | Route id `GET:/` ignores the domain, so multi-tenant routes get duplicate ids (and duplicate Vue keys) |
| `resources/js/lib/routeFilters.ts:146-150`                     | Route groups keyed by controller basename merge `V1\PostController` and `V2\PostController`         |
| `TreeBuilder.php:302-311`, `ActionResolver.php:115-121`        | `str_contains($file, '/vendor/')` marks path-repo domain packages (symlinked into vendor) as vendor  |

**Fix:** use FQCN ids, bump `layout.json` / `views.json` to v2 (migrating basename
keys; `VersionedStateFile` already handles `.bak` and refusal). Add
`models.paths[]` with globs, discovering by type via `ClassFile::classIn`. Put the
domain in route ids, and classify vendor code by PSR-4 roots.

### G. Analysis that is confidently wrong

The design says this must never happen.

| Sev     | Where                                                   | Problem → Fix                                                                                                                         |
| ------- | ------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| MED-HIGH | ✔︎ `src/Routes/RuleSource.php:48`                        | `rules()` runs on a bare **GET** request, so `isMethod('POST') ? … : …` reports the wrong branch as `certain`. Construct with the route's method and resolver, or downgrade when the AST touches `isMethod`/`method`/`input`. Memoise per class. |
| MED     | `RequestAnalyzer.php:198-207`                           | Any `X::make($a, [...])` counts as validation (`View::make`, `Hash::make`). Check that the class is `Validator`                        |
| MED     | `Ast/ClassSource.php:53-63`, `DispatchScanner.php:205`  | Methods that come from traits are invisible (actions, `rules()`, `middleware()`). Locate them by reflection file and line             |
| MED     | `ResponseAnalyzer.php:87-92`                            | An untyped `return XResource::collection()` is reported as a single resource                                                          |
| MED     | `ResponseAnalyzer.php:308-352`                          | The first `->json()` wins, so an early `404` return becomes the response; `$request->json('k', 5)` gives status 5                     |
| MED     | `DispatchScanner.php:143,245-268`                       | `dispatch(new X)->onQueue('y')` loses its modifiers                                                                                   |
| MED     | `DispatchScanner.php:196-231`, `ClassSource.php:106-111` | Nested closures and arrow-function routes break the route join, and `fn () =>` routes are always `unknown`                           |
| MED     | `JsonApi/DocumentAnalyzer.php:79-91`                    | Custom JSON:API actions are reported as a `certain` single resource. Whitelist the known actions                                      |
| MED-LOW | `JsonApi/ServerRegistry.php:83-87`                      | Detection needs the route name to start exactly with the server name, so an `api.` name prefix breaks it                              |
| MED     | `JobExporter.php:96-103`                                | Queued listeners always show "nothing dispatches this". Join them through the event's dispatch sites                                  |
| MED     | `ProviderInspector.php:263-287,196,483-494`             | Helper methods in `register()`, `giveConfig`/`giveTagged`, `$app->make` in closures, `extend`/`alias`/`tag` and `\App::` are not followed |
| LOW-MED | `RuleSource.php:139-175`                                | AST fallback: `'max:'.Post::MAX` breaks `required`, and `Password::defaults()` loses its class                                        |
| LOW     | `RuleSource.php:83-85`                                  | `quietly()` throws on deprecations and on `@`-suppressed errors                                                                       |
| LOW     | `JobInspector.php:146-253`                              | `$this->queue = …`, `parent::__construct()` and a `tries()` method are missed                                                         |

### H. Scale

- **Unbounded AST cache.** `DispatchScanner` + `ClassSource` keep **every AST in a singleton** with no
  eviction, which risks running out of memory on 3–5k-file apps and goes stale under Octane. Pre-filter
  files by job basename, use a non-retaining parse, and scope the cache.
- **Graph layout doesn't scale.** `lib/layout.ts` fixes columns at 5: 400 models make a 27,000 px wide
  ribbon. Use `ceil(sqrt(n))`, `only-render-visible-elements`, and `hidden` instead of re-filtering.
- **Long lists.** Routes and Jobs lists aren't virtualized and search isn't debounced.
- **Too much work per request.** The controller constructor resolves 7 services for every request,
  assets included, and polls write the session (`web` group). Use method injection.

### I. Structure: seams that make domains (and new panels) cheap

- **`SourceTree`:** one paths → files → relative path → *module* walk. Today the same Finder walk is
  copied in `JobDiscovery`, `DispatchScanner` and `RouteFingerprint`, and path
  resolution is written 3× with different behaviour.
- **Move shared code to `Support/`.** `Routes\Ast\ClassSource`, `Routes\ModelLinker` and
  `Routes\RouteFingerprint` are shared infrastructure, not route code.
- **A `Surface` interface** (`key()`, `fingerprint()`, `export()`) plus a registry replaces the four
  copies of fingerprint-plus-`Cache::remember` in the controller.
- **Frontend:** a `defineResourceStore()` factory, with the registry driving the poller,
  fingerprint flags and landing cards. Today a new panel touches about 6 files
  even though `registry.ts` says "an entry here and nothing else" (Providers
  already has no landing card).
- **Duplicates to remove:** `nodeFor`/`bodyOf` are duplicated; `RouteStack` and `CONFIDENCE_NOTE` are each
  defined twice; `PageId` could be derived with `as const`.

### J. Tests & CI

- **CI coverage.** CI runs PHPUnit on SQLite only. Nothing runs vitest, eslint, vue-tsc, Playwright,
  phpstan or pint, and there are no MySQL, Postgres or Redis legs.
- **Untested PHP:** the production gate, the 422/409 views paths, `Types/*`, `ColumnNormalizer`,
  `MigrationState`, the Redis reader, the route fingerprint, and concurrent or failed state writes.
- **Untested frontend:** no store tests at all, and no e2e for drag-then-switch-view or for
  leaving the Queue page mid-read.
- **Config bugs.** `tsconfig.vitest.json` and ESLint only match `__tests__/`, but the specs live in
  `lib/*.spec.ts`. The Playwright CI path (`npm run preview`) has no fixtures. The scripts use
  `npm`, but the repo uses pnpm.
- **Parallel e2e browsers clobber each other.** All three POST the same `public/views.json`, which makes the tests flaky.

---

## 2. 🧩 Domain-driven design

### What dissect needs to know

A new `src/Architecture/` namespace (not `Domain/`, so it isn't confused with the
user's own code):

- **`ClassIndex`:** one php-parser walk with a `NameResolver`. For each class:
  kind, flags, parent, interfaces, traits, attributes (read, never
  instantiated) and outgoing `Reference`s. It shares the parse cache with
  `DispatchScanner`.
- **`Reference { from, to, kind, line, confidence }`.** It covers the syntax kinds
  (import, extends, `new`, static call, type hints, …) and also the
  **Laravel-aware kinds Deptrac can't see**: `relation`, `foreign_key`,
  `table_ref` (`DB::table`, `exists:` rules), `binding`, `dispatch`,
  `event_dispatch` / `event_listen`. That gap is the reason to own the checker.
- **`Context`** (bounded context: name, source, roots, type core/supporting/
  generic/shared-kernel, public namespaces) and **`Layer`** (domain / application /
  infrastructure / interface).
- **`ContextResolver`:** attribute > explicit file mapping > glob > convention >
  unassigned. Its provenance goes on the wire, like `confidence` does.
- **Wire format:** a new on-demand `/contexts.json`, plus a cheap `context` field on
  every existing payload, resolved server-side once. (Not `domain`, which `routes.json` already uses for the host.)
- **Authored state:** `.dissect/contexts.json` (mapping and rules, editable from the UI), plus
  `boundaries.baseline.json` and `architecture.lock.json`.

### Apps that aren't domain-split

Domain features are opt-in by detection. An app with no detectable domains must
look and behave exactly as it does today.

- **Nothing changes until a domain exists.** If no convention matches, there is no
  `.dissect/contexts.json` and no attribute is found, then `contexts` is empty.
  Every payload carries `context: null`, domain chips and filters are not rendered,
  and the Domains page shows an empty state rather than an error.
- **No cost when unused.** `ClassIndex` is only built when the Domains page is
  opened or rules are declared. The per-payload `context` field is a cheap prefix
  match that short-circuits when there are no contexts.
  `architecture.enabled` defaults to `auto`, and `false` turns it all off.
- **No checks run.** `dissect:check` exits 0 with "no domains defined". Boundary
  rules exist only once someone writes them.
- **The identity migration is invisible.** Ids become FQCN, but cards still show
  the basename and `layout.json` / `views.json` migrate automatically (old file
  kept as `.bak`).
- **A way in, never pushed.** The empty state offers "Suggest domains" (Phase 4
  inference) and explains the folder conventions. A layered app with sub-folders
  (`app/Models/Billing`, `app/Http/Controllers/Billing`) is detected as domains
  without moving anything.
- **Test it.** The current workbench app stays the "plain Laravel" fixture, and an
  e2e asserts that no domain UI appears for it. Domain fixtures go in a separate
  workbench folder.

### Where the domain comes from: folders first, attributes as the exception

Folder structure is the source of truth, because it can't drift from where the
file actually lives. A per-class marker is only an override for the odd class
that sits in the wrong place (e.g. a shared `app/Models/User.php` that belongs
to Identity). Use an attribute (`#[Domain('Identity')]`) rather than a
`protected $domain` property: it is readable statically and by reflection
without instantiating, and it adds nothing to the class at runtime.

Overrides can go stale, so dissect checks them. If an override disagrees with
the folder convention, or names a domain that doesn't exist, it is reported as a
finding ("`#[Domain('Billing')]` on a class under `app/Domain/Sales/`"). If
neither the folder nor an override assigns a class, it is listed as Unassigned
rather than guessed.

### Phase 1: see it by domain *(after the §1 F identity migration)*

| Feature                       | What you see                                                                                                                                             | Size |
| ----------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- | ---- |
| **Context detection**         | A domain list with a source badge and class counts, plus "Unassigned: 37 classes". Zero-config detectors for InterNACHI/modular, nwidart, Spatie Beyond CRUD, hexagonal, layer-first sub-folders and `packages/*` | M    |
| **ClassIndex + references**   | Mostly invisible: a "classes analysed" stat and drill-down lists                                                                                          | M–L  |
| **Domain across all panels**  | Domain chips and filters on models, routes, jobs and providers. Read-only per-domain views (with "copy to view"). Cross-domain relation edges drawn distinctly | S–M  |
| **Provider tree awareness**   | Nodes coloured by domain; port → adapter pairs labelled (contract in Domain, concrete in Infrastructure)                                                  | S    |

### Phase 2: catch the agent (guardrails)

| Feature                         | What you see                                                                                                                                                                                                                       | Size |
| ------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---- |
| **Boundary rules + violations** | Per-domain allow-lists, public namespaces, layer rules and framework bans (Domain must not use `Illuminate\Http`), plus Laravel-specific checks: `cross_context_relation`, `_fk`, `_model_access`, `_table`, cycles. A Violations tab and red edges. A baseline keyed by rule, not by line | M–L  |
| **`dissect:check`**             | `--format=text|json|github|junit|agent`, `--files=…`, `--since=origin/main`, `--generate-baseline`. With `--files` it runs in a **Claude Code PostToolUse hook** on Edit/Write                                                         | (in the above) |
| **Coupling lockfile**           | "New coupling since lock: Sales → Billing +3 refs". Catches coupling that is *allowed but new*                                                                                                                                       | S    |
| **CLAUDE.md handoff**           | `dissect:context` writes a managed block (domains, public API, allowed dependencies, where new things go) and per-domain nested `CLAUDE.md` files, plus a ready-made hook snippet                                                     | S–M  |

Default policy: observe-only until a domain declares rules, then deny-by-default for that domain.

### Phase 3: the Domain Map

- **Domain Map panel:** domains as nodes with counts, and directed edges with counts and a
  breakdown by kind. Tiered by instability, so an upward edge visibly breaks the
  Stable Dependencies Principle. Relationship hints: shared kernel, conformist,
  open host, ACL, event-driven (dashed).
- **Coupling metrics:** Ca, Ce, instability, abstractness, distance from main
  sequence, cycles and hub classes.
- **Foreign keys:** `Schema::getForeignKeys()` on model cards and graph edges. This also unlocks the FK rule.
- **Event flow between domains:** publisher → event → listeners. These are the healthy seams.

### Phase 4: help migrating a layered app

- **Domain inference:** deterministic clustering (cascade FK > BelongsTo > morph, with hubs like
  `User` excluded as shared-kernel candidates), plus name-stem and URI-prefix
  attachment for non-model classes. Suggestions only, with reasons, and accepting one writes
  `contexts.json`. `workbench/scripts/generate-huge-models.php` already has 12
  domain vocabularies, which gives free ground truth for a precision test.
- **Pattern badges:** aggregate root, entity, value object, domain vs integration event,
  repository (port/adapter), action, DTO, domain service, query builder and states, each
  with confidence and evidence. Also flags anemic models and fat controllers.
- **Readiness score + move plan:** an ordered plan of class → target path, with an optional
  generated Rector config (dissect never moves files itself). Laravel-specific
  risks: morph `*_type` columns (need `enforceMorphMap`), and class names stored in
  queued and failed payloads. The Queue panel can show "3 jobs of this class are
  queued right now".
- **Interop:** `--deptrac` / `--phparkitect` exports of the namespace-only rules.

---

## 3. 🕰️ Snapshots, diff & history *(your git idea goes here)*

These three features share one snapshot format (exporter arrays keyed by their
existing stable ids) and one semantic-diff engine.

| Feature                    | What you see                                                                                                                                                                                                                                                                                                                                                                     | Size |
| -------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---- |
| **Structural diff**        | A Changes panel: `+ model Refund`, `~ Invoice: +column refunded_at`, `~ POST api/invoices: customer_id now optional`, `~ SendInvoice: tries 3→null`. Each change is classed breaking, risky or additive; changed nodes and rows glow. Baselines: a session ("Start watching"), a committed `.dissect/snapshot.json`, or a git ref. **First slice is client-only:** the stores already re-fetch on fingerprint change, so diff the old payload against the new one | M    |
| **Model & migration history** | A History tab per model: every migration that touched its table, with date (from the filename), table and column operations parsed from `Schema::create`/`table`, ran or pending (from the `migrations` table and its batch), and the git commit and author (`git log --follow`). Hover a column or relation to see its `git blame` line. Commits with an AI co-author trailer are marked. "Show the model as of a date" is rebuilt from migrations and git, with no live DB needed | M–L  |
| **Review inbox**           | Tick "reviewed" on any item. When the agent changes its semantic hash it returns to an Unreviewed inbox, with the diff beside it                                                                                                                                                                                                                                                  | S–M  |
| **Architecture timeline**  | A scrubber over snapshots, one per commit or CI run: growth curves, "which domain grew most this month", and a diff between any two points                                                                                                                                                                                                                                       | L    |

Constraints for history: no git means "history unavailable", never a failure.
Blame is loaded lazily when a card opens and cached against `HEAD`. Dynamic or
raw-SQL migrations are marked `inferred`.

---

## 4. 🚀 Other feature ideas

Ranked by value for keeping an eye on AI-written code.

| #   | Feature                                                                                                                                                                                                                          | Size | Value    |
| --- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---- | -------- |
| 3   | **Headless CLI:** `dissect:export --format=json|md|mermaid`, `dissect:snapshot`, `dissect:diff`, `dissect:check`. Registered under `runningInConsole()`, separately from the viewer gate                                           | M    | high     |
| 4   | **MCP server + Boost guideline:** `dissect_model`, `dissect_route`, `dissect_job`, `dissect_provider_tree`, `dissect_changes`, `dissect_findings`, `dissect_search`. Read-only, trimmed for tokens. It complements Boost's raw schema and route tools with the semantic layer | M    | high     |
| 5   | **Findings panel** with a PHPStan-style baseline, so only *new* smells show. Starter rules: jobs without tries/backoff, mutating routes with no validation or auth, `env()` outside `config/`, request-scoped singletons, unbound interfaces, duplicated FormRequests, FK columns without an index, closure routes, a static N+1 heuristic | M→L  | high     |
| 6   | **Events, listeners & observers graph:** dispatch site → event → listeners, plus a model "lifecycle" view (what happens when an Order is saved?)                                                                                     | M    | high     |
| 7   | **Authorization map:** guard, middleware and policies per route, `authorize(): true` flagged, models with mutating routes but no policy                                                                                             | M    | high     |
| 8   | **Schema ↔ migration ↔ model drift:** unrun migrations, `$fillable`/`$casts` naming missing columns, incompatible casts, factories with unknown attributes                                                                          | S–M  | high     |
| 9   | **Config & env map:** keys and call sites (never values), `env()` outside config, vars missing from `.env.example`                                                                                                                 | S–M  | med-high |
| 10  | **Cmd-K search** across everything                                                                                                                                                                                              | S    | med-high |
| 11  | **"Used by" / impact tab** on any class, plus an orphaned-code state                                                                                                                                                             | M    | med-high |
| 12  | **Mermaid / Markdown export** per view or tree, and a token-budgeted architecture digest for CLAUDE.md                                                                                                                           | S    | med-high |
| 13  | **Test coverage map:** "untested" chips on routes and jobs (static scan of `tests/`)                                                                                                                                               | M    | med-high |
| 14  | **Middleware stacks + outliers:** "this route under `api/invoices/*` lacks `auth:sanctum`, which its 11 siblings have"                                                                                                             | S–M  | med      |
| 15  | **Runtime "observed" overlay** (opt-in): query counts, lazy-load N+1s, response keys seen (never values), job run and fail counts, shape drift against the static inference                                                        | L    | high, riskier |
| 16  | **Schedule & Artisan commands panel**                                                                                                                                                                                             | M    | med      |
| 17  | **Notifications, mail & broadcasting**, as a facet of the events graph                                                                                                                                                           | M    | med      |
| 18  | **Container health strip** on Providers (mostly Findings rules)                                                                                                                                                                  | S    | med      |

---

## ⚖️ Decisions

Recommended option in **bold**.

| #   | Decision                                   | Options                                                                                                                                         | Blocks       |
| --- | ------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------- | ------------ |
| D1  | Model id format                            | **FQCN** · `context/Basename`                                                                                                                   | §1 F         |
| D2  | Source of truth for domains                | **Folder conventions by default + `.dissect/contexts.json` (UI-editable, reviewed in PRs) + attributes only as per-class overrides, checked against the folder** · rules in PHP config (Deptrac style, not UI-editable) · a per-class property/attribute everywhere (rejected: drifts) | Phase 1      |
| D3  | Attribute ownership                        | **Match `#[Domain]` by short name; the app owns the class** · ship a tiny non-dev `kdr-dev/dissect-attributes` package                           | Phase 1      |
| D4  | Class membership                           | **Exactly one domain + an explicit shared kernel** · multiple                                                                                    | Phase 1      |
| D5  | Per-domain views                           | **Derived, read-only, "copy to view"** · materialised into `views.json`                                                                          | Phase 1      |
| D6  | Domain facet on Routes                     | **Yes, chosen on the Routes page, derived from the action class** (keeps "nothing elsewhere narrows endpoints") · no                             | Phase 1      |
| D7  | Own checker vs delegate                    | **Own it** (needs relation, FK, table, binding and dispatch edges) **+ optional Deptrac/PHPArkitect export** · generate configs and delegate     | Phase 2      |
| D8  | Default rule policy                        | **Observe-only until rules are declared, then deny-by-default per domain** · always deny                                                         | Phase 2      |
| D9  | May dissect write files (CLAUDE.md, Rector)? | **Write only between managed markers, behind an explicit flag** · print only                                                                   | Phase 2, 4   |
| D10 | Domain Map placement                       | **Its own page** · a mode of the Models graph                                                                                                    | Phase 3      |
| D11 | Inference                                  | **Suggestion-only, deterministic, seeded**                                                                                                       | Phase 4      |
| D12 | Snapshot baseline default                  | **Committed `.dissect/snapshot.json`** + a session baseline in `storage/` · git ref only                                                         | §3           |
| D13 | Auth gate outside local                    | **`viewDissect` gate, like Telescope** · env flag only (today)                                                                                   | §1 E         |
| D14 | Palette for 8–15 domains                   | **Extend the validated ramp; colour always paired with a label**                                                                                 | Phase 1      |
