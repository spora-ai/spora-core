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
export Spora_PLUGINS_PATHS="/var/spora-plugins/spora-plugin-minimax,/opt/spora/community-plugins"
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
| `file`          | string | Relative path (from the plugin directory) to the PHP file that declares the entry-point class. Defaults to `Plugin.php` when omitted. |
| `autoload.psr-4`  | object | PSR-4 namespace → relative path mappings registered with the Composer classloader before the plugin is instantiated. Multiple entries are supported. |
| `autoload.files`  | array  | PHP files to `require_once` before the plugin is instantiated, relative to the plugin directory. Use `["vendor/autoload.php"]` to load the plugin's own Composer dependency tree. Processed after `psr-4` mappings. |

### Minimal example

```json
{
    "slug": "my-plugin",
    "class": "Acme\\MyPlugin\\Plugin"
}
```

### Full example

```json
{
    "slug": "acme-search",
    "class": "Acme\\Search\\Plugin",
    "file": "src/Plugin.php",
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

`PluginLoader` enforces structural correctness at boot time and throws a `RuntimeException`
for any of the following:

- Invalid JSON in `plugin.json`
- Missing or non-string `slug` field
- `slug` value that does not match `^[a-z0-9][a-z0-9_-]*$`
- Missing or non-string `class` field

A manifest that is structurally valid but whose declared class cannot be resolved at
runtime (e.g. a bad autoload path) is silently skipped — this is treated as a recoverable
deployment error rather than a fatal one.

The following conditions also result in a silent skip rather than a fatal error:

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
