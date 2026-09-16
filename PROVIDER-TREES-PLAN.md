# 🌲 Provider Trees — implementation plan

Read the application's service providers, work out what each one depends on,
and render every provider as a selectable tree.

Branch: `feature/provider-trees`. **One step = one commit.** Each step ends in
something that can be checked — a passing test, a curl, or a page that renders.

Commit style follows this branch: `ADD …`, `UPDATE …`, `BUGFIX …`, `REMOVE …`.

---

## ⚖️ Decisions (settle before the step they block)

Recommended option in **bold**. Tick one when decided.

| #   | Decision                                                            | Options                                                                                                                                                            | Blocks      |
| --- | ------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----------- |
| D1  | Which providers are found                                           | A all `.php` files · **B real `ServiceProvider` subclasses only** · C also framework/package providers (`bootstrap/providers.php`)                                 | Step 3      |
| D2  | How dependencies are extracted                                      | A runtime container · ✅ **B static AST of `register()`/`boot()`** · C B + live container only to _raise_ confidence                                                  | Step 5      |
| D3  | Does the tree follow a bound concrete into _its_ constructor?       | ✅ **Yes, reflection only, depth + node cap, stop at vendor** · No, one level                                                                                         | Step 6      |
| D4  | JSON contract                                                       | **One `/providers.json`, trees inline (like `jobs.json`)** · list + per-provider endpoint                                                                          | Step 8      |
| D5  | Tree layout                                                         | **Hand-rolled tier layout, no new dep** · add `@dagrejs/dagre` / `elkjs`                                                                                           | Step 12     |
| D6  | Live updates via the change poller                                  | **Yes, like routes/jobs** · No                                                                                                                                     | Step 11     |
| D7  | Providers the scanner can only partly read (loops, traits, helpers) | Partial tree with `unknown` nodes · explicit "not fully analysed" banner · ✅ **both**                                                                                    | Steps 5, 13 |
| D8  | Confidence rules                                                    | ✅ `certain` = literal class-string in `bind`/`singleton`/… · `inferred` = reached through a constructor type hint · `unknown` = union/no type/unresolvable | Step 4      |

If D1 might become C later, add an `origin` field (`app` / `vendor` / `framework`)
to the payload in Step 4 now — cheaper than retrofitting it.

---

## 🧹 Before starting

The working tree has uncommitted frontend WIP (`ProviderTree.vue` Vue Flow demo,
`tree/Node.vue`, `ProviderFilters.vue`, `ProviderList.vue`, deleted `Node.vue`)
and an untracked `src/Types/ProviderTree.php`. Commit the frontend WIP as-is
(it gets rebuilt in Step 13) or stash it — your call — so Step 1 starts clean.

---

## Phase 1 — Discovery

### Step 1 · Housekeeping

- Add `.php-cs-fixer.cache` to `.gitignore`.
- Delete `src/Types/ProviderTree.php` — two classes in one file breaks PSR-4, and
  `Types/` belongs to the database type normalizers. Value objects return in Step 4
  under `src/ProviderTree/`.

**Done when:** `git status` shows neither file.
**Commit:** `REMOVE draft ProviderTree types, UPDATE gitignore for php-cs-fixer cache`

### Step 2 · Workbench fixture providers (base set)

Add fixtures under `workbench/app/Providers/`, each covering one construct, plus
the classes they point at (e.g. `workbench/app/Services/`, `workbench/app/Contracts/`):

- `bind(Contract::class, Concrete::class)`
- `singleton(...)`, `scoped(...)`, `instance(...)`
- `when(A::class)->needs(B::class)->give(C::class)`
- `$this->app->register(SiblingProvider::class)`
- a `DeferrableProvider` with `provides()`
- `$bindings` / `$singletons` properties
- side effects only: `Event::listen`, `Gate::define`, `mergeConfigFrom`, `publishes`, `loadMigrationsFrom`
- an `abstract` provider, and a non-provider `.php` file in the folder (discovery must skip both)

Fixtures are scanned, not booted — do **not** add them to `testbench.yaml`.

**Done when:** `composer dump-autoload` is clean and existing tests still pass.
**Commit:** `ADD workbench fixture providers covering each binding construct`

### Step 3 · Resolve providers to classes _(needs D1)_

- Extend `src/ProviderTree/ProviderReader.php` (or add `ProviderDiscovery.php`
  beside it — pick one, don't duplicate) to resolve each file's FQCN the way
  `JobDiscovery::classIn()` does, and keep only non-abstract subclasses of
  `Illuminate\Support\ServiceProvider`.
- Return classes (with their relative path), sorted.
- `tests/Feature/ProvidersTest.php`: finds the fixtures, skips the abstract
  provider and the non-provider file, missing directory → empty list.

**Done when:** `vendor/bin/phpunit --filter ProvidersTest` passes.
**Commit:** `UPDATE ProviderReader to resolve provider classes and skip non-providers`

---

## Phase 2 — Extraction

### Step 4 · Value objects _(needs D8)_

One class per file under `src/ProviderTree/`:

- `ProviderNode` — id, kind (`provider` / `abstract` / `concrete` / `unresolved`), label, class, file, docblock summary, confidence, depth
- `ProviderEdge` — source, target, kind (`bind` / `singleton` / `scoped` / `instance` / `contextual` / `registers` / `resolves` / `injects`)
- `ProviderDescription` — the provider, its nodes, edges, side-effect badges, `deferred`, `provides`
- an enum for node/edge kinds; reuse `src/Jobs/Confidence.php` rather than a new one

Each with a `toArray()` matching the wire shape.

**Done when:** a unit test round-trips one description to an array.
**Commit:** `ADD provider tree value objects`

### Step 5 · ProviderInspector — direct dependencies _(needs D2, D7)_

`src/ProviderTree/ProviderInspector.php`, modelled on `JobInspector` and using
`Routes\Ast\ClassSource` + `nikic/php-parser`:

- walk `register()` and `boot()`: `bind/singleton/scoped/instance`, `when()->needs()->give()`,
  `$this->app->register()`, `make()`/`resolve()`/`app()` with a literal class-string
- read `$bindings` / `$singletons` properties and `provides()` via `ClassSource::returnedArray()`
- record side effects as badges, not edges
- anything it can't follow (non-literal argument, loop, helper method) → an `unknown` marker per D7

**Done when:** `ProvidersTest` asserts the direct edges and badges for every Step 2 fixture.
**Commit:** `ADD ProviderInspector, reads bindings and side effects from register and boot`

### Step 6 · DependencyResolver — recursion _(needs D3)_

`src/ProviderTree/DependencyResolver.php`:

- from each bound concrete, reflect the constructor parameters (never `make()` or instantiate)
- follow type hints; interfaces resolve through the bindings found in Step 5
- guard cycles with a per-branch visited set; cap with `providers.max_depth` (default 4) and `providers.max_nodes`
- stop at `Illuminate\*` and anything outside the app's own namespaces
- union / intersection / untyped / missing class → `unknown` leaf

Add fixtures: a concrete with a nested constructor chain, and a cycle.

**Done when:** tests cover depth cut-off, cycle, vendor stop, and unknown leaf.
**Commit:** `ADD DependencyResolver, follows constructor dependencies with depth and cycle guards`

### Step 7 · Exporter, fingerprint, config, bindings

- `src/ProviderTree/ProviderFingerprint.php` (like `RouteFingerprint`) over `providers.watch_paths`
- `src/ProviderTree/ProviderExporter.php` (like `JobExporter`): discovery → inspect → resolve, returns `{ providers, generated_at }` and `fingerprint()`
- `config/dissect.php`: `providers.watch_paths`, `providers.max_depth`, `providers.max_nodes`; mirror in `WorkbenchServiceProvider`
- bind them in `src/DissectServiceProvider.php` the same way the jobs pieces are

**Done when:** tests assert export shape, and that the fingerprint changes when a watched file changes.
**Commit:** `ADD ProviderExporter and ProviderFingerprint, wire provider config`

---

## Phase 3 — HTTP

### Step 8 · `/providers.json` _(needs D4)_

- `DissectController::providersJson()` modelled on `jobsJson()`: `Cache::remember` keyed by fingerprint, `no-store`
- `routes/web.php`: `GET /providers.json` → `dissect.providers`
- `fingerprintJson()`: accept a `providers` flag like `routes` / `jobs`
- add `providersUrl` to the bootstrap data `index()` inlines

**Done when:** feature test hits the endpoint, `FingerprintTest` covers the flag, and
`curl localhost:8000/dissect/providers.json` returns the workbench fixtures.
**Commit:** `ADD providers.json endpoint and providers fingerprint flag`

---

## Phase 4 — Frontend data

### Step 9 · Types and store

- `resources/js/types/providers.ts` mirroring the PHP payload, reusing `Confidence`
- `resources/js/lib/bootstrap.ts`: `providersUrl`
- rewrite `resources/js/stores/provider.ts` on the `stores/jobs.ts` template: `status`, `error`, `fingerprint`, `shallowRef` list, `load()` / `refresh()`, `selectedId`, `selected` computed

**Done when:** `pnpm type-check` is clean and the list shows real providers.
**Commit:** `UPDATE provider store to load providers.json`

### Step 10 · Filters

- `resources/js/lib/providerFilters.ts` + `providerFilters.spec.ts`, like `jobFilters`
- `ProviderFilters.vue` binds to the store's search; `ProviderList.vue` renders the filtered list with a selected state

**Done when:** `pnpm vitest run providerFilters` passes and typing filters the list.
**Commit:** `ADD provider filters and wire the provider list`

### Step 11 · Live updates _(needs D6)_

- `stores/schema.ts` `watchForChanges()`: request the `providers` flag and refresh the provider store when its fingerprint moves and it is loaded — same as the jobs branch.

**Done when:** editing a fixture provider updates the open page without reload.
**Commit:** `UPDATE change poller to refresh providers`

---

## Phase 5 — Rendering

### Step 12 · Layout _(needs D5)_

- `resources/js/lib/providerLayout.ts`: pure function, provider as root, x by depth, y by order within the tier
- `providerLayout.spec.ts`

**Done when:** spec passes.
**Commit:** `ADD tiered layout for provider trees`

### Step 13 · Tree canvas and nodes

- rebuild `ProviderTree.vue` in TS, borrowing the wiring from `components/graph/GraphCanvas.vue`: nodes and edges computed from the selected provider, no leftover demo or `SpecialEdge`
- rebuild `tree/Node.vue`: import every card part it uses; styling per node kind and confidence; side-effect badges; the click-to-expand docblock with its open state kept in store or node data (a component `ref` resets when Vue Flow replaces the node array)
- `ProviderTreePanel.vue`: loading, error, empty and "nothing selected" states like `JobsPanel.vue`; `PAGE_CONTEXT` teleport with a count; fix `flex grid-cols-2`

**Done when:** selecting each fixture provider renders its tree in the workbench.
**Commit:** `ADD provider tree canvas and node cards`

### Step 14 · End-to-end test

- `e2e/providers.spec.ts` like `e2e/jobs.spec.ts`: open the page, filter, select, see the tree

**Done when:** the Playwright spec passes.
**Commit:** `ADD e2e coverage for provider trees`

---

## Phase 6 — Ship

### Step 15 · Docs

- README: a 🌲 **Providers** bullet beside Routes / Jobs / Queue, plus the new config keys
- ARCHITECTURE.md: a Providers section, the `providers.json` contract, decisions worth not undoing (static only, depth/node caps, vendor stop), known rough edges (loop-built bindings, dynamic class strings)

**Commit:** `UPDATE README and ARCHITECTURE for provider trees`

### Step 16 · Rebuild `dist/`

`dist/` is committed and is what installed apps load. Run `pnpm build` last so the
new `ProviderTreePanel` chunk ships.

**Done when:** `dist/dissect-ProviderTreePanel.js` exists and the page works with `.workbench-dev` removed.
**Commit:** `UPDATE compiled frontend with provider trees`

---

## ⚠️ Risks

- **Export cost** on a cache miss for large apps — hence the `max_nodes` cap alongside depth.
- **Static blind spots** — dynamic class strings and loop- or helper-built bindings stay partial by design (D7).
- **`ClassSource` lives in `Routes\Ast`** though Jobs uses it too. Reuse it; moving it is out of scope.
