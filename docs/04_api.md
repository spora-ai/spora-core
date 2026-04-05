# Spora: REST API

**Base path:** `/api/v1` | **Format:** `application/json` | **Auth:** Session cookie (`PHPSESSID`)

**Envelopes:**
```json
{ "data": { ... } }           // success
{ "error": { "code": "MACHINE_CODE", "message": "Human text." } }  // error
```

---

## Endpoint Summary

| Method | Path | Auth | Purpose |
|---|---|---|---|
| `POST` | `/auth/login` | No | Log in (rate-limited: 5 req/60s) |
| `POST` | `/auth/logout` | Yes | Log out |
| `GET` | `/auth/me` | Yes | Current user |
| `POST` | `/auth/register` | No | Register (rate-limited: 5 req/60s) |
| `GET` | `/agents` | Yes | List agents |
| `POST` | `/agents` | Yes | Create agent |
| `GET` | `/agents/{id}` | Yes | Get agent |
| `PATCH` | `/agents/{id}` | Yes | Update agent |
| `DELETE` | `/agents/{id}` | Yes | Delete agent |
| `GET` | `/llm-drivers` | No | All registered driver classes + schemas |
| `GET` | `/llm-configs` | Yes | User's LLM configurations |
| `GET` | `/llm-configs/{id}` | Yes | Single LLM config |
| `POST` | `/llm-configs` | Yes | Create LLM config |
| `PUT` | `/llm-configs/{id}` | Yes | Update LLM config |
| `DELETE` | `/llm-configs/{id}` | Yes | Delete LLM config |
| `POST` | `/llm-configs/{id}/set-default` | Yes | Set as user's default |
| `GET` | `/tools` | Yes | All tools with metadata, settings schema, enablement status |
| `GET` | `/tools/{toolClass}/settings` | Yes | Global tool settings (passwords masked `***`) |
| `PUT` | `/tools/{toolClass}/settings` | Yes | Save global tool settings (`***` = no-overwrite) |
| `POST` | `/agents/{id}/tools/{toolClass}/enable` | Yes | Enable tool for agent |
| `PATCH` | `/agents/{id}/tools/{toolClass}` | Yes | Update `auto_approve` |
| `DELETE` | `/agents/{id}/tools/{toolClass}/enable` | Yes | Disable tool |
| `GET` | `/agents/{id}/tools/{toolClass}/override` | Yes | Per-agent credential override (masked) |
| `PUT` | `/agents/{id}/tools/{toolClass}/override` | Yes | Save per-agent credential override (requires tool assigned) |
| `DELETE` | `/agents/{id}/tools/{toolClass}/override` | Yes | Remove per-agent credential override (requires tool assigned) |
| `GET` | `/tasks` | Yes | List tasks (paginated, filterable by status) |
| `POST` | `/tasks` | Yes | Create and start a task |
| `GET` | `/tasks/{taskId}` | Yes | Task detail + history + pending tool call |
| `POST` | `/tasks/{taskId}/approve` | Yes | Approve pending tool call (with optional arg edits) |
| `POST` | `/tasks/{taskId}/reject` | Yes | Reject pending tool call (reason surfaced to LLM) |
| `GET` | `/recipes` | Yes | List available recipes |

**`{toolClass}`** in paths = URL-encoded FQCN, e.g. `Spora%5CTools%5CBuiltin%5CSearchWebTool`.

---

## Rate Limiting

Auth endpoints (`/auth/login`, `/auth/register`) are rate-limited: **5 attempts per 60-second sliding window** per client IP.

**Rate-limited response (429):**
```json
{
  "error": {
    "code": "TOO_MANY_REQUESTS",
    "message": "Too many requests. Please try again later."
  }
}
```

**Headers on all auth responses:**

| Header | Description |
|---|---|
| `X-RateLimit-Limit` | Maximum attempts per window (5) |
| `X-RateLimit-Remaining` | Remaining attempts in current window |
| `Retry-After` | Seconds until window resets (only on 429) |

A successful login clears the IP's rate-limit bucket.

---

## Key Design Decisions

**Multi-tenancy: `user_id` scoping** — `LLMDriverConfiguration` records are scoped to the authenticated user. All CRUD endpoints filter by `user_id = currentUserId()`. No user can read, edit, or delete another user's LLM configurations.

**Agent tool override requires explicit assignment** — `PUT/DELETE /agents/{id}/tools/{toolClass}/override` returns `403 FORBIDDEN` unless the tool has been enabled for the agent via `POST /agents/{id}/tools/{toolClass}/enable`. This prevents arbitrary credential injection.

**Three tables for tool config, not a per-agent blob** — credentials belong to the tool installation, not the agent. Global defaults + optional per-agent overrides without re-entering credentials per agent.

**`scope: "global"` vs `scope: "agent"` on `#[ToolSetting]`** — infrastructure settings (SMTP host) can't be overridden per-agent; personal settings (API key, from-address) can. Enforced in `ToolConfigService`.

**`PUT` for settings, not `PATCH`** — full replacement with `"***"` no-overwrite rule is simpler and safer than deep-merging unknown keys.

**Password fields are write-only** — `GET` returns `"***"` for all `type: "password"` settings. The UI never receives the plaintext.

**CSRF** — `X-XSRF-TOKEN` header matched against the `XSRF-TOKEN` readable cookie set on first load.
