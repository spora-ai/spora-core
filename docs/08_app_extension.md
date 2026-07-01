# App Extensions (`app/App.php`)

A Symfony-style single-class extension point for project-local Spora code.

## TL;DR

Drop a class at `app/App.php` implementing `Spora\Extensions\AppInterface`
(easiest: extend `Spora\Extensions\AbstractExtension`). Override only the
hooks you need. That's it — the framework discovers the file via reflection,
calls your hooks at the right lifecycle points, and lets you promote the App
to a distributable plugin with a one-file rename.

```php
// app/App.php
namespace App;

use Spora\Extensions\AbstractExtension;

final class App extends AbstractExtension
{
    public function getName(): string { return 'My Spora App'; }

    public function tools(): array
    {
        return [\App\Tools\HelloTool::class];
    }
}
```

## App vs Plugin — when to use which

| Need | Use |
|------|-----|
| One tool, this project only | `app/Tools/Hello.php` + uncomment `tools()` in `App.php` |
| Multiple contributions + routes + DI | `app/App.php` |
| Distribute to other Spora installs | Migrate `app/` into a plugin: rename `App.php` → `Plugin.php`, add `plugin.json`, publish as Composer package |

The hook surface is identical for both. Promoting an App to a Plugin is a
mechanical rename + manifest, not a refactor.

## Hooks

All hooks are inherited from [`SporaExtensionInterface`](app/Extensions/SporaExtensionInterface.php).
Implementations may extend [`AbstractExtension`](app/Extensions/AbstractExtension.php)
to get empty defaults for every hook.

| Hook | When called | Default | Purpose |
|------|-------------|---------|---------|
| `getName()` | Discovery | (required) | Human-readable name shown in the UI / logs |
| `autoload()` | Discovery | `[]` | Additional PSR-4 mappings for the App's own classes |
| `tools()` | Container build | `[]` | Tool class FQCNs contributed to the Tool Registry |
| `drivers()` | Container build | `[]` | LLM drivers contributed (`provider => FQCN`) |
| `recipePaths()` | Container build | `[]` | Absolute paths to recipe directories |
| `schemaVersion()` | Schema install | `0` | Bump when adding migrations |
| `migrationsPath()` | Schema install | `null` | Absolute path to migration directory |
| `apps()` | Container build | `[]` | UI side-panels (`Spora\Apps\AppInterface` FQCNs) |
| `register(ContainerBuilder)` | Before container build | no-op | Apply DI bindings |
| `routes(MiddlewareRouteCollector)` | Before router build | no-op | Register HTTP routes |
| `boot()` | After container build, every request | no-op | Lifecycle hook — safe to use container services |

### Lifecycle ordering

1. **Discovery** — `app/App.php` is loaded; the implementing class is
   instantiated.
2. **Autoload** — `App::autoload()` PSR-4 mappings are registered with
   Composer's ClassLoader so the App's own classes become resolvable.
3. **DI bindings** — `App::register(ContainerBuilder)` is called BEFORE the
   container is built. Its bindings are merged into the same builder that
   the framework's core definitions come from.
4. **Container build** — PHP-DI compiles the merged definitions.
5. **Routes** — `RouteDefinitions::register()` runs first (core routes), then
   `App::routes()` runs (app routes appended). The router is built once.
6. **Boot** — `Database->boot()` (schema install), then `App::boot()`, then
   request dispatch.

### Hook details

#### `register(ContainerBuilder)`

`register()` is the most powerful hook — it lets the App contribute DI
bindings to the framework's container. This is the "Symfony bundle build()"
equivalent.

```php
public function register(\DI\ContainerBuilder $builder): void
{
    $builder->addDefinitions([
        'my_service' => static fn(\Psr\Container\ContainerInterface $c) => new MyService(),
        MyServiceInterface::class => static fn(\Psr\Container\ContainerInterface $c) => new MyService(),
    ]);
}
```

The hook is called BEFORE the container is built. Bindings registered here
behave like core framework bindings: any controller, tool, or service can
type-hint them.

> **Note:** the `register()` hook was previously declared on
> `PluginInterface` but never actually wired into the running container.
> It is now wired (see `ContainerDefinitions`). Plugins that already
> override `register()` start working as expected without code changes.

#### `routes(MiddlewareRouteCollector)`

The App contributes HTTP routes via imperative registration. This mirrors
how core routes are declared in `RouteDefinitions::register()`.

```php
public function routes(MiddlewareRouteCollector $r): void
{
    $r->addRoute(
        'GET',
        '/api/v1/hello',
        [\App\Http\Controllers\HelloController::class, 'index'],
        [\Spora\Http\Middleware\AuthMiddleware::class],
    );
}
```

#### `boot()`

`boot()` runs once per request, after the container is built and the DB is
up. Safe to use any container service here.

```php
public function boot(): void
{
    // Initialise a singleton, warm a cache, register a Mercure subscriber, …
}
```

Note: `boot()` is called BEFORE the request is dispatched, not "once per
process". For long-running workers that handle many requests, use
`boot()` only for idempotent setup.

## Promoting an App to a Plugin

The App → Plugin migration is mechanical:

```bash
# 1. Create the plugin directory
mkdir plugins/my-tool
mv app/Tools/HelloTool.php plugins/my-tool/src/HelloTool.php

# 2. Rename App.php → Plugin.php
mv app/App.php plugins/my-tool/src/Plugin.php

# 3. Inside Plugin.php, rename class App → Plugin, change the namespace
#    from App\ to Spora\MyPlugin\ (or your vendor namespace)
```

Then add a `plugin.json`:

```json
{
    "slug": "my-tool",
    "class": "Spora\\MyTool\\Plugin",
    "description": "My tool, distributed.",
    "autoload": {
        "psr-4": { "Spora\\MyTool\\": "src/" }
    }
}
```

Ship as a Composer package. Install with `php bin/spora spora:plugin:install`.
No internal refactor required — the hook surface is identical.

## Reference

- [`SporaExtensionInterface`](../app/Extensions/SporaExtensionInterface.php) — common contract
- [`AbstractExtension`](../app/Extensions/AbstractExtension.php) — empty-default base class
- [`AppInterface`](../app/Extensions/AppInterface.php) — marker for project apps
- [`AppLoader`](../app/Extensions/AppLoader.php) — discovery + boot
- [`PluginInterface`](../app/Plugins/PluginInterface.php) — marker for plugins
- [`AbstractPlugin`](../app/Plugins/AbstractPlugin.php) — empty-default base class for plugins

See also:
- [07_plugins.md](07_plugins.md) — plugin system reference (for the migration target)
- [06_tools.md](06_tools.md) — how to write a Tool class
- [01_architecture.md](01_architecture.md) — config priority and boot sequence