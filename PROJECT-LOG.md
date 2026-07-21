# dissect — build log

A record of what was built, what was decided and why, and what is deliberately
still missing. Written at the point where the app became a Composer package.

> **Publication status: NOT PUBLISHED.**
>
> - **Not on Packagist.** Nothing was submitted to any registry.
> - **No release tag** — Composer resolves packages by tag, so even a pushed
>   repo would not be installable by version.
> - **Nothing pushed by me.** The repo has an `origin`
>   (`gitea.kadir-dev.tech/kadir/eloquent-via.git`, added by you), but no
>   `git push` was run, and none of this work is even committed yet.
> - The only installation anywhere is a local Composer **path repository** in
>   `echodms`, which copies the package from `../dissect` on install.
>
> To actually publish: commit, tag (e.g. `v0.1.0`), push, then submit the repo
> URL to Packagist — or, for a private Gitea, add it as a `vcs` repository in
> the consuming app's `composer.json`.

---

## What it is

A dev tool that renders an application's Eloquent models and their relationships
as an interactive graph. Ships as a Laravel package: one `composer require`, no
frontend build in the host app, no publish step.

---

## Timeline

### 1. Frontend foundation

- Installed Vue Flow (`@vue-flow/core`, `background`, `controls`, `minimap`).
- `vue-demi`'s postinstall was being skipped by pnpm 11, which would leave it
  bound to the wrong Vue version. Allowed in `pnpm-workspace.yaml`.
- Built a demo canvas and verified it rendered before going further.

### 2. UI library — PrimeVue rejected after a licensing discovery

PrimeVue was chosen first. While wiring it up, a `@primeui/license-manager`
dependency turned up in v5 that did not exist in v4. Reading the source:

- `@primevue/core` calls `verifyLicense()` on boot.
- Without a key, status is `unconfigured` → `valid: false` → a red
  **"Invalid PrimeUI License"** banner is injected into a *closed* shadow root,
  hardened against removal (`all:initial`, no semantic id, max z-index).
- The source comment describes this as "anti-honest-user signaling".

Confirmed against PrimeTek's site: **PrimeVue 5 moved to dual licensing.**
Community is free but requires a registered key (revenue/headcount limits,
annual renewal); Commercial is $599/developer. PrimeVue 4.5.5 is the last MIT
release.

**Decision:** dropped PrimeVue entirely. Moved to **shadcn-vue** + Tailwind v4.

Two CLI workarounds were needed: the shadcn CLI reads `tsconfig.json` but this
scaffold puts `paths` in `tsconfig.app.json` (mirrored it), and generated
components fail `vue/multi-word-component-names` (ESLint-ignored as vendored).

### 3. Theme — "grand mage developer"

Deep blue-black base, deep magenta primary, neon yellow accent, in both light
and dark. Notable points:

- Magenta is primary in *both* modes; yellow cannot carry a primary button on a
  light surface (no boundary against white). Yellow lives on accents and rings.
- The `--chart-1..5` categorical ramp was **validated with a script**, not
  eyeballed, against each mode's own surface. Two findings:
  - The dark lightness band is `L 0.48–0.67`, so true neon *cannot* carry chart
    marks. It works for UI accents only.
  - Cyan/teal near hue 195° is gamut-capped in sRGB and kept falling under the
    chroma floor; the teal was moved green-ward to 168°.
- Fixed a pre-existing bug: `--font-sans` was `'Geist Variable'` while the
  Google Fonts import provides `'Geist'` — it had been silently falling back to
  system sans. Added `Geist Mono` for identifiers.

### 4. Rendering the real schema

Three data problems in the export had to be handled:

| Problem | Handling |
|---|---|
| 4 edges point at models absent from `nodes` (`DatabaseNotification`, `Passkey`) | Rendered as dashed "not in scan" placeholders rather than silently dropped |
| 12 model pairs share a source+target | Edge ids are `source--name--target`, or Vue Flow dedupes them away |
| 7 self-referencing edges (`MorphTo`, `Folder.parent`) | Excluded from layout maths (they distort it), still rendered |

Eloquent has ~11 relation types, more than a categorical palette can carry, so
they fold into four families (`Has`, `Belongs to`, `Many to many`,
`Polymorphic`). Polymorphic is also **dashed**, so family is never colour-alone,
and a legend is always on screen.

Edge labels are hidden until hover — 75 labels at fit-view zoom was unreadable
noise, and this was the single biggest legibility win.

### 5. Layout

Started with dagre, then replaced it with a **deterministic grid** (columns of
5) on request. dagre was uninstalled. Node height is estimated from the column
count so tall nodes push their neighbours down instead of overlapping —
verified with 0/2/5/8/14-column nodes: zero overlaps.

### 6. Layout persistence

Node positions persist to a JSON file, deliberately **separate from
`schema.json`**: the schema is *generated* and clobbered on every export, the
layout is *authored* by a human. Verified that re-exporting the schema leaves a
hand-arranged layout intact.

Includes: 400 ms debounce, atomic write-then-rename, pruning of models that no
longer exist, and a 3 px drag threshold so a stray click cannot silently pin a
node.

A two-step **Reset layout** button clears the file and re-flows nodes to the
grid.

### 7. Exporter → `ModelInspector`

Replaced the hand-rolled exporter with Eloquent's own `ModelInspector` (the
class behind `artisan model:show`). This gives real column types, nullability,
uniqueness, casts, accessor detection and relation reflection for free — the
previous version emitted placeholder columns.

- **`ModelInspector` is public API, but the `ModelInfo` it returns is marked
  `@internal`.** It is consumed via `toArray()` and normalised immediately, so a
  reshape upstream only touches two methods.
- Relations went 75 → 73. Not a regression: the two dropped are
  `User::readNotifications` / `unreadNotifications`, which are
  `$this->notifications()->read()` — filtered *aliases* of one `MorphMany`.
- Output is sorted, so the committed file diffs cleanly.

### 8. Packaging

Restructured this repo into the package. Frontend source moved to
`resources/js`; PHP added alongside.

```
composer.json                 kdr-dev/dissect, auto-discovery
src/DissectServiceProvider.php
src/SchemaExporter.php        ModelInspector + model discovery + fingerprint
src/LayoutRepository.php      read/write/sanitise the layout file
src/Http/Controllers/DissectController.php
routes/web.php                page, schema.json, fingerprint, layout, assets
config/dissect.php
resources/views/app.blade.php standalone page, schema inlined
resources/js/                 the Vue app
dist/                         committed build output, served by route
```

Key decisions:

- **Assets are served by a route, not `vendor:publish`.** One `composer require`
  is the whole install; nothing to re-publish after an update.
- **Standalone page**, not Inertia. No dependency on the host's frontend build,
  Vue version, or Tailwind version, and the host's design tokens cannot collide
  with the viewer's.
- **Routes are local-only by default** (`config('dissect.enabled')`). The
  tool exposes the full schema and writes a file.
- **Layout lives at `base_path('.dissect/layout.json')`** — not `vendor/`
  (wiped by composer) and not `storage/` (gitignored, which would destroy the
  point of a committable layout).
- **Minimum Laravel is `^11.33`**, established by bisecting release tags:
  `ModelInspector` is absent in 11.32.0 and present in 11.33.0.
- The same bundle runs in two hosts: under the package it reads an inlined
  `window.__DISSECT__`; standalone under Vite it falls back to fetching
  JSON files.

### 9. Auto-refresh, finished

`/fingerprint` had existed since packaging but nothing polled it, and it only
hashed model-file mtimes — half the graph. Columns come from the live database,
so the missing half was migrations.

The subtlety is *which* migration event counts. Watching the migration
**files** would be wrong: a written-but-unrun migration describes columns that
do not exist, and re-exporting on save would render a schema no database has.
The right signal is the `migrations` **table** — Laravel writes that row only
after `up()` returns, so its contents are precisely "what ran successfully",
and a migration that threw part-way leaves nothing behind. `MigrationState`
reads row count plus max id (the id alone misses a rollback; the count alone
misses a rollback followed by a different migration) on the default connection,
and swallows every error, since an unmigrated or unreachable database is a
normal state for this tool rather than a crash.

The client polls every 3s, paused while the tab is hidden and checked
immediately on return — editing models usually happens with the tab in the
background. Updates go through `applySchema`, the same path as the initial
load, which was written for exactly this: existing models keep their slot and
saved positions, so a live re-export never reshuffles a hand-arranged board. A
failed refresh keeps the working graph on screen and does not advance the
stored fingerprint, so the change is retried rather than lost.

Verified live against the workbench: writing a migration moved nothing; running
it changed the fingerprint and `subtitle` appeared on `Post` with no restart;
adding a `hasMany` to a model produced the new edge the same way.

---

## Bugs found and fixed

Most of these were only findable by running the thing.

| Bug | Consequence |
|---|---|
| Vite watches `public/`, where layout saves were written | **Every node drag reloaded the page.** Excluded the file from the watcher; `schema.json` stays watched deliberately |
| A JS comment contained the word `@json` | Blade compiled it as a directive → `json_encode(, 15, 512)` → page 500 |
| `vue-router` matched `/`, package serves `/dissect` | No route matched, canvas never mounted. Router removed (−23 kB) |
| `dist/` was gitignored | Published package would ship with **no assets** and 404 |
| Vite copies `public/` into `dist/` | Package would have shipped **a real application's `schema.json`** — a data leak |
| Un-ignoring `dist/` made Tailwind scan its own bundle | CSS grew 41.6 → 53.7 kB and would grow every build. Fixed with `@source not` |
| Hidden edge labels stayed hit-testable | Invisible labels stole pointer events; hover-to-reveal silently didn't work |
| `stroke-width` set inline by Vue Flow | Stylesheet rules were silent no-ops; needed `!important` |
| `:hover` and `.selected` had equal specificity | A clicked edge rendered as merely hovered while the cursor sat on it |
| Selected edge sat mid-DOM among 75 sibling `<svg>` wrappers | Its glow was overdrawn; lifted with `:has()` |
| Micro-drags persisted positions | A 1 px stray click pinned a node, excluding it from grid re-flow |
| Vue Flow's theme hardcodes light colours | Canvas stayed white in dark mode; bridged its variables to the design tokens |
| e2e suite hard-coded `75 relations` | Broke on every legitimate re-export; now pattern-matched |
| Playwright reused a server on port 5173 | **The suite was testing a different Laravel app entirely.** Pinned to 5180 with `--strictPort` |

---

## Current state

Passing: `pnpm lint`, `pnpm type-check`, `pnpm build`, `pnpm test:e2e`
(2 tests), and the package serving live from `echodms` at `/dissect`.

Verified in the package host: 20 models, 73 relations, 2 external placeholders,
176 columns, zero XHR on load, no console errors, drag → POST → file written →
restored on reload.

---

## Not done

- **Not published.** No remote, no tag, no Packagist entry.
- **No README** for package consumers.
- **Frontend unit tests.** Vitest is installed and unused; the PHP side now has
  a Testbench suite, the Vue side still has only the e2e smoke tests.
- **FK ownership data is not exported.** `Schema::getForeignKeys()` exposes
  `on_delete: cascade`, which is the real ownership signal for any future
  grouping/clustering work.
- **Node type labels get truncated** at 200 px — `character varying(255)` is
  long. Needs abbreviation, wider nodes, or type-on-hover.
- **Column cap of 8** is a guess; real tables often run 15–25.
- **Saving is dev-only in the standalone Vite host** (the endpoint is a
  dev-server middleware). Under the package it works normally.
- **`echodms` was modified for testing** — a path repository and a `--dev`
  requirement were added to its `composer.json`.

---

## Open questions

- Grouping/clustering was discussed and deferred. `User` has degree 25, so any
  modularity algorithm produces an arguable result; the agreed direction is
  manual layout (done) with clustering as an optional "auto-arrange" later.
- Whether to keep Pinia, now that it holds two real stores (it does).
- Whether the Google Fonts `@import` should be self-hosted via `@fontsource`.
