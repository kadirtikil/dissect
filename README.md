# dissect

Visualise your Eloquent models and their relationships as an interactive graph.

Point it at your models directory and open a page: every model becomes a node
with its columns, every relation becomes an edge. Drag nodes into an
arrangement that makes sense, and the layout is saved to a file you can commit
so your team sees the same picture.

- No build step in your application — the compiled frontend ships with the package.
- No `vendor:publish` required to get running.
- Local-only by default; the viewer exposes your full schema, so it stays off in production unless you switch it on deliberately.

## Requirements

- PHP 8.2+
- Laravel 11.33+, 12.x, or 13.x

Laravel 11.33 is a hard floor: column and relation discovery is delegated to
the framework's own `ModelInspector` (the class behind `artisan model:show`),
which does not exist in earlier releases.

## Installation

Not on Packagist yet, so point Composer at the repository. In your
application's `composer.json`:

```json
{
    "repositories": [
        { "type": "vcs", "url": "git@github.com:kadirtikil/dissect.git" }
    ]
}
```

Then:

```sh
composer require kdr-dev/dissect --dev
```

The service provider is auto-discovered. Visit **`/dissect`** in your local
environment and you're done.

Publishing the config file is optional:

```sh
php artisan vendor:publish --tag=dissect-config
```

## Configuration

Everything is configurable through environment variables, so the published
config file is usually unnecessary.

| Variable | Default | Purpose |
| --- | --- | --- |
| `DISSECT_ENABLED` | unset | Unset means "on in `local` only". Set to `true`/`false` to decide explicitly. |
| `DISSECT_PATH` | `dissect` | URL prefix the viewer is served from. |
| `DISSECT_MODELS_PATH` | `app/Models` | Directory scanned for models. Relative paths resolve from the base path; absolute paths are used as given. |
| `DISSECT_MODELS_NAMESPACE` | inferred | Set this when your namespace does not follow the conventional `app/Models` → `App\Models` mapping. |

Two settings are config-file only:

- **`middleware`** — defaults to `['web']`. Add your auth middleware here if you enable the viewer outside local.
- **`layout_path`** — defaults to `base_path('.dissect/layout.json')`. Deliberately not in `storage/` (gitignored) or `vendor/` (wiped by `composer install`), because the layout is meant to be committed and shared.

### Models outside `app/Models`

For a domain-oriented or monorepo layout, set both the path and the namespace:

```dotenv
DISSECT_MODELS_PATH=src/Domain
DISSECT_MODELS_NAMESPACE="Acme\\Domain"
```

### Enabling outside local

The viewer serves your entire schema and writes a file to your project root.
If you turn it on beyond `local`, gate it:

```php
'enabled' => true,
'middleware' => ['web', 'auth', 'can:view-schema'],
```

## How it works

- **Schema** is built by `SchemaExporter` from Laravel's `ModelInspector` and cached against a fingerprint of your models directory, so the reflection and schema queries run once per model-file change rather than once per request.
- **The page** inlines schema and layout into the initial HTML, so it makes no XHR on boot.
- **Live updates** work by polling that fingerprint — edit a model, and the graph refreshes without a file watcher.
- **Assets** are served straight from the package's `dist/` directory by a route with an allow-list of two filenames. Nothing to publish, nothing to re-publish after an upgrade.

See [ARCHITECTURE.md](ARCHITECTURE.md) for the full design.

## Contributing

The package develops against [Orchestra Testbench](https://packages.tools/testbench),
with a Workbench application in `workbench/`.

```sh
composer install
pnpm install

composer serve      # build the workbench app and serve it
composer test       # PHPUnit
pnpm build          # compile the frontend into dist/
```

### Frontend hot reload

`composer serve:hmr` in one terminal and `pnpm dev` in another runs the real
Laravel host with the frontend served from Vite, so frontend edits hot-reload
while the PHP side stays genuine.

### Releasing

`dist/` is committed on purpose — it is the compiled bundle the Composer
package serves, and the whole promise is one `composer require` with no build
step in the host application. So **every release must rebuild it**:

```sh
pnpm build
git add dist && git commit -m "build: dist for vX.Y.Z"
git tag vX.Y.Z && git push --tags
```

A tag that ships a stale `dist/` ships a stale UI to everyone who installs it.

## License

MIT. See [LICENSE](LICENSE).
