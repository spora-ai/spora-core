# Spora Plugin Author Guide

This document covers everything you need to publish a Spora plugin on
Packagist so operators can install it from the admin UI (`/apps/plugins` →
**Browse** tab). For the runtime side (manifest, auto-discovery, recipes),
see [07_plugins.md](07_plugins.md).

> **Audience:** authors of `spora-ai/spora-plugin-*` (and forks) who want
> their plugin discoverable from the operator's admin UI.

---

## 1. Package conventions

### 1.1 `composer.json` essentials

Your plugin's `composer.json` **must** include the following for it to
appear in the **Browse** catalog:

```json
{
    "name": "spora-ai/spora-plugin-<your-plugin>",
    "type": "spora-plugin",
    "keywords": ["spora-plugin"],
    "require": {
        "spora-ai/spora-core": "^0.6"
    }
}
```

| Field | Required | Notes |
|---|---|---|
| `type` | yes | **MUST** be exactly `spora-plugin` (lowercase, hyphenated). The catalog re-filters server-side on this exact value — anything else is excluded even if `keywords` matches. |
| `keywords` | yes | **MUST** contain the string `spora-plugin`. The Packagist search uses this token. |
| `name` | yes | Packagist follows the `vendor/package` convention. `vendor` should match your org (e.g. `spora-ai`, `acme-corp`). |
| `require.spora-ai/spora-core` | yes | Pin to the Spora major version your plugin targets (e.g. `^0.6`, `^0.7`). |

> **Why both `type` and `keywords`?** Packagist's search ranks by
> `keywords`, but the type filter is what `spora-installer` uses to
> route the package into the `plugins/` directory on install. The
> catalog re-filters on `type === 'spora-plugin'` server-side to avoid
> keyword pollution from unrelated packages.

### 1.2 Tag your releases

Follow [SemVer](https://semver.org/). Operator installs use Composer's
constraint resolver, so:

- `0.1.x` → preview / early adopters
- `0.2.x` → stable
- `1.x` → production-ready GA

Tag from `main` **after** CI is green on the merged PR. Never tag from
a PR branch.

### 1.3 Bundle your tools

Each tool class should carry `#[Tool]` and `#[ToolParameter]` attributes
so the admin UI can render its settings. See [06_tools.md](06_tools.md)
for the full attribute reference. The `description` on `#[Tool]` is what
operators see in the **Installed** list — keep it under 140 chars.

---

## 2. Plugin catalog

Once your plugin is published on Packagist with the metadata above, it
appears under `/apps/plugins` → **Browse** for any operator whose Spora
install has the catalog enabled.

### 2.1 Where it shows up

The catalog is read-only and surfaces:

- Package name (e.g. `spora-ai/spora-plugin-email`)
- Short description
- Latest version
- Download / fav count from Packagist
- Repository + homepage links

Operators click **Install** on your card → the `InstallPluginModal`
runs `composer require` exactly as the CLI does. You don't need to
write any UI-side code; the install surface is shared.

### 2.2 Author checklist (catalog inclusion)

Before tagging a release that should appear in the catalog:

- [ ] `composer.json` has `"type": "spora-plugin"` (exact spelling)
- [ ] `composer.json` has `"keywords": ["spora-plugin"]` (or includes it)
- [ ] Tag pushed: `git tag vX.Y.Z && git push --tags`
- [ ] CI green on `main` for the tagged commit
- [ ] Manual check: `https://packagist.org/packages/<vendor>/<package>`
      shows the new tag within ~5 minutes

If your package shows up under the wrong heading (or not at all), the
type/keyword pair is the first thing to verify. Packagist's search index
updates on a delay, so a fresh tag may take a few minutes to appear.

### 2.3 What the operator sees

The **Browse** tab is gated by the operator's `SPORA_PLUGIN_CATALOG_ENABLED`
env var (default `true`). When an operator disables it, the tab is hidden
and `GET /api/v1/plugins/catalog` returns `404`. This does **not** affect
already-installed plugins — it only hides the discovery surface.

You can verify what an operator will see by hitting the catalog endpoint
yourself:

```bash
curl https://packagist.org/search.json?q=email\&type=spora-plugin
```

The JSON shape is documented at `GET /api/v1/plugins/catalog` in
[04_api.md](04_api.md).

---

## 3. Versioning & compatibility

| Spora core | Plugin versions | Notes |
|---|---|---|
| `v0.6.x` | `^0.2` | The 7 extracted plugins (`tavily`, `serper`, etc.) and any new plugin published against this contract. |
| `v0.7.x` | `^0.3` | Adds the catalog endpoint (`GET /api/v1/plugins/catalog`) and the **Browse** tab. Plugins on `^0.2` still install fine on v0.7, but they don't get a catalog card until you re-tag at `^0.3` with the metadata above. |

A plugin that requires `spora-core: ^0.6` will install on `v0.7.x` because
of Composer's caret semantics. The reverse is **not** true: a plugin that
declares `^0.7` will not install on `v0.6.x`.

---

## 4. Before you open a PR

For projects in the `spora-ai` org, the same release flow as Spora core
applies:

1. Branch from `main`: `feat/<plugin-name>-<feature>`
2. Open a PR — CI runs Pest / PHPStan / cs-fixer
3. Merge after green
4. Tag from `main` **only**: `git tag vX.Y.Z && git push --tags`

SonarCloud quality gate must be green on `main` before tagging. The
project key is `spora-ai_<repo-slug>`.