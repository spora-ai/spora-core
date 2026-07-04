# Plugin Install API

The Web UI for plugin management exposes three HTTP endpoints on top of the
existing `Spora\Core\Extension\PluginManager` (which the CLI
`php bin/spora plugin:install|uninstall|update` already uses). The endpoints
are gated by a feature flag — see [§ Feature flag](#feature-flag) — so existing
installs are unaffected unless an operator opts in.

> **Doc scope:** this document locks the JSON envelope, error codes, auth
> stack, and feature-flag key for the `POST /api/v1/plugins`,
> `DELETE /api/v1/plugins/{package}`, and `PATCH /api/v1/plugins/{package}`
> routes. Implementation lives in `app/Http/PluginsController.php` and
> `app/Core/RouteDefinitions.php`. Spec authored as part of the v0.6.2 release.

---

## Endpoint summary

| Method | Path | Auth | Purpose |
|---|---|---|---|
| `POST` | `/api/v1/plugins` | Admin + CSRF | Install a plugin (from registry or local path) |
| `DELETE` | `/api/v1/plugins/{package}` | Admin + CSRF | Uninstall a plugin |
| `PATCH` | `/api/v1/plugins/{package}` | Admin + CSRF | Update a plugin (single) |

The existing read-only `GET /api/v1/plugins` is unchanged.

All three endpoints require the **admin** middleware stack:
`[AuthMiddleware, CsrfMiddleware, AdminMiddleware]`. This matches the stack
used by `/api/v1/users`, `/api/v1/llm-configs/global`, and `/api/v1/mail/config`.

---

## Feature flag

| Env var | Default | Read at | Effect |
|---|---|---|---|
| `SPORA_PLUGIN_INSTALL_ENABLED` | `false` | `ContainerDefinitions` | When `false`, all three routes return `403 FEATURE_DISABLED`. CLI commands are **not** gated — operator recovery path always works. |

The flag is read in `ContainerDefinitions` (env-first, config-fallback, safe
default) and exposed to controllers via a `pluginInstallEnabled(): bool`
factory, mirroring the `SPORA_SECRET_KEY` resolution pattern.

**Why off by default:** shared-host operators on cPanel/FTP don't have
`composer` on the path. Enabling the Web UI without verifying
`SPORA_COMPOSER_BINARY` is set would surface a confusing error to admins.
Operators opt in once they confirm the binary path.

---

## Common conventions

All responses use the standard envelope from [04_api.md](04_api.md):

```json
{ "data": { ... } }                       // success
{ "error": { "code": "MACHINE_CODE", "message": "Human text." } }
```

Mutating routes (POST/DELETE/PATCH) require a `X-CSRF-Token` header matching
the value of `data.csrf_token` returned by `GET /api/v1/auth/me`. Unauth →
`401 UNAUTHENTICATED`; non-admin → `403 FORBIDDEN`; bad CSRF → `403 CSRF_INVALID`;
flag off → `403 FEATURE_DISABLED`.

Composer invocations run with a **120 s** timeout
(`PluginManager::TIMEOUT_SECONDS`). Long-running installs block the HTTP
response — streaming progress is a follow-up.

---

## POST `/api/v1/plugins` — Install

### Request body

```jsonc
{
    "package":     "spora-ai/spora-plugin-tavily",   // required, vendor/name
    "constraint":  "^0.2",                            // optional, semver constraint
    "path":        "/absolute/path/to/sibling/checkout" // optional, mutually exclusive with `constraint`
}
```

| Field | Required | Notes |
|---|---|---|
| `package` | yes | Composer vendor/name. Validated against `^[a-z0-9]([_.\-a-z0-9]*[a-z0-9])?/[a-z0-9]([_.\-a-z0-9]*[a-z0-9])?$`. Bad shape → `400 VALIDATION_FAILED`. |
| `constraint` | no | Semver constraint string passed verbatim to `composer require`. Omit for "latest". |
| `path` | no | Absolute path to a local checkout. Used for sibling-clone dev workflows. Mutually exclusive with `constraint` — both set → `400 VALIDATION_FAILED`. |

### Success — `200 OK`

```json
{
    "data": {
        "package":    "spora-ai/spora-plugin-tavily",
        "status":     "installed",
        "constraint": "^0.2",
        "path":       "/var/www/spora/plugins/spora-plugin-tavily",
        "message":    "Installed spora-ai/spora-plugin-tavily ^0.2"
    }
}
```

Maps to `PluginInstallResult::STATUS_INSTALLED`. The HTTP layer re-emits the
DTO verbatim — no additional fields. The path is what `composer show` reports
after install (operator-visible install location).

### Failure

| Code | HTTP | Triggered by |
|---|---|---|
| `VALIDATION_FAILED` | 400 | Missing `package`; bad shape; both `constraint` and `path` set |
| `UNAUTHENTICATED` | 401 | No session |
| `CSRF_INVALID` | 403 | CSRF header missing/mismatched |
| `FORBIDDEN` | 403 | Authenticated, not admin |
| `FEATURE_DISABLED` | 403 | `SPORA_PLUGIN_INSTALL_ENABLED=false` |
| `PLUGIN_INSTALL_FAILED` | 500 | Composer exited non-zero. Body includes `exit_code` and `stderr` for diagnostics (see below). |
| `INTERNAL_SERVER_ERROR` | 500 | Anything else |

### Composer-failure body (500)

```json
{
    "error": {
        "code":    "PLUGIN_INSTALL_FAILED",
        "message": "composer require failed (exit 2). See stderr for details."
    },
    "details": {
        "exit_code": 2,
        "stderr":    "  [InvalidArgumentException]\n  Could not find package spora-ai/spora-plugin-typo.\n  ..."

    }
}
```

`details.stderr` is truncated to **8 KiB** by the controller to keep
responses bounded — operators with longer errors are pointed to
`storage/spora.log`.

---

## DELETE `/api/v1/plugins/{package}` — Uninstall

### Path params

`package` — Composer vendor/name (URL-encoded). Validated by the same regex as
the install body.

### Success — `200 OK`

```json
{
    "data": {
        "package": "spora-ai/spora-plugin-tavily",
        "status":  "uninstalled",
        "message": "Removed spora-ai/spora-plugin-tavily"
    }
}
```

Maps to `PluginInstallResult::STATUS_UNINSTALLED`.

### Failure

Same error table as POST, with `code = PLUGIN_INSTALL_FAILED` when Composer
chokes (rare for `remove` — usually it's a missing package).

---

## PATCH `/api/v1/plugins/{package}` — Update

Updates a single plugin to the latest matching the existing constraint. Maps
to `PluginManager::update(string $package)`.

### Request body

Empty body is acceptable — `update` uses the constraint already pinned in
`composer.json`. For advanced use, the body may carry:

```json
{ "constraint": "^0.3" }
```

to re-pin before the update.

### Success — `200 OK`

```json
{
    "data": {
        "package":    "spora-ai/spora-plugin-tavily",
        "status":     "updated",
        "constraint": "^0.3",
        "message":    "Updated spora-ai/spora-plugin-tavily"
    }
}
```

`status` is one of `PluginInstallResult::STATUS_UPDATED` (success),
`STATUS_INSTALLED` (update resolved to a fresh install — same wire shape).

---

## Long-running installs

Composer invocations currently block the HTTP response for up to 120 s.
This is acceptable for the v0.6.2 release but **should not** become the
permanent contract. The planned follow-up:

- `POST /api/v1/plugins/{package}/install-jobs` — enqueue, returns job id
- `GET  /api/v1/plugins/install-jobs/{id}` — poll status, percentage, stderr stream

Tracked separately from this document.

---

## Example end-to-end

```bash
# 1. Log in (sets PHPSESSID cookie, returns CSRF token)
curl -c cookies.txt -X POST http://localhost/api/v1/auth/login \
     -H 'Content-Type: application/json' \
     -d '{"email":"admin@example.com","password":"…"}'
# → 200 { "data": { "csrf_token": "abc…" } }

# 2. Install Tavily (admin-only + CSRF-protected + feature-flagged)
curl -b cookies.txt -X POST http://localhost/api/v1/plugins \
     -H 'Content-Type: application/json' \
     -H 'X-CSRF-Token: abc…' \
     -d '{"package":"spora-ai/spora-plugin-tavily","constraint":"^0.2"}'
# → 200 { "data": { "status": "installed", … } }

# 3. Verify it shows up in the inventory
curl -b cookies.txt http://localhost/api/v1/plugins
# → 200 { "data": { "plugins": [ … { "name": "spora-plugin-tavily", … } ] } }

# 4. Uninstall
curl -b cookies.txt -X DELETE http://localhost/api/v1/plugins/spora-ai/spora-plugin-tavily \
     -H 'X-CSRF-Token: abc…'
# → 200 { "data": { "status": "uninstalled", … } }
```

---

## Why no streaming / WebSocket for v0.6.2

The 120 s blocking response is fine for the typical install (10–30 s in
practice). The catalog feature (Track 4) is the larger surface that
introduces HTTP latency beyond just install — install streaming is a
follow-up.

---

## Operator recovery path

If the Web UI is disabled (`SPORA_PLUGIN_INSTALL_ENABLED=false`) or breaks,
the **CLI is not gated** and always works:

```bash
php bin/spora plugin:install spora-ai/spora-plugin-tavily --constraint=^0.2
php bin/spora plugin:uninstall spora-ai/spora-plugin-tavily
php bin/spora plugin:update spora-ai/spora-plugin-tavily
```

The CLI uses the same `PluginManager` under the hood, so behavior is
identical — only the transport differs.