# Spora Plugin System

Plugins extend Spora with additional LLM drivers, tools, and recipes. Each plugin is a
self-contained directory deployed alongside the core application.

---

## Directory layout

```
plugins/
└── my-plugin/
    ├── plugin.json        ← required manifest
    ├── Plugin.php         ← entry-point class (default file location)
    ├── src/               ← optional: plugin source code
    └── vendor/            ← optional: plugin's own Composer dependencies
```

Plugins live in the directory configured as the plugin path (default: `plugins/` at the
repo root). Each plugin must occupy its own subdirectory and ship a `plugin.json` manifest.

### Loading from external paths

By default, `PluginLoader` only scans the in-repo `plugins/` directory. Operators can
point Spora at additional directories — e.g. sibling git checkouts of community
plugins — via the `SPORA_PLUGINS_PATHS` env var (or the `plugins_paths` key in
`config.php`).

```bash
# Comma-separated absolute paths. Whitespace is trimmed; empty entries are dropped.
export SPORA_PLUGINS_PATHS="/var/spora-plugins/spora-plugin-minimax,/opt/spora/community-plugins"
```

```php
// config.php (equivalent)
'plugins_paths' => [
    '/var/spora-plugins/spora-plugin-minimax',
    '/opt/spora/community-plugins',
],
```

The in-repo `BASE_PATH/plugins` directory is always scanned first. External paths are
appended in declaration order. If the same `slug` appears in multiple directories, the
first one wins (later manifests with the same slug are silently skipped, matching the
existing duplicate-slug guard). Non-existent directories are silently skipped — never
throw — so an uninstalled optional plugin doesn't break the boot.

---

## plugin.json manifest

The full JSON Schema is in [`plugin.schema.json`](../plugin.schema.json) at the repo root.

### Required fields

| Field   | Type   | Description |
|---------|--------|-------------|
| `slug`  | string | Unique machine-readable identifier. Lowercase alphanumeric, hyphens, and underscores only (`^[a-z0-9][a-z0-9_-]*$`). Must be stable across releases — it is used as the component key in `schema_versions` and as the required prefix for migration filenames. |
| `class` | string | Fully-qualified class name of the plugin entry point. Must implement `Spora\Plugins\PluginInterface`. |

### Optional fields

| Field           | Type   | Description |
|-----------------|--------|-------------|
| `description`   | string | Short human-readable description surfaced by the inventory UI. Max 500 chars. |
| `icon`          | string | Icon for the inventory UI. Three forms are accepted — bundled name, full `<svg>` string, or raw SVG path. Defaults to `"puzzle"` when omitted. Lets a plugin ship its own visual identity without coordinating with the Spora frontend. See [Bundled icons](#bundled-icons) for the curated palette and the three forms in detail. |
| `autoload.psr-4`  | object | PSR-4 namespace → relative path mappings registered with the Composer classloader before the plugin is instantiated. Multiple entries are supported. |
| `autoload.files`  | array  | PHP files to `require_once` before the plugin is instantiated, relative to the plugin directory. Use `["vendor/autoload.php"]` to load the plugin's own Composer dependency tree. Processed after `psr-4` mappings. |

### Minimal example

```json
{
    "slug": "my-plugin",
    "class": "Acme\\MyPlugin\\Plugin"
}
```

### Bundled icons

The Spora frontend ships a curated palette of bundled SVG icons. Plugin authors can reference any of these by name from the manifest's `icon` field without shipping their own SVG. For categories not covered below, fall back to a raw SVG path string (the `icon` field accepts anything starting with a path command letter).

| Category        | Names                                                                       |
|-----------------|-----------------------------------------------------------------------------|
| General / apps  | `puzzle` (default), `brain`, `lightbulb`, `compass`, `globe`, `sparkles`     |
| Documents & data| `file-text`, `database`                                                     |
| Productivity    | `calendar`, `search`, `mail`                                                |
| Media           | `music`                                                                     |
| Tools & code    | `zap`, `code`                                                               |
| UI utility      | `bell`, `check`, `x`, `plus`, `chevron-right/down/left`, `arrow-right`, `menu`, `grid`, `user`, `logout`, `settings`, `sun`, `moon`, `warning`, `pencil`, `trash`, `star`, `clock`, `computer`, `tools`, `file`, `chat`, `agents`, `shield-check`, `user-plus`, `eye`, `lock`, `check-circle`, `info`, `error-circle` |

### Three forms of plugin-supplied icons

The `icon` field in `plugin.json` accepts three forms. The frontend tries them in this order:

1. **Bundled name** — any kebab-case identifier from the table above (or the wider UI palette). Smallest in JSON, no shipping required. Best for the common case.
   ```json
   { "icon": "puzzle" }
   ```

2. **Full `<svg>` string** — a complete `<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">…</svg>` for multi-primitive icons (e.g. circle + path, rect + path). The host's outer `<svg>` tag is discarded and the host's `class`, `fill`, `stroke`, `viewBox`, `stroke-width` win. The inner children are sanitized to a tight allowlist (`path`, `circle`, `ellipse`, `polyline`, `polygon`, `rect`, `g` plus the attributes the template reads) before being rendered via `v-html` — any other tags or attributes are stripped. Use this when you need a lucide icon (or a hand-rolled one) that uses non-`<path>` primitives.
   ```json
   {
     "icon": "<svg viewBox=\"0 0 24 24\" xmlns=\"http://www.w3.org/2000/svg\"><circle cx=\"12\" cy=\"12\" r=\"10\"/><path d=\"m16.24 7.76-1.804 5.411a2 2 0 0 1-1.265 1.265L7.76 16.24l1.804-5.411a2 2 0 0 1 1.265-1.265z\"/></svg>"
   }
   ```

3. **Raw SVG path** — a single path string starting with a path command letter (`M`/`L`/`H`/`V`/`C`/`S`/`Q`/`T`/`A`/`Z`, uppercase or lowercase). Smaller than the full `<svg>` form, but limited to single-path icons.
   ```json
   { "icon": "M15.39 4.39a1 1 0 0 0 1.68-.474 2.5 2.5 0 1 1 3.014 3.015 1 1 0 0 0-.474 1.68l1.683 1.682a2.414 2.414 0 0 1 0 3.414L19.61 15.39a1 1 0 0 1-1.68-.474 2.5 2.5 0 1 0-3.014 3.015 1 1 0 0 1 .474 1.68l-1.683 1.682a2.414 2.414 0 0 1-3.414 0L8.61 19.61a1 1 0 0 0-1.68.474 2.5 2.5 0 1 1-3.014-3.015 1 1 0 0 0 .474-1.68l-1.683-1.682a2.414 2.414 0 0 1 0-3.414L4.39 8.61a1 1 0 0 1 1.68.474 2.5 2.5 0 1 0 3.014-3.015 1 1 0 0 1-.474-1.68l1.683-1.682a2.414 2.414 0 0 1 3.414 0z" }
   ```

If `icon` is omitted, the backend defaults it to `"puzzle"` and the frontend renders the bundled `puzzle` icon. If `icon` is set but matches none of the three forms (typo, non-SVG garbage, etc.), the frontend falls back to the bundled `puzzle` icon — silently, not an error. A whitespace-only `icon` value is treated the same as missing.

**Security note:** Plugin authors are operators with shell access to the Spora host — see § Security. The frontend trust boundary is the plugin manifest itself, not user input. The `<svg>` form is rendered via Vue's `v-html` only on the inner children of a trusted plugin's `<svg>` string, and only after DOMPurify has stripped everything outside the SVG-primitive allowlist. The host's outer `<svg>` tag is discarded and cannot be overridden.

### Full example

```json
{
    "slug": "acme-search",
    "class": "Acme\\Search\\Plugin",
    "description": "Search the public web via the Acme API.",
    "icon": "M11 4a7 7 0 1 1-4.95 11.95l-2.43 2.43a1 1 0 0 1-1.42-1.42l2.43-2.43A7 7 0 0 1 11 4Zm0 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z",
    "autoload": {
        "psr-4": {
            "Acme\\Search\\": "src/",
            "Acme\\Shared\\": "lib/"
        },
        "files": [
            "vendor/autoload.php"
        ]
    }
}
```

---

## Entry-point class

The class named in `class` must implement `Spora\Plugins\PluginInterface`:

```php
namespace Acme\MyPlugin;

use DI\ContainerBuilder;
use Spora\Plugins\PluginInterface;

final class Plugin implements PluginInterface
{
    public function getName(): string { return 'My Plugin'; }

    /** @return array<string, string> */
    public function autoload(): array  { return []; }

    /** @return array<class-string<\Spora\Tools\ToolInterface>> */
    public function tools(): array     { return []; }

    /** @return array<string, class-string<\Spora\Drivers\LLMDriverInterface>> */
    public function drivers(): array   { return []; }

    /** @return string[] */
    public function recipePaths(): array { return []; }

    public function schemaVersion(): int     { return 0; }
    public function migrationsPath(): ?string { return null; }

    public function register(ContainerBuilder $builder): void {}
}
```

**Note:** the plugin system is currently a work-in-progress. The hook methods (`tools()`, `drivers()`, `recipePaths()`, `register()`) are declared on the interface and surfaced by the manifest, but the explicit `PluginLoader → DI container` injection path is not yet fully wired up. New drivers, tools, and recipes contributed via plugins may not take effect without additional glue in `app/Plugins/PluginLoader.php` or direct registration via `config.php`.

To register a new LLM driver via a plugin, return its FQCN from `PluginInterface::drivers()` — see [05_drivers.md](05_drivers.md) for the driver contract and the `llm_driver_classes` container key that plugins are intended to extend.

---

## Database migrations

Plugins that need their own tables declare a schema version and a migrations path:

```php
public function schemaVersion(): int     { return 1; }
public function migrationsPath(): ?string { return __DIR__ . '/../database/migrations'; }
```

Migration files follow the same anonymous-class pattern as core migrations and **must be
prefixed with the plugin slug**:

```
database/migrations/
└── acme-search_000001_create_search_index_table.php
```

```php
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration {
    public function up(): void {
        Capsule::schema()->create('search_index', static function (Blueprint $table): void {
            $table->id();
            $table->string('keyword')->index();
            $table->timestamps();
        });
    }

    public function down(): void {
        Capsule::schema()->dropIfExists('search_index');
    }
};
```

The slug prefix is enforced at install time — `DatabaseSchemaInstaller` throws a
`RuntimeException` if any migration file in the plugin's path lacks the `{slug}_` prefix.

---

## Tool namespacing

Plugin tools are automatically prefixed with their `slug` when sent to the LLM, e.g. a tool with `#[Tool(name: 'web_search')]` in a plugin with slug `acme-search` is exposed to the LLM as `acme-search:web_search`. This ensures plugin tools never collide with core tools or tools from other plugins.

Core tools use their plain `#[Tool(name:)]` value without any prefix.

The Orchestrator derives the prefix automatically from the loaded plugins (via `PluginLoader::getPlugins()` in `app/Agents/Orchestrator.php:1177-1188`) — no changes to the plugin's `#[Tool]` attribute are needed.

## Shipping third-party dependencies

For plugins that depend on external Composer packages, run `composer install --no-dev`
inside the plugin directory before deployment and declare the vendor autoloader in the
manifest:

```json
{
    "slug": "acme-search",
    "class": "Acme\\Search\\Plugin",
    "autoload": {
        "files": ["vendor/autoload.php"]
    }
}
```

Spora will `require_once` the file before instantiating the plugin. The plugin's vendor
tree is completely isolated from the host application's vendor tree.

---

## Manifest validation

`PluginLoader` enforces structural correctness at boot time and throws `PluginLoadFailedException`
for any of the following:

- Invalid JSON in `plugin.json`
- Missing or non-string `slug` field
- `slug` value that does not match `^[a-z0-9][a-z0-9_-]*$`
- Missing or non-string `class` field
- A `class` that cannot be resolved via PSR-4 autoloading (bad `autoload.psr-4` mapping, missing
  composer.json entry, etc.), or that resolves to a class not implementing `PluginInterface`

The exception message includes the manifest path and the declared FQCN so the failure is
straightforward to diagnose from a log line.

The following conditions result in a silent skip rather than a fatal error:

- **Duplicate slug** — a second plugin with the same `slug` as an already-loaded plugin.
- **Duplicate class** — a second plugin manifest pointing to the same entry-point FQCN as an already-loaded plugin.

In both cases the second plugin is quietly ignored. If a plugin appears to be "not found" at
runtime, check that its slug and class are unique across all plugins in the plugins directory.

---

## Security

Plugins are loaded by `Spora\Plugins\PluginLoader` (`app/Plugins/PluginLoader.php`) at boot. They are **not sandboxed** — a plugin runs as ordinary PHP code with full access to the application, the database, the file system, and any decrypted credentials. Only install plugins from sources you trust, and review their `plugin.json` manifest and source before deployment.

For the broader security model (credential encryption, API auth, rate limiting), see `docs/15_security.md`.

### Boot-time stamp cache

`PluginLoader` writes a sha256 stamp to `storage/.plugins_stamp` after each successful
boot. The stamp hashes every discovered manifest (path, mtime, content hash) across
all configured directories. On the next boot with an unchanged stamp, the loader
re-instantiates plugins from a sidecar JSON (`storage/.plugins_stamp.cache.json`),
skipping the manifest re-discovery. This eliminates the per-request cost of N file
reads + N JSON parses for operators with many plugins.

The cache is invalidated automatically when any manifest's path, mtime, or content
changes. It is also invalidated by a corrupt or missing sidecar (the loader falls
back to full discovery and rewrites both files). The stamp path is currently
non-configurable; advanced operators can clear it by removing the two files.

---

## Installing third-party plugins

Spora plugins are distributed as standalone PHP packages. The canonical way to
install one is the `plugin:install` CLI command — it wraps `composer require`
with the `spora-ai/installer` package so the plugin lands in the right place
and its manifest is picked up on the next request. The `plugins/` directory
and `SPORA_PLUGINS_PATHS` env var are still supported as escape hatches for
plugin authors iterating on a sibling git checkout; see the options below.

The canonical reference implementation is
[`spora-ai/spora-plugin-minimax`](https://github.com/spora-ai/spora-plugin-minimax) — it
ships five multimodal tools (image, speech, music, lyrics, video) and a
migration. Use it as a starting point when authoring your own plugin.

### Recommended — `bin/spora plugin:install`

```bash
php bin/spora plugin:install spora-ai/spora-plugin-minimax
php bin/spora spora:install   # applies the plugin's migration
```

`plugin:install` is a thin wrapper around `composer require` that pins the
right install path and runs `--optimize-autoloader`. The plugin's
`plugin.json` manifest is auto-discovered on the next request; its tools are
registered with the orchestrator and surfaced in `GET /api/v1/plugins`.

For development against a sibling git checkout, pass `--path`:

```bash
php bin/spora plugin:install spora-ai/spora-plugin-minimax --path=/abs/path/to/checkout
```

The remaining options are listed under [Plugin CLI commands](#plugin-cli-commands).

### Option A — Drop into `plugins/` (legacy / path-based)

```bash
cd /path/to/your/spora
git clone https://github.com/spora-ai/spora-plugin-minimax.git plugins/minimax
php bin/spora spora:install   # applies the plugin's migration
```

Useful for plugin authors iterating on a plugin before tagging a release, or
when you need the plugin's source visible at a stable path for debugging.
The `plugins/` directory is gitignored by the operator skeleton.

### Option B — External path via `SPORA_PLUGINS_PATHS`

```bash
git clone https://github.com/spora-ai/spora-plugin-minimax.git /opt/spora-plugins/minimax
echo 'SPORA_PLUGINS_PATHS=/opt/spora-plugins/minimax' >> .env
php bin/spora spora:install
```

Useful when you want the plugin's versioning completely decoupled from your
Spora deployment. Multiple paths are supported — comma-separate them:

```bash
SPORA_PLUGINS_PATHS=/opt/spora-plugins/minimax,/srv/spora/community
```

`SPORA_PLUGINS_PATHS` is also accepted via `config.php` under the
`plugins_paths` key (list of absolute paths).

### Install via Composer (`composer require`)

For a minimal install — no CLI wrapper — `composer require` works directly when the
operator skeleton is in use (the bundled `spora-ai/installer` Composer plugin routes
`spora-plugin` packages to `plugins/{$name}/` automatically):

```bash
composer require spora-ai/spora-plugin-tavily:^0.2
composer require spora-ai/spora-plugin-serper:^0.2
composer require spora-ai/spora-plugin-semantic-scholar:^0.1
composer require spora-ai/spora-plugin-worldnews:^0.1
composer require spora-ai/spora-plugin-weather:^0.1
composer require spora-ai/spora-plugin-calendar:^0.1
composer require spora-ai/spora-plugin-email:^0.1
php bin/spora spora:install   # applies the plugin's migration
```

### Install via the Web UI

When the operator has opted in by setting `SPORA_PLUGIN_INSTALL_ENABLED=true` in the
host's environment, admins can install and uninstall plugins from the
**Plugins** page in the admin UI. The UI calls a set of write endpoints under
`/api/v1/plugins/install/*` that wrap the same `Spora\Core\Extension\PluginManager`
that `plugin:install` uses on the CLI. For the request/response shape, gating, and
audit-log behaviour, see [`docs/20_plugin_install_api.md`](20_plugin_install_api.md).

### Updating a plugin

For a CLI-installed plugin, run `php bin/spora plugin:update [package]` (or
`php bin/spora plugin:install … --constraint=…` to pin a version). For a
path-based install, pull the latest changes into the plugin directory and
rerun `spora:install` to apply any new migrations.

The `storage/.plugins_stamp` cache invalidates automatically when the
manifest content changes — no manual cache-bust needed.

### Uninstalling a plugin

1. `php bin/spora plugin:uninstall <package>` for a CLI-installed plugin, or
   drop the plugin directory (or remove the entry from `SPORA_PLUGINS_PATHS`)
   for a path-based install.
2. Manually roll back any plugin-specific migrations (Spora does not
   auto-rollback plugin migrations on uninstall — see
   [Database migrations](#database-migrations)).
3. Optional: drop the plugin's database tables if you don't need the
   historical data.

---

## Plugin CLI commands

Spora ships four `plugin:*` commands in `bin/spora` for Composer-driven
plugin lifecycle management. All four delegate to `Spora\Core\Extension\PluginManager`,
which wraps `composer require`, `composer remove`, `composer update`, and
`composer show --installed` respectively.

| Command | Purpose |
|---------|---------|
| `plugin:install <package>` | Install a plugin from Packagist (or a local path repo via `--path`) |
| `plugin:uninstall <package>` | Run `composer remove` for the given package |
| `plugin:update [<package>]` | Update one plugin, or all of them when no argument is given |
| `plugin:list` | List installed `spora-plugin` packages with version and path |

### `plugin:install`

```bash
php bin/spora plugin:install vendor/package
php bin/spora plugin:install vendor/package --constraint=^1.0
php bin/spora plugin:install vendor/package --path=/abs/path/to/checkout
```

`--constraint` and `--path` are mutually exclusive. `--path` registers the local
checkout as a Composer path repository — useful during plugin development
against a sibling git clone.

### `plugin:list`

```bash
php bin/spora plugin:list
```

Renders a table of every installed Composer package whose type is
`spora-plugin`, with name, version, and filesystem path. Non-plugin
dependencies (e.g. `symfony/console`) are filtered out.

### `plugin:update`

```bash
php bin/spora plugin:update                       # update every installed plugin
php bin/spora plugin:update vendor/package        # update a single plugin
```

Runs `composer update` against the plugin subset (or the entire project when
no package is given).

### `plugin:uninstall`

```bash
php bin/spora plugin:uninstall vendor/package
```

Runs `composer remove`. Note that this does **not** roll back plugin-specific
database migrations — see [Uninstalling a plugin](#uninstalling-a-plugin)
above.
