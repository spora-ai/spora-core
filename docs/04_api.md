# Group Settings Pages — API

> GitHub-style group settings pages (Overview / Members / Agents / Tools / LLM Drivers / Preferences). The existing `/api/v1/groups/{id}` (CRUD) and `/api/v1/groups/{id}/members` endpoints are documented in the spora-ai.com API reference; this file covers the new surface added in the `group-settings-pages` PR.

## Authorisation

- **Read** endpoints use `callerCanSeeGroup()` — members of the group only. Non-members (including global admins without membership) receive `404 GROUP_NOT_FOUND` (existence-hiding). Global admins manage members via the admin-panel overlay instead.
- **Write** endpoints additionally require `callerCanManageGroup()` — `role ∈ {owner, admin}` OR global admin. Members receive `403 FORBIDDEN`.
- The `tools` and `preferences` upserts always write to the **group's** group-principal; the `principal_id` in the request body is ignored for the LLM-config POST to prevent redirection to a different principal.

## `GET /api/v1/groups/{id}`

Returns the group row plus four count fields used by the Overview page cards.

**Auth:** `callerCanSeeGroup`.

### 200 — response

```json
{
  "data": {
    "group": {
      "id": 1,
      "name": "Marketing",
      "description": "Outbound campaign agents",
      "created_by_user_id": 1,
      "principal_id": 5,
      "my_role": "owner",
      "member_count": 3,
      "agent_count": 12,
      "llm_config_count": 2,
      "tool_setting_count": 4,
      "created_at": "2026-08-19T10:00:00+00:00",
      "updated_at": "2026-08-19T10:00:00+00:00"
    }
  }
}
```

### Errors

- `401 UNAUTHENTICATED` — no session.
- `404 GROUP_NOT_FOUND` — group missing OR caller is not a member.

## `GET /api/v1/groups/{id}/agents`

Lists agents whose `principal_id` matches the group's group-principal. Used by the Agents sub-page table; powers the Transfer action.

**Auth:** `callerCanSeeGroup`.

### 200 — response

```json
{
  "data": {
    "agents": [
      {
        "id": 42,
        "name": "Outreach Bot",
        "is_active": true,
        "principal_id": 5,
        "tools": []
      }
    ],
    "total": 1
  }
}
```

### Errors

- `401 UNAUTHENTICATED` — no session.
- `404 GROUP_NOT_FOUND` — group missing OR caller is not a member.

## `GET /api/v1/groups/{id}/preferences`

Returns the single `principal_preferences` row keyed by the group's group-principal id. When no row exists yet (fresh group), returns a synthesised empty preference so the Settings UI can render its initial state without a 404.

**Auth:** `callerCanSeeGroup`.

### 200 — response

```json
{
  "data": {
    "preference": {
      "principal_id": 5,
      "preferred_llm_config_id": 12,
      "updated_at": "2026-08-19T10:00:00+00:00"
    }
  }
}
```

### Errors

- `401 UNAUTHENTICATED` — no session.
- `404 GROUP_NOT_FOUND` — group missing OR caller is not a member.

## `PUT /api/v1/groups/{id}/preferences`

Upsert the `principal_preferences` row for the group's group-principal. `null` is a valid value (clears the preference).

**Auth:** `callerCanManageGroup`.

### Request

```json
{
  "preferred_llm_config_id": 12
}
```

`preferred_llm_config_id` is required, may be `null` or a positive integer pointing at a config the caller can see.

### 200 — response

Same shape as the GET endpoint.

### Errors

- `400 INVALID_JSON` — body is not valid JSON.
- `401 UNAUTHENTICATED` — no session.
- `403 FORBIDDEN` — caller is `member`-only.
- `404 GROUP_NOT_FOUND` — group missing OR caller is not a member.
- `422 VALIDATION_ERROR` — `preferred_llm_config_id` missing or wrong type.

## `GET /api/v1/groups/{id}/tools`

Lists `tool_user_settings` rows scoped to the group's group-principal. Used by the Tools sub-page list.

**Auth:** `callerCanSeeGroup`.

### 200 — response

```json
{
  "data": {
    "tools": [
      {
        "tool_class": "Spora\\Tools\\CalculatorTool",
        "principal_id": 5,
        "settings": { "precision": 8 },
        "updated_at": "2026-08-19T10:00:00+00:00"
      }
    ]
  }
}
```

Settings are passed through `ToolConfigService::maskForApi()` so password fields render as `***`.

### Errors

- `401 UNAUTHENTICATED` — no session.
- `404 GROUP_NOT_FOUND` — group missing OR caller is not a member.

## `POST /api/v1/groups/{id}/tools/{toolClass}`

Upsert (insert or update) the tool user settings row for the group principal. The body's `principal_id` field is ignored — the write always lands on the group's group-principal.

**Auth:** `callerCanManageGroup`.

### Request

```json
{
  "settings": { "precision": 8 }
}
```

Or the bare object form (no `settings` wrapper) — both accepted.

### 200 — response

```json
{
  "data": {
    "tool": {
      "tool_class": "Spora\\Tools\\CalculatorTool",
      "principal_id": 5,
      "settings": { "precision": 8 }
    }
  }
}
```

### Errors

- `400 INVALID_JSON` — body is not valid JSON.
- `401 UNAUTHENTICATED` — no session.
- `403 FORBIDDEN` — caller is `member`-only.
- `404 GROUP_NOT_FOUND` — group missing OR caller is not a member.
- `422 VALIDATION_ERROR` — `settings` is not an object.

## `DELETE /api/v1/groups/{id}/tools/{toolClass}`

Hard-delete the tool user settings row for the group principal.

**Auth:** `callerCanManageGroup`.

### 200 — response

```json
{ "data": { "deleted": true } }
```

### Errors

- `401 UNAUTHENTICATED` — no session.
- `403 FORBIDDEN` — caller is `member`-only.
- `404 GROUP_NOT_FOUND` — group missing OR caller is not a member.

## `GET /api/v1/groups/{id}/llm-configs`

List `llm_driver_configurations` rows scoped to the group's group-principal. **Does not include** global configs (they live at `principal_id = null`) — use the existing `/api/v1/llm-configs/global` endpoint for those.

**Auth:** `callerCanSeeGroup`.

### 200 — response

```json
{
  "data": {
    "configs": [
      {
        "id": 12,
        "name": "Marketing OpenAI",
        "driver_class": "Spora\\Drivers\\OpenAICompatibleDriver",
        "driver_name": "openai-compatible",
        "driver_display_name": "OpenAI-compatible",
        "settings": { "api_key": "***", "model": "gpt-4o" },
        "context_window": 128000,
        "max_tokens_output": 4096,
        "is_default": true,
        "principal_id": 5,
        "is_global": false,
        "created_at": "2026-08-19T10:00:00+00:00",
        "updated_at": "2026-08-19T10:00:00+00:00"
      }
    ]
  }
}
```

### Errors

- `401 UNAUTHENTICATED` — no session.
- `404 GROUP_NOT_FOUND` — group missing OR caller is not a member.

## `POST /api/v1/groups/{id}/llm-configs`

Create a new LLM config under the group's group-principal. Reuses `LLMConfigValidator` and `LLMConfigServiceInterface`; the request body is identical to `/api/v1/llm-configs` except `principal_id` is forced to the group's principal.

**Auth:** `callerCanManageGroup`.

### Request

```json
{
  "name": "Marketing OpenAI",
  "driver_class": "Spora\\Drivers\\OpenAICompatibleDriver",
  "settings": { "api_key": "sk-...", "model": "gpt-4o" },
  "context_window": 128000,
  "max_tokens_output": 4096,
  "is_default": true
}
```

### 201 — response

Same shape as a single item from `GET /api/v1/groups/{id}/llm-configs`, with the freshly assigned `id`, `principal_id = group principal id`, and `is_global = false`.

### Errors

- `400 INVALID_JSON` — body is not valid JSON.
- `401 UNAUTHENTICATED` — no session.
- `403 FORBIDDEN` — caller is `member`-only.
- `404 GROUP_NOT_FOUND` — group missing OR caller is not a member.
- `422 VALIDATION_ERROR` — `name` empty, `driver_class` unknown, or driver settings fail schema validation.

## `PATCH /api/v1/groups/{id}/llm-configs/{cid}`

Update an existing LLM config that is scoped to this group's group-principal. Returns `404` if the config id is scoped to a different principal (no leakage across groups).

**Auth:** `callerCanManageGroup`.

### Request

```json
{
  "name": "Renamed",
  "settings": { "model": "gpt-4o-mini" }
}
```

### 200 — response

Same shape as the create response.

### Errors

- `400 INVALID_JSON` — body is not valid JSON.
- `401 UNAUTHENTICATED` — no session.
- `403 FORBIDDEN` — caller is `member`-only.
- `404 GROUP_NOT_FOUND` — group missing OR caller is not a member.
- `404 NOT_FOUND` — config missing OR scoped to a different principal.
- `422 VALIDATION_ERROR` — `name` empty or driver settings fail schema validation.

## `DELETE /api/v1/groups/{id}/llm-configs/{cid}`

Delete an LLM config scoped to this group's group-principal.

**Auth:** `callerCanManageGroup`.

### 200 — response

```json
{ "data": { "deleted": true } }
```

### Errors

- `401 UNAUTHENTICATED` — no session.
- `403 FORBIDDEN` — caller is `member`-only.
- `404 GROUP_NOT_FOUND` — group missing OR caller is not a member.
- `404 NOT_FOUND` — config missing OR scoped to a different principal.

## `POST /api/v1/groups/{id}/llm-configs/{cid}/set-default`

Promote the config to default for the group. The "only one default per group" invariant is enforced by clearing every other `is_default = true` row sharing the same `principal_id` first. The global default (admin path) is unaffected.

**Auth:** `callerCanManageGroup`.

### 200 — response

Same shape as a single item from `GET /api/v1/groups/{id}/llm-configs`, with `is_default: true` and `updated_at` bumped.

### Errors

- `401 UNAUTHENTICATED` — no session.
- `403 FORBIDDEN` — caller is `member`-only.
- `404 GROUP_NOT_FOUND` — group missing OR caller is not a member.
- `404 NOT_FOUND` — config missing OR scoped to a different principal.

## Groups — CRUD

The settings-pages surface above assumes the group already exists. The CRUD endpoints below are the admin surface for creating and managing those groups. They sit alongside the existing per-group routes under `/api/v1/groups/{id}`.

### `GET /api/v1/groups`

List groups. Members see the groups they belong to; admins see every group. The response reuses the per-group shape returned by `GET /api/v1/groups/{id}`, including `my_role` (`owner` | `admin` | `member`), the four count fields, and `principal_id`.

**Auth:** session.

### 200 — response

```json
{
  "data": {
    "groups": [
      {
        "id": 1,
        "name": "Marketing",
        "description": "Outbound campaign agents",
        "created_by_user_id": 1,
        "principal_id": 5,
        "my_role": "owner",
        "member_count": 3,
        "agent_count": 12,
        "llm_config_count": 2,
        "tool_setting_count": 4,
        "created_at": "2026-08-19T10:00:00+00:00",
        "updated_at": "2026-08-19T10:00:00+00:00"
      }
    ]
  }
}
```

### `POST /api/v1/groups`

Create a group. **Admin only** — the route is gated by `AdminMiddleware`. The caller becomes `role: owner` of the new group and the group's group-principal is materialised in the same transaction.

**Auth:** admin + CSRF.

### Request

```json
{ "name": "Marketing", "description": "Outbound campaign agents" }
```

`name` required (non-empty, ≤ 120 chars); `description` optional (≤ 500 chars).

### 201 — response

Same shape as a single item from `GET /api/v1/groups/{id}`.

### Errors

- `400 INVALID_JSON`
- `401 UNAUTHENTICATED`
- `403 FORBIDDEN` — non-admin
- `422 VALIDATION_ERROR` — empty name

### `PATCH /api/v1/groups/{id}`

Update `name` / `description` / `profile_picture`. **Admin only.** The `profile_picture` field uses the same object shape as the agent picture resource.

**Auth:** admin + CSRF.

### `DELETE /api/v1/groups/{id}`

Delete a group. Caller must be a global admin OR the group's `owner` member (plain `admin` / `member` tier callers receive `403 FORBIDDEN`). Returns `409 GROUP_HAS_AGENTS` with `agent_ids` and `reassign_endpoint: /api/v1/agents/{id}/transfer` if any agent still references the group's principal — the operator must transfer or delete those agents first.

**Auth:** session + CSRF; the owner-or-admin gate is in `GroupService::deleteGroup()`.

## Group members — admin surface

The settings-pages surface already documents `GET /api/v1/groups/{id}/members`. The admin CRUD endpoints below layer on top.

### `POST /api/v1/groups/{id}/members`

Add a member. Caller must be group owner, group admin, or global admin. Admins cannot touch `owner` rows; finer role-tier rules are enforced inside `GroupService::addMember()`. The endpoint accepts either a `user_id` or an `email`, but not both.

**Auth:** session + CSRF.

### Request

```json
{ "user_id": 7, "role": "admin" }
```

Or by email (mutually exclusive with `user_id`):

```json
{ "email": "ada@example.com", "role": "admin" }
```

`role` ∈ {`owner`, `admin`, `member`}; default `member` when omitted.

### 201 — response

The new member record, with `name` and `email` enriched from the `users` table:

```json
{
  "data": {
    "member": {
      "user_id": 7,
      "name": "Ada Lovelace",
      "email": "ada@example.com",
      "role": "admin",
      "joined_at": "2026-08-19T10:00:00+00:00"
    }
  }
}
```

### Errors

- `400 INVALID_JSON`
- `401 UNAUTHENTICATED`
- `403 FORBIDDEN` — caller is `member`-only
- `404 GROUP_NOT_FOUND`
- `404 USER_NOT_FOUND` — `email` / `user_id` resolves to no user
- `409 ALREADY_A_MEMBER` — the user is already a member
- `422 VALIDATION_ERROR` — both `user_id` and `email` provided, neither provided, or invalid `role`

### `PATCH /api/v1/groups/{id}/members/{uid}`

Change a member's role. Same authorisation gate as POST. Body: `{ "role": "admin" }`.

**Auth:** session + CSRF.

### `DELETE /api/v1/groups/{id}/members/{uid}`

Remove a member. Same authorisation gate. The last `owner` of a group cannot be removed — surfaces `403 FORBIDDEN` with `GroupMembershipRuleException`.

**Auth:** session + CSRF.

## Group picture — multipart

### `POST /api/v1/groups/{id}/picture/image`

Multipart avatar upload (≤ 1 MiB; `image/*` MIME allowlist; byte-decode verified). Mirrors the agent picture endpoint — bytes land in `media_assets` (`upload_source = 'avatar'`) and a 1:1 row is upserted into `group_pictures`. If the request also supplies a `picture` JSON part, the archetype / variant / palette are applied first, then the uploaded image.

**Auth:** session + CSRF. Caller must be group owner / admin / global admin.

### `DELETE /api/v1/groups/{id}/picture/image`

Clear the group picture and reset to the default archetype (`collaborative / null / slate`).

**Auth:** session + CSRF.

## Agent transfer

### `POST /api/v1/agents/{id}/transfer`

Re-key an agent's `principal_id` to a different principal the caller controls. Authorisation is enforced inside `AgentPrincipalService::transferAgent()`:

- Caller must be admin OR `role ∈ {owner, admin}` of the **source** principal (admins skip this check).
- Caller must be admin OR `role ∈ {owner, admin}` of the **target** principal, OR be the owner of the target when the target is the caller's own user-principal.

**Auth:** session + CSRF.

### Request

```json
{ "principal_id": 5 }
```

### 200 — response

```json
{
  "data": {
    "agent": {
      "id": 42,
      "name": "Outreach Bot",
      "principal_id": 5,
      "principal": { "id": 5, "type": "group", "name": "Marketing" }
    }
  }
}
```

### Errors

- `400 INVALID_JSON`
- `401 UNAUTHENTICATED`
- `403 FORBIDDEN` — `UnauthorizedTransferException`
- `404 NOT_FOUND` — agent or target principal missing
- `422 VALIDATION_ERROR` — missing/non-positive `principal_id`

## Principals

### `GET /api/v1/principals/me`

Return the principal rows the caller can act as: their own user-principal (auto-created if missing) and the group-principal for every group they belong to. Each entry includes a derived `name` so the principal picker can label entries without a second round-trip.

**Auth:** session (no CSRF — read-only).

### 200 — response

```json
{
  "data": {
    "principals": [
      {
        "id": 3,
        "type": "user",
        "name": "Ada Lovelace",
        "user_id": 1,
        "group_id": null,
        "created_at": "2026-08-19T10:00:00+00:00",
        "updated_at": "2026-08-19T10:00:00+00:00"
      },
      {
        "id": 5,
        "type": "group",
        "name": "Marketing",
        "user_id": null,
        "group_id": 2,
        "created_at": "2026-08-19T10:00:00+00:00",
        "updated_at": "2026-08-19T10:00:00+00:00"
      }
    ]
  }
}
```

When the caller has zero principals (e.g. freshly-registered user with no agent and no group), returns `200` with `principals: []` so the UI can render the empty state without a second round-trip.

### Errors

- `401 UNAUTHENTICATED`.

## Updated contract — `GET /api/v1/agents`

Accepts a repeatable `?principal_id=` query parameter (single `?principal_id=1`, multi `?principal_id=1&principal_id=2`, or PHP-style `?principal_id[]=1&principal_id[]=2`). Values are intersected with `PrincipalResolver::visiblePrincipalIds()` — out-of-scope ids are silently dropped so a caller cannot probe principal existence.

Omitted filter (legacy) returns every agent the caller can see across their visible principals.

## Updated contract — `POST /api/v1/agents`

Accepts an optional `principal_id` body field. The caller must be admin OR control the target principal (`AgentPrincipalService::callerControlsPrincipal`). When omitted, or when the caller doesn't control the value, the agent lands on the caller's own user-principal (materialised on demand).

## STT provider capability resolution

`GET /api/v1/speech/capability` resolves the list of STT providers the
SPA can offer the user. The wire shape is one row per provider class,
keyed by the class-level `name`. Configurable providers (e.g.
`Spora\Speech\OpenAiCompatibleTranscriber`) report the operator's
per-config `display_name` instead of the class-level default — the
registry resolves the effective settings for the calling user via
`ToolConfigService::getEffectiveSettings()` (which cascades through
defaults → global → group[0..N] → user → agent_override) and calls
`OpenAiCompatibleTranscriber::bindLabel()` before reading
`getName()` / `getDisplayName()`. Class-level providers (the Muse
plugin's bespoke multipart) keep their static `getName()` /
`getDisplayName()`.

`configured` is true when ANY cascade level resolves to a usable
config for the caller — including group-scoped configs the user can
see because they belong to a group that has a STT config. Anonymous
callers get `configured: false` (the SPA renders the "please log in"
hint without a second round-trip).

The capability row also exposes two forward-compat fields:

  - `has_global_default` — true when the operator has a global settings
    row for the provider class.
  - `config_id` — the row id of the global settings row when one
    exists for `OpenAiCompatibleTranscriber` (`null` when no row
    exists, and `null` for class-level providers that don't write to
    `tool_configurations`). The SPA deep-links the Capability row
    into the config edit form via this id.

Anonymous callers still get a 200 with the providers' class-level
labels and `configured: false` whenever no provider has a usable API
key — the SPA renders the "please log in" hint without a separate
round-trip.

`config_id` is populated by `ToolConfigService::globalConfigId($provider::class)` — a thin lookup against `tool_configurations.id` for `OpenAiCompatibleTranscriber` rows. Class-level providers (e.g. the future Muse plugin) keep `config_id: null` since they have no row in `tool_configurations`. The SPA deep-links the Capability row into the operator's `/admin/settings/speech-providers?config=<id>` form via this id.

## Speech provider configuration

Operator-facing CRUD for STT provider configurations. The storage layer reuses the existing `tool_configurations` (global, admin-only writes) and `tool_user_settings` (per-principal) tables — no new database tables — and the `***` sentinel convention from `ToolController` so an unchanged `api_key` round-trips through edits without being re-prompted. Full operator guide and worked examples: [`docs/14_speech.md`](14_speech.md).

### `GET /api/v1/speech/provider-configs`

Returns the configs the caller can see.

- admin: every global config (one per registered provider class that has a row in `tool_configurations`).
- non-admin: only the caller's own user-scoped configs.

When `?agent_id=N` is supplied:

- the response is narrowed to configs whose `principal_id` matches the agent's owning principal, plus every global config.
- The dropdown scope is the AGENT's principal, NOT the caller's `visiblePrincipalIds()` — a user-owned agent's dropdown stays scoped to that user's user-principal (no group-owned configs leak in) and a group-owned agent's dropdown stays scoped to that group's principal (no caller user-scoped configs leak in). Mirrors `LLMConfigService::getConfigurationsForAgent()`.
- Visibility: the caller must own the agent (`AgentServiceInterface::getAgent()` returns non-null) OR be a global admin; otherwise the response is `[]` (existence-hide, not 404).
- Combines with `?group_id=N` only when both apply; supplying neither falls back to caller-scoped (`getConfigurationsForUser`).

When `?group_id=N` is supplied:

- members of the group (any role) and global admins receive the group-scoped configs for that group
- non-members receive an empty list (existence-hide)

```jsonc
// 200 OK
{
  "data": {
    "configs": [
      {
        "id": 7,
        "provider_class": "Spora\\Speech\\OpenAiCompatibleTranscriber",
        "provider_display_name": "OpenAI Compatible",
        "scope": "group",
        "display_name": "Team Voxtral",
        "settings": { "display_name": "Team Voxtral", "base_url": "https://api.mistral.ai/v1", "model": "voxtral-mini-latest", "language": "", "http_timeout_seconds": "60", "api_key": "***" },
        "principal_id": 12,
        "created_at": "2026-09-11T12:34:56+00:00",
        "updated_at": "2026-09-11T12:34:56+00:00"
      }
    ]
  }
}
```

```jsonc
// 200 OK
{
  "data": {
    "configs": [
      {
        "id": 7,
        "provider_class": "Spora\\Speech\\OpenAiCompatibleTranscriber",
        "provider_display_name": "OpenAI Compatible",
        "scope": "global",
        "display_name": "Mistral Voxtral (prod)",
        "settings": { "display_name": "Mistral Voxtral (prod)", "base_url": "https://api.mistral.ai/v1", "model": "voxtral-mini-latest", "language": "", "http_timeout_seconds": "60", "api_key": "***" },
        "principal_id": null,
        "created_at": "2026-09-11T12:34:56+00:00",
        "updated_at": "2026-09-11T12:34:56+00:00"
      },
      {
        "id": 12,
        "provider_class": "Spora\\Speech\\OpenAiCompatibleTranscriber",
        "provider_display_name": "OpenAI Compatible",
        "scope": "user",
        "display_name": "Personal Mistral",
        "settings": { "...": "..." },
        "principal_id": 8,
        "created_at": "2026-09-11T13:00:00+00:00",
        "updated_at": "2026-09-11T13:00:00+00:00"
      }
    ]
  }
}
```

### Errors

- `401 UNAUTHENTICATED` — `AuthMiddleware` rejects anonymous requests before they reach the controller.

### `GET /api/v1/speech/provider-configs/schema`

Returns the provider-class picker schema: every registered `SpeechToTextProviderInterface` with its declared `#[ToolSetting]` attributes (label, type, default, required, validation regex). Used by the create form's provider picker. Adding a new plugin-provided STT class is a server-side change that auto-appears in the UI.

```jsonc
// 200 OK
{
  "data": {
    "providers": [
      {
        "class": "Spora\\Speech\\OpenAiCompatibleTranscriber",
        "display_name": "OpenAI Compatible",
        "settings_schema": [
          { "key": "display_name", "label": "Display name", "type": "text", "required": true, "validation": "/^[A-Za-z0-9 _\\-\\.\\(\\)]{1,80}$/" },
          { "key": "api_key",      "label": "API Key",       "type": "password", "required": true },
          { "key": "base_url",     "label": "Base URL",      "type": "text", "required": true, "default": "https://api.openai.com/v1", "validation": "#^https?://[^\\s]+$#" },
          { "key": "model",        "label": "Model",         "type": "text", "required": true, "default": "whisper-1" },
          { "key": "language",     "label": "Language hint (BCP-47)", "type": "text", "required": false, "default": "" },
          { "key": "http_timeout_seconds", "label": "HTTP timeout (seconds)", "type": "text", "required": false, "default": "60", "validation": "/^\\d+$/" }
        ]
      }
    ]
  }
}
```

### `POST /api/v1/speech/provider-configs`

Create a config. Use `PUT /api/v1/speech/provider-configs/{id}` to update an existing row. Re-posting the same `(scope, provider_class, principal_id)` creates a second row, not an update.

Body:

```jsonc
{
  "provider_class": "Spora\\Speech\\OpenAiCompatibleTranscriber",
  "scope": "global",          // or "user" or "group"
  "group_id": 12,             // required when scope="group"; names the group
  "settings": {
    "api_key": "sk-...",
    "display_name": "Mistral Voxtral (prod)",
    "base_url": "https://api.mistral.ai/v1",
    "model": "voxtral-mini-latest",
    "language": "en-US",
    "http_timeout_seconds": "60"
  }
}
```

Auth rules:

- `scope: 'global'` — global admin only.
- `scope: 'user'`   — the caller (no extra permission needed).
- `scope: 'group'`  — caller must be `owner` / `admin` of the named
  group OR a global admin. The body's `group_id` names the group.

Response:

- `200 OK` — `{data: {config: ConfigResource}}` on success.
- `403 SPEECH_PROVIDER_CONFIG_FORBIDDEN` — `scope: 'global'` was
  requested by a non-admin, or `scope: 'group'` was requested by
  a non-admin caller who isn't a group admin.
- `404 SPEECH_PROVIDER_CONFIG_NOT_FOUND` — `provider_class` is not
  a registered speech provider class.
- `422 SPEECH_PROVIDER_CONFIG_INVALID` — settings key is unknown to
  the schema, a required field is missing, a value fails its declared
  regex validation, or `scope: 'group'` was supplied without a
  positive `group_id` (or `scope` wasn't `'group'` while `group_id`
  was set).

```bash
# Global (admin)
curl -X POST https://spora.example.com/api/v1/speech/provider-configs \
  -b cookies.txt -H 'Content-Type: application/json' \
  -H 'X-CSRF-Token: ...' \
  -d '{
    "provider_class": "Spora\\Speech\\OpenAiCompatibleTranscriber",
    "scope": "global",
    "settings": {
      "api_key": "sk-mistral-...",
      "display_name": "Mistral Voxtral (prod)",
      "base_url": "https://api.mistral.ai/v1",
      "model": "voxtral-mini-latest"
    }
  }'

# Group (group admin OR global admin)
curl -X POST https://spora.example.com/api/v1/speech/provider-configs \
  -b cookies.txt -H 'Content-Type: application/json' \
  -H 'X-CSRF-Token: ...' \
  -d '{
    "provider_class": "Spora\\Speech\\OpenAiCompatibleTranscriber",
    "scope": "group",
    "group_id": 12,
    "settings": {
      "api_key": "sk-mistral-...",
      "display_name": "Team Voxtral",
      "base_url": "https://api.mistral.ai/v1",
      "model": "voxtral-mini-latest"
    }
  }'
```

```bash
curl -X POST https://spora.example.com/api/v1/speech/provider-configs \
  -b cookies.txt -H 'Content-Type: application/json' \
  -H 'X-CSRF-Token: ...' \
  -d '{
    "provider_class": "Spora\\Speech\\OpenAiCompatibleTranscriber",
    "scope": "global",
    "settings": {
      "api_key": "sk-mistral-...",
      "display_name": "Mistral Voxtral (prod)",
      "base_url": "https://api.mistral.ai/v1",
      "model": "voxtral-mini-latest"
    }
  }'
```

### `PUT /api/v1/speech/provider-configs/{id}`

Update the settings on an existing config. The id is the row id from a prior `GET` / `POST` response — admin-only for global configs, owner-only for user-scope configs.

Body: `{"settings": {...}}`. Sending `api_key: "***"` keeps the existing value (matches `ToolConfigService`'s `***` sentinel convention).

Response: same as `POST`.

### `DELETE /api/v1/speech/provider-configs/{id}`

Delete a config by id.

- `200 OK` — `{data: {deleted: true}}` on success.
- `403 SPEECH_PROVIDER_CONFIG_FORBIDDEN` — caller isn't allowed to delete this row (e.g. non-admin caller trying to delete a global or other-group config).
- `404 SPEECH_PROVIDER_CONFIG_NOT_FOUND` — id doesn't exist (or isn't visible to the caller).

### `POST /api/v1/speech/transcribe`

Transcribe a recorded audio asset via the first-configured STT provider.
Backed by the controller's full `defaults → global → group[0..N] → user
→ agent_override` cascade resolution: the registry picks the first
provider whose effective `api_key` (or `isConfigured()` for class-level
providers) resolves for the caller's principal ids.

Body:

```jsonc
{
  "media_id": "00000000-0000-4000-8000-000000000abc",   // UUID, required
  "language": "en-US",                                  // optional BCP-47 hint
  "agent_id": 42                                        // optional, must belong to caller
}
```

Response:

```jsonc
// 200 OK
{
  "data": {
    "text": "hello world",
    "language": "en",
    "duration_ms": 1234
  }
}
```

Errors:

- `401 UNAUTHORIZED` — no active session.
- `404 MEDIA_NOT_FOUND` — `media_id` does not exist or is not owned by the caller.
- `422 VALIDATION_ERROR` — body is not valid JSON, `media_id` is missing, or `agent_id` does not belong to the caller.
- `422 INVALID_AUDIO` — the asset is in external-only storage (provider needs bytes, not a URL), the upstream STT returned an empty transcript, or the asset's MIME is not one the provider accepts (`audio/webm`, `audio/ogg`, `audio/mp4`, `audio/wav`, `audio/mpeg`, `audio/flac`).
- `502 SPEECH_PROVIDER_FAILED` — upstream STT rejected the request (transport error or HTTP 4xx/5xx).
- `503 SPEECH_PROVIDER_UNAVAILABLE` — no provider is configured at any cascade level the caller can see.

`agent_id` is threaded through to the cascade as the deepest level, so
the per-agent override in `agent_tool_overrides` wins over group /
user / global settings when set. Composers that don't know the active
agent (e.g. a "New Chat" picker) omit the field; the cascade falls
through as before.

## Temporary-file media lifecycle

### `POST /api/v1/media/{id}/keep`

Pin a temp row as permanent so the per-(user, agent) retention sweep leaves it alone. Idempotent — calling on a non-temp row returns 200 without re-saving.

- **Auth**: global admin OR asset owner (`asset.user_id == currentUserId`); non-owners receive 403, missing rows 404.
- **Body**: empty.
- **Response 200**: `{data: <MediaAsset with is_temporary=false>}`.

### `POST /api/v1/media` — temp opt-in

Accepts `is_temporary` (bool, default false) on the multipart form. When `is_temporary=true` AND `agent_id` is supplied, the upload controller runs `MediaArchiveService::enforceTempRetention()` to keep the (user, agent) temp set under `agents.voice_message_retention_count`.

### `GET /api/v1/media` — include temp rows

Adds `?include_temporary=true` to surface `is_temporary=TRUE` rows (hidden by default — temp voice transcripts crowd out the permanent grid). Operator CLI (`media:list`) sets `includeTemporary: true` by default.

### `agents.voice_message_retention_count`

Per-agent ceiling on temp rows for the (user, agent) pair. Defaults to 5; 0 disables auto-purge (operator must run `media:gc --temporary` or hit `/keep` per row). Range 0–100 (CHECK constraint, validated server-side at PATCH/POST with a 422).

### `media:gc --temporary [--older-than-hours N]`

Reaps `is_temporary=TRUE` rows older than N hours (default 24). Without `--temporary`, the command keeps its orphan-sweep semantics unchanged.

## `POST /api/v1/tasks/{taskId}/answer`

Submit the operator's answers to a `ask_user_question` batch parked on an `AWAITING_INPUT` task. The whole batch is answered atomically — the request must include exactly one answer per question in the batch, keyed by `header`.

### Request

```jsonc
{
  "tool_call_id": "call_01HX...",          // required, must match a pending batch
  "answers": [                              // required, exactly one entry per question
    {
      "header": "DB backend",                // must match question.header
      "selections": ["SQLite"],              // required, list of selected option labels
      "free_text": null                      // optional, only when allowFreeText=true
    }
  ]
}
```

### 204 — accepted

On success: the answer is appended as a single `role:tool` history row carrying all formatted answers (one row per batch — mirrors the orchestrator's batched-tool-call pattern). When no more pending batches remain, the task flips to `QUEUED`. When other batches are still pending, status stays `AWAITING_INPUT`.

### Errors

- **404 NOT_FOUND** — task is not owned by the calling user.
- **422 VALIDATION_ERROR** — body malformed, `tool_call_id` does not match a pending batch, answer count != question count, an unknown `header` is supplied, an unknown `selections` label, or `free_text` was supplied for a `allowFreeText: false` question.

## `tasks.data.todos` (TodoTool persistence)

The `todo` tool persists its working list on the existing `tasks.data` JSON column at key `todos`:

```jsonc
{
  "version": 1,
  "items": [
    {
      "id": "t_01HX...",
      "content": "Migrate notes table to new schema",
      "activeForm": "Migrating notes table to new schema",
      "status": "in_progress",            // pending | in_progress | completed
      "order": 0
    }
  ],
  "updated_at": "2026-09-19T10:30:00+00:00"
}
```

`tasks.data` is already serialized to the operator chat (`TaskDetail` payload) and surfaced on Mercure events — no extra API is needed for the SPA to render the todo state. The `MessageHistoryBuilder::compactHistory()` path trims `task_history` rows but never touches `tasks.data`, so the todo list survives context-window compaction.

## `tasks.pending_state.pending_questions` (AskUserQuestion lifecycle)

`ask_user_question` batches are persisted on `tasks.pending_state` under the `pending_questions` key (parallel to the existing `pending_tool_calls` for `PENDING_APPROVAL`). Each batch carries the tool_call_id, the question list, and the creation timestamp:

```jsonc
{
  "tool_call_id": "call_01HX...",
  "questions": [
    {
      "question": "Which database backend should we use?",
      "header": "DB backend",
      "options": [
        {"label": "SQLite", "description": "Zero-config, single file", "preview": null},
        {"label": "MySQL",  "description": "Shared hosting friendly", "preview": null}
      ],
      "multiple": false,
      "allowFreeText": true
    }
  ],
  "created_at": "2026-09-19T10:30:00+00:00"
}
```

Multiple batches may coexist when the LLM invokes `ask_user_question` across separate turns. The Mercure intermediate-state event publishes `pending_questions` whenever the task is in `AWAITING_INPUT`, so the chat UI renders the picker without a follow-up `/show` fetch.

The new task status `AWAITING_INPUT` is the parallel to `PENDING_APPROVAL`:
- new lifecycle state, added to `TaskLifecyclePolicy::QUIESCENT_STATUSES`
- flips to `QUEUED` on `/answer` when no more batches remain
- stays `AWAITING_INPUT` when other batches are still pending
- `ToolCallDisposition::AwaitingInput` is the parallel to `AwaitingApproval`

