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
| `POST` | `/auth/login` | No | Log in |
| `POST` | `/auth/logout` | Yes | Log out |
| `GET` | `/auth/me` | Yes | Current user |
| `POST` | `/auth/register` | No | Register (controlled by `allow_registration` config) |
| `GET` | `/agent` | Yes | Agent config + enabled tools |
| `PATCH` | `/agent` | Yes | Update agent config |
| `GET` | `/tools` | Yes | All tools with metadata, settings schema, enablement status |
| `GET` | `/tools/{toolClass}/settings` | Yes | Global tool settings (passwords masked `***`) |
| `PUT` | `/tools/{toolClass}/settings` | Yes | Save global tool settings (`***` = no-overwrite) |
| `POST` | `/agent/tools/{toolClass}/enable` | Yes | Enable tool for agent |
| `PATCH` | `/agent/tools/{toolClass}` | Yes | Update `auto_approve` |
| `DELETE` | `/agent/tools/{toolClass}/enable` | Yes | Disable tool |
| `GET` | `/agent/tools/{toolClass}/override` | Yes | Per-agent credential override (masked) |
| `PUT` | `/agent/tools/{toolClass}/override` | Yes | Save per-agent credential override |
| `DELETE` | `/agent/tools/{toolClass}/override` | Yes | Remove per-agent credential override |
| `GET` | `/tasks` | Yes | List tasks (paginated, filterable by status) |
| `POST` | `/tasks` | Yes | Create and start a task |
| `GET` | `/tasks/{taskId}` | Yes | Task detail + history + pending tool call |
| `POST` | `/tasks/{taskId}/approve` | Yes | Approve pending tool call (with optional arg edits) |
| `POST` | `/tasks/{taskId}/reject` | Yes | Reject pending tool call (reason surfaced to LLM) |
| `GET` | `/recipes` | Yes | List available recipes |

**`{toolClass}`** in paths = URL-encoded FQCN, e.g. `Spora%5CTools%5CBuiltin%5CSearchWebTool`.

---

## Key Design Decisions

**Three tables for tool config, not a per-agent blob** — credentials belong to the tool installation, not the agent. Global defaults + optional per-agent overrides without re-entering credentials per agent.

**`scope: "global"` vs `scope: "agent"` on `#[ToolSetting]`** — infrastructure settings (SMTP host) can't be overridden per-agent; personal settings (API key, from-address) can. Enforced in `ToolConfigService`.

**`PUT` for settings, not `PATCH`** — full replacement with `"***"` no-overwrite rule is simpler and safer than deep-merging unknown keys.

**Password fields are write-only** — `GET` returns `"***"` for all `type: "password"` settings. The UI never receives the plaintext.

**CSRF** — `X-XSRF-TOKEN` header matched against the `XSRF-TOKEN` readable cookie set on first load.
