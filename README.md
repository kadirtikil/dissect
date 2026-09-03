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
- 🛣️ Switch to **Routes** for your HTTP surface — every endpoint, what it accepts and what it returns.
- ⏱️ Switch to **Jobs** for everything that reaches a worker — which queue it lands on, how hard it retries, what it carries, and the endpoint that dispatches it.
- 📡 Switch to **Queue** for what is on it *right now* — waiting, running, due next, and what failed. Live, polled, and never cached.

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

The rest are config-file only:

- **`middleware`** — defaults to `['web']`. Add your auth middleware here if you enable the viewer outside local.
- **`layout_path`** — defaults to `base_path('.dissect/layout.json')`. Deliberately not in `storage/` (gitignored) or `vendor/` (wiped by `composer install`), because the layout is meant to be committed and shared.
- **`views_path`** — defaults to `base_path('.dissect/views.json')`, for the same reason: "the billing models" is worth agreeing on once and reviewing in a pull request.
- **`routes.watch_paths`** — defaults to `['app', 'routes']`. Which directories are watched to notice that the endpoint list has gone stale. Narrow it to the directories that actually hold HTTP code if your `app/` is large.
- **`jobs.paths`** — defaults to `['app/Jobs', 'app/Listeners', 'app/Mail', 'app/Notifications']`. Where queueable classes are looked for. There is no namespace setting to match: each class is read from the `namespace` line in its own file, so an unconventional layout just needs the directory adding.
- **`jobs.watch_paths`** — defaults to `['app', 'routes']`. Where dispatch sites are looked for, and the change signal for the job list. This is the widest walk the package does — every file is *parsed*, not just stat'd — so narrowing it is worth more here than anywhere else.
- **`queue.rows`** — defaults to `50`. How many jobs each section of the live queue lists. The counts are always exact: a queue 40,000 deep reports 40,000 and shows you the first page.
- **`queue.poll_interval`** — defaults to `5000` (ms). How often the queue tab re-reads while it is open.

### 📁 Models outside `app/Models`

For a domain-oriented or monorepo layout, set both the path and the namespace:

```dotenv
DISSECT_MODELS_PATH=src/Domain
DISSECT_MODELS_NAMESPACE="Acme\\Domain"
```

### 🔐 What actually keeps this off your production boxes

Worth being precise, because it is easy to assume more protection than there
is. The provider is auto-discovered and boots on **every request in every
environment** where the package is installed. One thing decides whether the
routes register:

```php
config('dissect.enabled') === null
    ? app()->environment('local')   // the default
    : (bool) config('dissect.enabled');
```

So the gate is **`APP_ENV`**. Two things have to hold, and dissect controls
neither of them:

1. Production deploys with `composer install --no-dev`, so a `--dev` install is
   genuinely absent. This is your deploy command, not something the package can
   enforce.
2. `APP_ENV` is not `local` there, and `DISSECT_ENABLED` was not left switched
   on after somebody debugged something.

Break either — a staging box left at `APP_ENV=local`, a deploy that installs dev
dependencies — and the viewer is live. Unauthenticated, it serves your full
schema, every route, every validation rule, and accepts two POSTs that write
into your project root.

If you enable it anywhere but your own machine, the middleware is the control
that holds when the environment check does not:

```php
'enabled' => true,
'middleware' => ['web', 'auth', 'can:view-schema'],
```

### 🧷 Upgrades and your files

`.dissect/layout.json` and `.dissect/views.json` are yours, and they live in
your project rather than `vendor/`, so `composer update` cannot touch them.

Both files record the format version they were written in, and dissect will not
rewrite a file written by a **newer** version than the one you are running — it
reads it, renders what it understands, and refuses the save with a message
rather than quietly replacing your layout with the subset it recognised. If a
future release does change the format, your file is copied to
`.dissect/layout.json.v1.bak` before the first migrating write. An unparseable
file is copied to `.dissect/layout.json.corrupt.bak` before being replaced.

Nothing here needs an upgrade step from you. It is worth knowing the refusal
exists, so that "layout not saved" on a machine running an older dissect reads
as a version mismatch rather than a bug.

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
your data looks like; this says how you reach it: every endpoint, what a
request must carry, and what comes back.

```
POST api/invoices                      Request   StoreInvoiceRequest
auth:sanctum · throttle:60,1             customer_id  integer  req
                                           exists:customers,id
Response  resource · 201                 lines[].sku  string   req
  id          scalar
  customer    object  sometimes
  lines[]     array
```

- 🧭 Endpoints stand on their own. They are not tied to the model graph, nothing on the graph narrows this list, and a rule that names a table is shown as written — `exists:customers,id` says what it says.
- 🔎 Filter by path, route name, controller or verb; facet by method and by whose code it is (`app` / `vendor` / `framework`). Nothing is hidden from the export — the facets narrow it.
- 👀 Every route always shows. Whatever you have selected or scoped elsewhere, the endpoint list is the whole table until *you* filter it here.
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
| `inferred` | Read from the source. Usually `rules()` could not run outside a request, or the resource never declared what it wraps. |
| `unknown` | The class was found but its shape could not be read. Treat it as incomplete. |

A confidently wrong API description is worse than none, so this is shown rather
than smoothed over.

## ⏱️ Jobs

The **Jobs** tab answers the question a `202 Accepted` leaves behind: what is
going to happen next, where, and carrying what. Jobs, queued listeners,
mailables and queued notifications all end up on the same worker, so they are
all here — found by the `ShouldQueue` interface rather than by which folder they
sit in.

```
SendInvoice                            Queue     invoices
Job                                      set in the constructor
                                       Retries   3 tries · 10, 60s · 120s timeout
Payload                                Handling  batchable · after commit
  invoice   App\Models\Invoice
            carries Invoice            Dispatched by                          2
                                         InvoiceController@store  dispatch()
Carries                                  app/Http/…/InvoiceController.php:61
  Invoice ↗                              POST:api/invoices ↗
```

- 🔗 **Dispatched by** links back to the endpoint that queues the job, and **Carries** jumps to the model on the graph. Expand a model card and click **Jobs** to go the other way.
- 🧭 Grouped by queue, because the queue is the unit you point a *worker* at.
- ⌀ Anything nothing was found to dispatch is flagged — dead code, or dispatched dynamically. It is the finding you cannot get from `queue:work`.
- 🔎 Facet by kind and by queue; filter by name, class, queue or what dispatches it.
- 🐢 `jobs.json` is fetched when you first open the tab, for the same reason `routes.json` is.

### 🎯 Where the queue name comes from

A job that uses Laravel's `Queueable` trait **cannot** declare a `public $queue`
property — PHP rejects it as an incompatible redefinition — so most jobs name
their queue somewhere else. All of those places are read, and the tab says which
one answered:

| Source | Meaning |
| --- | --- |
| `property` | A declared `$queue`. The usual form for a queued listener, which inherits nothing that claims the name. |
| `constructor` | `$this->onQueue('…')` among the constructor's own statements. Not one inside an `if`: a queue that depends on the arguments is not one this can report. |
| `dispatch` | The class named none, and exactly one dispatch site chained `->onQueue('…')`. |
| `mixed` | Two sites named two different queues. Picking one would invent a fact. |
| `default` | Nothing anywhere named one. |

Nothing is executed to find this out. `backoff()` and `middleware()` are read as
source too — a `middleware()` returning `new WithoutOverlapping($this->order->id)`
on an unconstructed job would be a fatal error, and describing your code must
never be able to cause one.

## 📡 Queue

Every other tab describes your code. This one describes your queue, right now —
so it is polled rather than fetched, never cached at either end, and it is the
only page here that goes stale the moment you look away.

Three tenses, because that is how anyone asks about a queue:

```
database                         LISTENERS 1   MAIL 1   PUBLISHING 2

Now      Waiting   4    PublishPost      publishing   0/3   6m ago
         Reserved  1    PublishPost      publishing   1/3   taken 6m ago
Next     Delayed   2    PruneComments    maintenance  0/3   in 13m
Past     Failed    2    WeeklyDigest     mail         3/3   4h ago
                        TransportException: Connection refused
         Batches   2    Republish every post   86/120   2 failed   running
```

- 🔗 Every row links to the job class on the **Jobs** tab — the same class, from the other side.
- 🚦 `database` and `redis` can be listed. SQS, `sync`, `null` and a missing `jobs` table each say **why** they cannot be, rather than showing you an empty queue.
- 🔀 An app with more than one queue connection gets a switcher; failures are readable even when the queue itself is not.
- ⏸️ Polling stops when you leave the tab and when the browser tab is hidden. Nobody's queue is scanned for a page nobody is looking at.

### 🕳️ The gap you should know about

**Laravel keeps no record of a job that succeeded.** It is queued, it runs, it
is deleted, and nothing anywhere remembers it — which is precisely why Horizon
exists. So "what was in the queue" means *what failed* and *what was batched*,
and an empty history means nothing failed, not that nothing ran. The tab says
this on the page rather than letting you infer it from a blank list.

### 🔒 What it will not do

The queue payload carries `data.command` — a serialised instance of your job.
dissect **never unserialises it**. Doing so would construct your objects, run
their `__wakeup`, and on a `SerializesModels` job go to the database for every
model it carries; a tool that describes a queue must not be able to change one.
Only the JSON envelope is read, and the payload itself is never put on the page.

## 🔍 How it works

- **Schema** is built by `SchemaExporter` from Laravel's `ModelInspector` and cached against a fingerprint of your models directory, so the reflection and schema queries run once per model-file change rather than once per request.
- **Routes** are read from the router itself; the request and response shapes behind them are read from your source with [nikic/php-parser](https://github.com/nikic/PHP-Parser), never by executing it. Cached against its own fingerprint, and only computed once you open the tab.
- **Jobs** are found by interface across the configured directories, then every file under `jobs.watch_paths` is parsed for the places each one is dispatched — again read, never run. Its own fingerprint, its own cache, and only computed once you open the tab.
- **The queue** is the one thing here that is not derived from your source, so it is the one thing that is never cached: `queue.json` is read on every request and the page polls it while it is open.
- **The page** inlines schema, layout and views into the initial HTML, so it makes no XHR on boot.
- **Live updates** work by polling that fingerprint — edit a model, and the graph refreshes without a file watcher. Edit a controller or a form request, and the endpoint list does the same; add a dispatch, and so does the job list.
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
