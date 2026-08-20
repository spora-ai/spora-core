# Group Settings Pages — API

> GitHub-style group settings pages (Overview / Members / Agents / Tools / LLM Drivers / Preferences). The existing `/api/v1/groups/{id}` (CRUD) and `/api/v1/groups/{id}/members` endpoints are documented in the spora-ai.com API reference; this file covers the new surface added in the `group-settings-pages` PR.

## Authorisation

- **Read** endpoints use `callerCanSeeGroup()` — members of the group OR global admin. Non-members receive `404 GROUP_NOT_FOUND` (existence-hiding).
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
      "caller_role": "owner",
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

List `llm_driver_configurations` rows scoped to the group's group-principal. **Does not include** global configs (they live at `principal_id = null`) — use the existing `/api/v1/llm-configs/global` admin endpoint for those.

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
