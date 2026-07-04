# Plugin Author Guide

End-to-end guide for writing a Spora plugin — a Composer package that ships tools, drivers, recipes, and migrations to a Spora deployment.

The complete plugin system reference (load order, manifest validation, manifest schemas, boot-time stamp cache, security model) lives in [07_plugins.md](07_plugins.md). The operator-facing install/uninstall flow (`bin/spora plugin:install`, HTTP endpoints) is documented in [20_plugin_install_api.md](20_plugin_install_api.md). This doc is focused on **how to author a plugin** from scratch.

## Table of contents

1. [What a Spora plugin is](#what-a-spora-plugin-is)
2. [`plugin.json` manifest](#pluginjson-manifest)
3. [Entry-point class](#entry-point-class)
4. [Adding a tool](#adding-a-tool)
5. [Adding an LLM driver](#adding-an-llm-driver)
6. [Adding migrations](#adding-migrations)
7. [Recipes](#recipes)
8. [Local development workflow](#local-development-workflow)
9. [The `spora-plugin` keyword](#the-spora-plugin-keyword)
10. [PSR-4 entry-point quirk](#psr-4-entry-point-quirk)
11. [Testing](#testing)
12. [Versioning](#versioning)
13. [Reference implementations](#reference-implementations)

---

## What a Spora plugin is

A Spora plugin is a Composer package — installable via `composer require` and shipped to Packagist like any other PHP library — that contributes runtime capabilities to a Spora deployment:

- **Tools** callable by an agent (web search, image generation, calendar ops).
- **LLM drivers** that plug into the driver factory alongside OpenAI and Anthropic.
- **Recipes** that bundle a system prompt + tool allowlist into a one-click agent.
- **Migrations** that create plugin-owned database tables.

A plugin is identified by a **Composer package** with `type: "spora-plugin"`. On install, the `spora-ai/installer` Composer plugin routes the package to the host Spora's `plugins/{slug}/` directory and the host's `PluginLoader` picks up its manifest on the next request.

Two reference layouts:

- **Skeleton template** — `spora-ai/spora-plugin-skeleton`. Copy this repo to bootstrap a new plugin.
- **Production example** — `spora-ai/spora-plugin-minimax`. Five tools (image, speech, music, lyrics, video), one migration, custom DI bindings.

### Standard layout

```
spora-plugin-foo/
├── composer.json         # type: "spora-plugin"
├── plugin.json           # manifest (slug, class, description, icon)
├── plugin.schema.json    # (optional) $schema pointer for editor hints
├── src/
│   ├── FooPlugin.php     # entry-point class (FQCN = Spora\Plugins\Foo\FooPlugin)
│   └── Tools/
│       └── FooTool.php   # at least one tool class
├── database/
│   └── migrations/
│       └── foo_000001_create_xyz_table.php
├── recipes/
│   └── default.yaml
└── tests/
    └── Unit/
        └── Tools/
            └── FooToolTest.php
```

The directory name is up to you (Composer picks files by namespace, not directory name), but it must match the slug used in `plugin.json` and as the migration filename prefix.

---

## `plugin.json` manifest

The full JSON Schema lives at [`plugin.schema.json`](../plugin.schema.json). Every field is validated at boot by `Spora\Plugins\PluginLoader` — extra fields are rejected outright (`additionalProperties: false`).

### Required fields

| Field   | Type   | Description |
|---------|--------|-------------|
| `slug`  | string | Machine identifier. Matches `^[a-z0-9][a-z0-9_-]*$`. Stable across releases — used as the component key in `schema_versions` and as the migration filename prefix. |
| `class` | string | FQCN of the entry-point class. Must implement `Spora\Plugins\PluginInterface` and resolve via PSR-4 autoloading. |

### Optional fields

| Field         | Type   | Description |
|---------------|--------|-------------|
| `description` | string | Short human-readable description surfaced by the inventory UI. Max 500 chars. |
| `icon`        | string | Icon shown next to the plugin in admin UIs. Three accepted forms — bundled name (`"puzzle"`, `"brain"`, `"globe"`…), a full `<svg>` string, or a raw SVG path string. Defaults to `"puzzle"` when omitted. See [07_plugins.md § Bundled icons](07_plugins.md#bundled-icons) for the full palette. |

### Minimal example

```json
{
    "slug": "acme-search",
    "class": "Spora\\Plugins\\AcmeSearch\\AcmeSearchPlugin"
}
```

### Full example

```json
{
    "slug": "acme-search",
    "class": "Spora\\Plugins\\AcmeSearch\\AcmeSearchPlugin",
    "description": "Web search via the Acme API.",
    "icon": "globe"
}
```

### What is *not* in the manifest

The previous schema (`<= v0.5.x`) accepted `version`, `dependencies`, `autoload`, and `file` overrides. Those were dropped when `PluginLoader` switched to PSR-4-only entry-point resolution (PR #108 in `spora-core`):

- **Version** is taken from `composer.json`. No `version` field.
- **Inter-plugin dependencies** are declared in `composer.json` (`"require"`), not the manifest.
- **Autoload PSR-4 mappings** are declared exclusively in `composer.json`. The manifest no longer accepts an `autoload` block.
- **`file` override** is gone — the loader instantiates `class` via PSR-4 and throws on failure.

If you are maintaining an older plugin, see [PSR-4 entry-point quirk](#psr-4-entry-point-quirk) below.

---

## Entry-point class

The class named in the manifest `class` field must implement `Spora\Plugins\PluginInterface`. In practice, extend `Spora\Plugins\AbstractPlugin` (or, for new code that may one day move into an App, `Spora\Extensions\AbstractExtension` — both implement the same interface).

`AbstractPlugin` provides empty defaults for every hook, so you only override what you actually use. The two methods every plugin overrides are `getName()` (for UI display) and `tools()` (the whole point of the plugin).

```php
<?php

declare(strict_types=1);

namespace Spora\Plugins\AcmeSearch;

use Spora\Plugins\AbstractPlugin;
use Spora\Plugins\AcmeSearch\Tools\AcmeSearchTool;

final class AcmeSearchPlugin extends AbstractPlugin
{
    public function getName(): string
    {
        return 'Acme Search';
    }

    /** @return array<class-string<\Spora\Tools\ToolInterface>> */
    public function tools(): array
    {
        return [
            AcmeSearchTool::class,
        ];
    }
}
```

### Available hooks

All hooks live on `Spora\Plugins\PluginInterface` (re-exported from `Spora\Extensions\SporaExtensionInterface`). Most plugins override one or two; the rest stay at their `AbstractPlugin` no-op defaults.

| Hook | Returns | Purpose |
|------|---------|---------|
| `getName()` | `string` | Human-facing name shown in admin UIs. |
| `tools()` | `class-string<ToolInterface>[]` | Tools contributed to the tool registry. |
| `drivers()` | `string[]` (id → FQCN) | LLM drivers contributed to the driver factory. |
| `recipePaths()` | `string[]` | Absolute paths to recipe YAML directories or files. |
| `apps()` | `class-string<AppInterface>[]` | UI side-panels contributed to the App registry. |
| `migrationsPath()` | `?string` | Absolute path to plugin migrations directory, or `null` if no schema. |
| `schemaVersion()` | `int` | Bump every time a new migration file is added. `0` if no schema. |
| `register(ContainerBuilder $builder)` | `void` | Hook for arbitrary DI bindings, middleware, or services. |
| `boot()` | `void` | Lifecycle hook fired once after DI container is built, before the first request. |
| `routes(MiddlewareRouteCollector $routes)` | `void` | Register HTTP routes into the running middleware collector. |

For the new hook surface and why `PluginInterface` is now a marker (with `SporaExtensionInterface` carrying the contract), see the docblock on [`Spora\Plugins\PluginInterface`](../app/Plugins/PluginInterface.php).

---

## Adding a tool

### Where the tool lives

Tool classes live in **your plugin's** `src/Tools/{Name}Tool.php`, not in Spora core. The plugin's entry-point references the tool's FQCN from `tools()` and the orchestrator auto-prefixes it with the plugin slug (a tool named `web_search` in a plugin with `slug: "acme-search"` is exposed to the LLM as `acme-search:web_search`).

The canonical base class is `Spora\Tools\AbstractTool` (in `app/Tools/AbstractTool.php`). It composes two opt-in traits:

- `HasOperations` — per-operation dispatch + override resolution.
- `HasParameterSchema` — builds `getParametersSchema()` from `#[ToolParameter]` attributes and synthesises a discriminator enum from `#[ToolOperation]`.

If your tool already extends a third-party base, you can `use` those traits directly instead.

### Minimal example

```php
<?php

declare(strict_types=1);

namespace Spora\Plugins\AcmeSearch\Tools;

use Spora\Tools\AbstractTool;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolParameter;

#[Tool(name: 'web_search', description: 'Run a web query against the Acme Search API.')]
final class AcmeSearchTool extends AbstractTool
{
    public function __construct(
        private readonly AcmeHttpClient $http,
        private readonly AcmeSettings $settings,
    ) {}

    #[ToolParameter(type: 'string', required: true, description: 'Natural-language query.')]
    public string $query;

    public function execute(array $params): ToolResult
    {
        $response = $this->http->search($params['query'], $this->settings->maxResults);
        return ToolResult::ok(json_encode($response, JSON_THROW_ON_ERROR));
    }
}
```

The contract is `Spora\Tools\ToolInterface`. The full `#[Tool]` / `#[ToolParameter]` / `#[ToolSetting]` attribute surface and the settings-key convention are documented in [06_tools.md](06_tools.md) — read it before shipping a tool with operator-facing config.

### Convention reminders

- Use `final` on the tool class (see CLAUDE.md).
- `declare(strict_types=1);` at the top of every PHP file.
- FQCN must match the file path (PSR-4) — `Spora\Plugins\AcmeSearch\Tools\AcmeSearchTool` lives at `src/Tools/AcmeSearchTool.php`.

---

## Adding an LLM driver

Drivers work the same way as tools: a class in `src/Drivers/`, referenced by FQCN from the plugin's `drivers()` hook. Drivers implement `Spora\Drivers\LLMDriverInterface` and are picked up by the driver factory alongside the built-in OpenAI and Anthropic drivers.

Driver registration contracts (config keys, `LLMDriverConfigInterface`, environment overrides) are documented in [05_drivers.md](05_drivers.md). Plugin drivers follow the same rules — return the FQCN from `drivers()` and the plugin loader registers it under the declared id.

```php
/** @return array<string, class-string<\Spora\Drivers\LLMDriverInterface>> */
public function drivers(): array
{
    return [
        'acme-anthropic-compatible' => AcmeAnthropicDriver::class,
    ];
}
```

For most plugins, **adding tools is more useful than adding a new driver** — drivers require parallel API-compat implementations across the Anthropic / OpenAI / Gemini surface, while a tool just needs an HTTP endpoint to call.

---

## Adding migrations

Plugins that need their own database tables declare a migration path and a schema version:

```php
public function schemaVersion(): int
{
    return 1;   // bump on every new migration file
}

public function migrationsPath(): ?string
{
    return __DIR__ . '/../database/migrations';
}
```

### Filename pattern

Every migration file **must be prefixed with the plugin slug** followed by a six-digit sequence number:

```
database/migrations/
├── acme-search_000001_create_search_index_table.php
├── acme-search_000002_add_results_table.php
└── …
```

`DatabaseSchemaInstaller` enforces this prefix at install time and throws `RuntimeException` if any file lacks the `{slug}_` prefix. The prefix namespacing is what keeps your plugin's migrations from colliding with core or sibling plugins.

### File content

An anonymous class extending `Illuminate\Database\Migrations\Migration`, matching the core migration style:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration {
    public function up(): void
    {
        Capsule::schema()->create('acme_search_index', static function (Blueprint $table): void {
            $table->id();
            $table->string('keyword')->index();
            $table->json('top_results')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('acme_search_index');
    }
};
```

Rule of thumb:

- Always namespace your tables (`acme_search_index`, not `search_index`) to avoid collision with core tables or other plugins.
- Always write a `down()` even if you only use it for local cleanup.
- Never edit a migration that has already shipped — bump `schemaVersion()` and add a new file.

---

## Recipes

Recipes bundle a system prompt + tool allowlist + LLM config into a one-click agent template. They live under `recipes/` in your plugin and are returned from the entry-point's `recipePaths()` hook.

### Directory layout

```
recipes/
└── default.yaml
```

### Example

```yaml
id: acme_search
name: Acme Web Search Agent
description: An agent that researches topics using the Acme Search API.
system_prompt: |
  You are a research assistant. Use the web_search tool when the user asks
  for current information, and cite your sources in the final answer.
tools:
  - acme-search:web_search
llm:
  driver: anthropic
  model: claude-opus-4-7
  temperature: 0.3
```

The full YAML schema (tool format, LLM driver override, max iterations, scheduled-run fields) is documented in [01_architecture.md § Recipes](01_architecture.md). What is plugin-specific is the path declaration:

```php
public function recipePaths(): array
{
    return [
        __DIR__ . '/../recipes',
    ];
}
```

Recipes are picked up by `RecipeScanner` alongside the host Spora's `recipes/` directory; plugin recipes are first-class and indistinguishable from operator-defined ones at runtime.

---

## Local development workflow

When iterating on a plugin against a Spora checkout, you want your changes effective without round-tripping through Packagist. Two equivalent options — pick whichever fits your setup.

### Option A — Composer path repository

In your Spora checkout's `composer.json`, register the plugin as a path repo and require it pinned to `@dev`:

```bash
cd /path/to/spora
composer config repositories.spora-plugin-foo path /Users/you/code/spora-plugin-foo
composer require spora-ai/spora-plugin-foo:@dev
```

Edits in `/Users/you/code/spora-plugin-foo` are picked up immediately on the next request — no rebuild, no re-publish.

### Option B — `SPORA_PLUGINS_PATHS`

Clone the plugin anywhere on disk and point Spora at it via env var (or `config.php`):

```bash
git clone https://github.com/spora-ai/spora-plugin-foo.git /opt/spora-plugins/foo
echo 'SPORA_PLUGINS_PATHS=/opt/spora-plugins/foo' >> .env
```

Comma-separate multiple paths:

```bash
SPORA_PLUGINS_PATHS=/opt/spora-plugins/foo,/srv/spora/community
```

The in-repo `plugins/` directory is scanned first; external paths follow in declaration order. Duplicates by `slug` are silently skipped — first manifest wins. See [07_plugins.md § Loading from external paths](07_plugins.md#loading-from-external-paths) for details.

### Verifying changes locally

After editing, refresh the boot:

```bash
php bin/spora spora:install   # applies any new migrations
```

`PluginLoader` invalidates its boot-stamp cache (`storage/.plugins_stamp`) automatically when a manifest's content changes, so no manual cache-bust is needed.

For agents working on the plugin via sub-agents in parallel worktrees, `.worktrees/` is gitignored across all Spora repos — see CLAUDE.md for the local workflow rules.

---

## The `spora-plugin` keyword

Plugins **must** include `"spora-plugin"` in their `composer.json` `keywords` array, alongside `"type": "spora-plugin"`:

```json
{
    "name": "spora-ai/spora-plugin-foo",
    "type": "spora-plugin",
    "keywords": [
        "spora-plugin",
        "spora"
    ]
}
```

This is how the **operator-facing catalog** (P2 of the catalog plan, ships in the same release) discovers published plugins via Packagist's API. Without it, the plugin still installs fine via `plugin:install` or a direct GitHub URL — it just won't appear in the Browse tab.

A working `keywords` block on every published Spora plugin was verified on Packagist on 2026-07-04. Treat the keyword as load-bearing for discoverability. See [20_plugin_install_api.md](20_plugin_install_api.md) for the operator-facing catalog contract.

---

## PSR-4 entry-point quirk

The entry-point class **must** be reachable via PSR-4 autoloading, full stop. After PR #108 ([`fix/plugin-loader-psr4-entry-point`](../CLAUDE.md#memory-notes)) the loader no longer supports a `file` override or `require_once` fallback — it relies on whatever PSR-4 prefix the plugin declares in `composer.json` and throws `PluginLoadFailedException` if the FQCN cannot be resolved.

Practical implication: **the file path must literally match the class name**.

For an entry-point declared as:

```json
"class": "Spora\\Plugins\\AcmeSearch\\AcmeSearchPlugin"
```

the file must live at:

```
src/AcmeSearchPlugin.php
```

declared under the matching PSR-4 prefix in `composer.json`:

```json
"autoload": {
    "psr-4": {
        "Spora\\Plugins\\AcmeSearch\\": "src/"
    }
}
```

The `spora-ai/installer` Composer plugin (transitive dep of `spora-core`) handles the path routing — `composer require spora-ai/spora-plugin-foo` on a Spora install lands the package at `plugins/{slug}/` where `PluginLoader` expects to find it.

If you see `"Plugin class X could not be resolved"` at boot, the cause is almost always a class-name vs filename mismatch or a missing PSR-4 mapping in `composer.json` (regenerate the classmap with `composer dump-autoload` after adding the prefix).

---

## Testing

The reference plugins use **Pest 4** as the test runner. The skeleton template ships with `composer test`, `composer analyse` (PHPStan), and `composer lint` (PHP-CS-Fixer) wired up.

### Layout

```
tests/
└── Unit/
    └── Tools/
        └── AcmeSearchToolTest.php
```

The autoload-dev PSR-4 mapping in `composer.json` exposes the namespace:

```json
"autoload-dev": {
    "psr-4": {
        "Spora\\Plugins\\AcmeSearch\\Tests\\": "tests/"
    }
}
```

### Conventions

- Extend the tool's behaviour, not its internals — hit `execute()` end-to-end whenever possible.
- Use **Mockery** (`mockery/mockery:^1.6`) to stub the HTTP client. The core project ships `phpstan/phpstan-mockery` so Mockery annotations type-check cleanly.
- For migrations, **use the in-memory SQLite database fixture** (`tests/Fixtures/inmemory-sqlite.php` or boot `Database::bootDatabaseConnectionOnly()` in `beforeEach`) instead of mocking the schema. See CLAUDE.md's testing rule — "no mocks for integration tests that already boot the DB".
- Cover the happy path, one failure path, and one credential-missing path per tool.

### Skeleton scripts

Copy these from `spora-plugin-skeleton/composer.json`:

```json
"scripts": {
    "test": "pest",
    "analyse": "phpstan analyse --no-progress",
    "lint": "php-cs-fixer fix --dry-run --diff --no-interaction"
}
```

CI in `spora-core` (`composer test && composer analyse`) is the same contract your plugin should meet before tagging a release.

---

## Versioning

Plugins follow **SemVer** (`vX.Y.Z` tags, `^X.Y` constraints in `composer.json`).

| Bump    | When |
|---------|------|
| Major   | Renamed tools, removed settings keys, changed manifest fields, dropped migrations, PHP major version bump. |
| Minor   | New tools or settings keys, new optional fields, new drivers. |
| Patch   | Bugfixes, docstring changes, dependency bumps that don't change the public surface. |

### Release checklist

1. All CI green (`composer test`, `composer analyse`, `composer lint`).
2. `composer.json` carries `"type": "spora-plugin"` and `"keywords": ["spora-plugin", ...]`.
3. `plugin.json` validates against [`plugin.schema.json`](../plugin.schema.json).
4. `phpstan.neon` and `phpunit.xml` are present (copied from the skeleton template).
5. Tag format `vX.Y.Z` — never tag from a PR branch, only from `main` after CI is green. See CLAUDE.md's tag rules.
6. Push the tag, then **create a GitHub Release** so Packagist picks up the version.

The catalog indexes tags on every push; releasing a tag without a GitHub Release delays catalog visibility by up to a few minutes.

---

## Reference implementations

The two canonical starting points:

- **[`spora-ai/spora-plugin-skeleton`](https://github.com/spora-ai/spora-plugin-skeleton)** — minimal template, copy-and-rename. The `composer.json` is the canonical example of `type`, `keywords`, scripts, and PSR-4 mapping.
- **[`spora-ai/spora-plugin-minimax`](https://github.com/spora-ai/spora-plugin-minimax)** — production example. Five tools (image, speech, music, lyrics, video), DI bindings, a migration with both `up()` and `down()`, an HTTP client wrapper, and an opt-in log writer. The closest real-world analogue to anything an author will actually need to build.

Other public plugins (`spora-plugin-tavily`, `spora-plugin-serper`, `spora-plugin-semantic-scholar`, `spora-plugin-worldnews`, `spora-plugin-weather`, `spora-plugin-calendar`, `spora-plugin-email`) are minimal single-tool plugins — ideal for studying the **minimum viable plugin** shape before adding tooling.
