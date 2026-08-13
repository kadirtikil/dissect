# 🔪 dissect

Visualise your Eloquent models and their relationships as an interactive graph.

Point it at your models directory and open a page: every model becomes a node
with its columns, every relation becomes an edge. Drag nodes into an
arrangement that makes sense, and the layout is saved to a file you can commit
so your team sees the same picture.

- 📦 No build step in your application — the compiled frontend ships with the package.
- 🔌 No `vendor:publish` required to get running.
- 🔒 Local-only by default; the viewer exposes your full schema, so it stays off in production unless you switch it on deliberately.
- 🗂️ Save **views** — named subsets of the graph — so a schema too big to read at once can be read one bounded context at a time.
- 🔎 Click any card to expand it: every column, with its key, unique, nullable, guarded, hidden and cast flags.
- 🛣️ Switch to **Routes** for your HTTP surface — every endpoint, what it accepts, what it returns, and which models each one touches.

## 🚀 Quick start

Not on Packagist, so install it straight from GitHub. From your application's
root:

```sh
composer config repositories.dissect vcs https://github.com/kadirtikil/dissect.git
composer require kdr-dev/dissect --dev
```

Then visit **`/dissect`** in your local environment. That's it — the service
provider is auto-discovered, and there is nothing to publish or build. 🎉

> 💡 Composer resolves packages by tag. To track the unreleased tip instead —
> or if no tag has been pushed yet — ask for the branch:
> `composer require kdr-dev/dissect:dev-main --dev`.
>
> 🔑 SSH works too if you prefer it — swap the URL for
> `git@github.com:kadirtikil/dissect.git`.

## ✅ Requirements

- PHP 8.2+
- Laravel 11.33+, 12.x, or 13.x

Laravel 11.33 is a hard floor: column and relation discovery is delegated to
the framework's own `ModelInspector` (the class behind `artisan model:show`),
which does not exist in earlier releases.

## 📦 Installation

The quick start above uses `composer config`, which writes the repository entry
for you. To do it by hand instead, add this to your application's
`composer.json`:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/kadirtikil/dissect.git" }
    ]
}
```

Then:

```sh
composer require kdr-dev/dissect --dev
```

Publishing the config file is optional:

```sh
php artisan vendor:publish --tag=dissect-config
```

## ⚙️ Configuration

Everything is configurable through environment variables, so the published
config file is usually unnecessary.

| Variable | Default | Purpose |
| --- | --- | --- |
| `DISSECT_ENABLED` | unset | Unset means "on in `local` only". Set to `true`/`false` to decide explicitly. |
| `DISSECT_PATH` | `dissect` | URL prefix the viewer is served from. |
| `DISSECT_MODELS_PATH` | `app/Models` | Directory scanned for models. Relative paths resolve from the base path; absolute paths are used as given. |
| `DISSECT_MODELS_NAMESPACE` | inferred | Set this when your namespace does not follow the conventional `app/Models` → `App\Models` mapping. |

Three settings are config-file only:

- **`middleware`** — defaults to `['web']`. Add your auth middleware here if you enable the viewer outside local.
- **`layout_path`** — defaults to `base_path('.dissect/layout.json')`. Deliberately not in `storage/` (gitignored) or `vendor/` (wiped by `composer install`), because the layout is meant to be committed and shared.
- **`views_path`** — defaults to `base_path('.dissect/views.json')`, for the same reason: "the billing models" is worth agreeing on once and reviewing in a pull request.
- **`routes.watch_paths`** — defaults to `['app', 'routes']`. Which directories are watched to notice that the endpoint list has gone stale. Narrow it to the directories that actually hold HTTP code if your `app/` is large.

### 📁 Models outside `app/Models`

For a domain-oriented or monorepo layout, set both the path and the namespace:

```dotenv
DISSECT_MODELS_PATH=src/Domain
DISSECT_MODELS_NAMESPACE="Acme\\Domain"
```

### 🔐 Enabling outside local

The viewer serves your entire schema and writes a file to your project root.
If you turn it on beyond `local`, gate it:

```php
'enabled' => true,
'middleware' => ['web', 'auth', 'can:view-schema'],
```

## 🗂️ Views

A large schema is easier to read a slice at a time. Pick the models you want —
shift-drag the canvas, or tick them in the **View** menu's model list — name the
selection, and it is saved to `.dissect/views.json` as a named view you can
switch to whenever you like.

- ➕ Tick a model in the list to add it to the open view, untick it to take it back out. Changes save as you go.
- 🧭 A model keeps the same position in every view, so switching hides models rather than rearranging the board.
- 🤝 The file is plain JSON and meant to be committed, so your team gets the same views you do. Which one *you* have open is remembered per browser, not in the file.

## 🛣️ Routes

The **Routes** tab is the other half of the same question. The graph says what
your data looks like; this says how you reach it — and, crucially, joins the
two: every endpoint lists the models it touches, and every field says which
column it came from.

```
POST api/invoices                      Request   StoreInvoiceRequest
auth:sanctum · throttle:60,1             customer_id  integer  req  Customer.id
                                         lines[].sku  string   req
Response  resource · 201               Touches
  id          Invoice.id                 Customer ↗  Invoice ↗  InvoiceLine ↗
  customer    object  sometimes  Customer
  lines[]     array   Comment
```

- 🔗 Click a model in **Touches** to jump to it on the graph, ringed and centred. Expand a model card and click **Endpoints** to go back the other way.
- 🔎 Filter by path, route name, controller or verb; facet by method and by whose code it is (`app` / `vendor` / `framework`). Nothing is hidden from the export — the facets narrow it.
- 🗂️ With a saved view open, the endpoint list narrows to the models in it.
- 🐢 `routes.json` is fetched when you first open the tab, not inlined — so the graph still boots with no round trips.

### 🎯 Where the shapes come from

Requests are read from a `FormRequest`'s `rules()` where one is type-hinted, and
from an inline `$request->validate([…])` otherwise. Responses are read from the
return type and the `toArray()` of whatever `JsonResource` it names, nested
resources included.

Nothing in Laravel can be *asked* what an endpoint returns, so some of this is
read from your source rather than run. Each shape says which:

| Confidence | Meaning |
| --- | --- |
| `certain` | The framework itself produced it — `rules()` ran, or the resource declared its model with `@mixin`. |
| `inferred` | Read from the source. Usually `rules()` could not run outside a request, or `PostResource` was assumed to describe a `Post`. |
| `unknown` | The class was found but its shape could not be read. Treat it as incomplete. |

A confidently wrong API description is worse than none, so this is shown rather
than smoothed over.

## 🔍 How it works

- **Schema** is built by `SchemaExporter` from Laravel's `ModelInspector` and cached against a fingerprint of your models directory, so the reflection and schema queries run once per model-file change rather than once per request.
- **Routes** are read from the router itself; the request and response shapes behind them are read from your source with [nikic/php-parser](https://github.com/nikic/PHP-Parser), never by executing it. Cached against its own fingerprint, and only computed once you open the tab.
- **The page** inlines schema, layout and views into the initial HTML, so it makes no XHR on boot.
- **Live updates** work by polling that fingerprint — edit a model, and the graph refreshes without a file watcher. Edit a controller or a form request, and the endpoint list does the same.
- **Assets** are served straight from the package's `dist/` directory by a route with an allow-list of two filenames. Nothing to publish, nothing to re-publish after an upgrade.

See [ARCHITECTURE.md](ARCHITECTURE.md) for the full design.

## 🛠️ Contributing

The package develops against [Orchestra Testbench](https://packages.tools/testbench),
with a Workbench application in `workbench/`.

```sh
composer install
pnpm install

composer serve      # build the workbench app and serve it
composer test       # PHPUnit
pnpm test:unit      # Vitest
pnpm test:e2e       # Playwright
pnpm build          # compile the frontend into dist/

composer serve:huge # serve a generated 122-model schema instead
composer huge:clean # and remove it again
```

The fixture in `workbench/app/Models` is small on purpose — it covers every
relation family and column kind, and the tests assert against it. 🐘
`workbench/app/Http` and `workbench/routes` do the same job for the endpoint
list: a form request whose `rules()` cannot be run, a resource with no model
behind it, a closure route, an invokable controller.
`composer serve:huge` generates the opposite fixture, a schema large enough to
show what layout, the minimap and saved views do under load.

### ⚡ Frontend hot reload

`composer serve:hmr` in one terminal and `pnpm dev` in another runs the real
Laravel host with the frontend served from Vite, so frontend edits hot-reload
while the PHP side stays genuine.

### 🏷️ Releasing

`dist/` is committed on purpose — it is the compiled bundle the Composer
package serves, and the whole promise is one `composer require` with no build
step in the host application. So **every release must rebuild it**:

```sh
pnpm build
git add dist && git commit -m "build: dist for vX.Y.Z"
git tag vX.Y.Z && git push --tags
```

⚠️ A tag that ships a stale `dist/` ships a stale UI to everyone who installs it.

## 📄 License

MIT. See [LICENSE](LICENSE).
