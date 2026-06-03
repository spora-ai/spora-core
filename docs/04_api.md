# Spora: REST API

**Base path:** `/api/v1` | **Format:** `application/json` | **Auth:** Session cookie

**Session cookie name** follows PHP's `session.name` ini setting (default `PHPSESSID`); Spora does not override it. Deployers that need a different cookie name should set `session.name` in `php.ini` or via `ini_set()` before session start.

**Envelopes:**
```json
{ "data": { ... } }           // success
{ "error": { "code": "MACHINE_CODE", "message": "Human text." } }  // error
```

---

## Endpoint Summary

| Method | Path | Auth | Purpose |
|---|---|---|---|
| `GET` | `/health` | No | Liveness probe (no auth, no CSRF) |
| `GET` | `/config` | No | Public app config (no auth) |
| `GET` | `/apps` | Yes | List installed apps/plugins |
| `POST` | `/auth/login` | No | Log in (rate-limited: 5 req/60s) |
| `POST` | `/auth/register` | No | Register (rate-limited: 5 req/60s) |
| `POST` | `/auth/logout` | Yes | Log out |
| `GET` | `/auth/me` | Yes | Current user (returns `data.csrf_token`) |
| `PATCH` | `/auth/password` | Yes | Change password |
| `PATCH` | `/auth/account` | Yes | Update account fields |
| `GET` | `/auth/verify/{selector}` | No | Confirm email verification link |
| `POST` | `/auth/verification/resend` | No | Resend verification email (rate-limited) |
| `POST` | `/auth/forgot-password` | No | Request password reset (rate-limited) |
| `POST` | `/auth/reset-password` | No | Reset password with selector+token |
| `POST` | `/auth/email/change-request` | Yes | Request email change |
| `POST` | `/auth/email/confirm` | No | Confirm email change with selector+token |
| `GET` | `/agents` | Yes | List agents |
| `POST` | `/agents` | Yes | Create agent |
| `GET` | `/agents/{id}` | Yes | Get agent |
| `PATCH` | `/agents/{id}` | Yes | Update agent |
| `DELETE` | `/agents/{id}` | Yes | Delete agent |
| `GET` | `/llm-drivers` | Yes | All registered driver classes + schemas |
| `GET` | `/llm-configs` | Yes | User's LLM configurations |
| `POST` | `/llm-configs` | Yes | Create LLM config |
| `GET` | `/llm-configs/global` | Yes (Admin) | All global LLM configs (admin only) |
| `GET` | `/llm-configs/{id}` | Yes | Single LLM config |
| `PUT` | `/llm-configs/{id}` | Yes | Update LLM config |
| `DELETE` | `/llm-configs/{id}` | Yes | Delete LLM config |
| `POST` | `/llm-configs/{id}/set-default` | Yes | Set as user's default |
| `GET` | `/tools` | Yes | All tools with metadata, settings schema, operations |
| `GET` | `/tools/{toolId}/settings` | Yes | Global tool settings (passwords masked `***`) |
| `PUT` | `/tools/{toolId}/settings` | Yes | Save global tool settings (`***` = no-overwrite) |
| `DELETE` | `/tools/{toolId}/settings` | Yes | Delete global tool settings |
| `GET` | `/tools/{toolId}/user-settings` | Yes | Per-user tool settings (passwords masked `***`) |
| `PUT` | `/tools/{toolId}/user-settings` | Yes | Save per-user tool settings (`***` = no-overwrite) |
| `DELETE` | `/tools/{toolId}/user-settings` | Yes | Delete per-user tool settings |
| `POST` | `/agents/{id}/tools/{toolId}/enable` | Yes | Enable tool for agent |
| `DELETE` | `/agents/{id}/tools/{toolId}/enable` | Yes | Disable tool |
| `GET` | `/agents/{id}/tools/status` | Yes | Status of all tools for an agent |
| `GET` | `/agents/{id}/tools/{toolId}/status` | Yes | Status of one tool for an agent |
| `GET` | `/agents/{id}/tools/{toolId}/override` | Yes | Per-agent credential override (masked; pass `?raw=true` to get stored-only) |
| `PUT` | `/agents/{id}/tools/{toolId}/override` | Yes | Save per-agent credential override |
| `DELETE` | `/agents/{id}/tools/{toolId}/override` | Yes | Remove per-agent credential override |
| `GET` | `/agents/{id}/tools/operations` | Yes | Per-operation enable/approval state for all tools on agent |
| `GET` | `/agents/{id}/tools/{toolId}/operations/{operation}` | Yes | Single per-operation override |
| `PATCH` | `/agents/{id}/tools/{toolId}/operations/{operation}` | Yes | Update per-operation enable/approval |
| `GET` | `/tasks` | Yes | List tasks (paginated, filterable by status) |
| `POST` | `/tasks` | Yes | Create and start a task |
| `GET` | `/tasks/{taskId}` | Yes | Task detail + history + pending tool call |
| `POST` | `/tasks/{taskId}/approve` | Yes | Approve pending tool call (with optional arg edits) |
| `POST` | `/tasks/{taskId}/reject` | Yes | Reject pending tool call (reason surfaced to LLM) |
| `POST` | `/tasks/{taskId}/retry` | Yes | Retry a failed task |
| `POST` | `/tasks/{taskId}/continue` | Yes | Continue a task with a new prompt |
| `DELETE` | `/tasks/{taskId}/retry-chain` | Yes | Cancel an in-progress retry chain |
| `DELETE` | `/tasks/{taskId}` | Yes | Delete a task |
| `GET` | `/notifications` | Yes | List notifications (paginated, filterable unread) |
| `POST` | `/notifications/{id}/read` | Yes | Mark notification as read |
| `POST` | `/notifications/read-all` | Yes | Mark all unread as read |
| `DELETE` | `/notifications` | Yes | Delete all notifications |
| `DELETE` | `/notifications/{id}` | Yes | Delete notification |
| `GET` | `/sse/status` | Yes | Whether Mercure/SSE is configured |
| `GET` | `/sse/auth` | Yes | Mercure hub URL + subscriber JWT for SSE |
| `GET` | `/agents/{id}/templates` | Yes | List prompt templates |
| `POST` | `/agents/{id}/templates` | Yes | Create prompt template |
| `GET` | `/agents/{id}/templates/{templateId}` | Yes | Get prompt template |
| `PUT` | `/agents/{id}/templates/{templateId}` | Yes | Update prompt template |
| `DELETE` | `/agents/{id}/templates/{templateId}` | Yes | Delete prompt template |
| `GET` | `/agents/{id}/scheduled-runs` | Yes | List scheduled runs |
| `POST` | `/agents/{id}/scheduled-runs` | Yes | Create scheduled run |
| `GET` | `/agents/{id}/scheduled-runs/{runId}` | Yes | Get scheduled run |
| `PUT` | `/agents/{id}/scheduled-runs/{runId}` | Yes | Update scheduled run |
| `DELETE` | `/agents/{id}/scheduled-runs/{runId}` | Yes | Delete scheduled run |
| `POST` | `/agents/{id}/scheduled-runs/{runId}/trigger` | Yes | Trigger scheduled run immediately |
| `GET` | `/recipes` | Yes | List available recipes |
| `GET` | `/memories` | Yes | List global memories |
| `POST` | `/memories` | Yes | Create global memory |
| `PATCH` | `/memories/reorder` | Yes | Reorder global memories |
| `GET` | `/memories/{id}` | Yes | Get global memory |
| `PUT` | `/memories/{id}` | Yes | Update global memory |
| `DELETE` | `/memories/{id}` | Yes | Delete global memory |
| `GET` | `/agents/{agentId}/memories` | Yes | List agent-scoped memories |
| `POST` | `/agents/{agentId}/memories` | Yes | Create agent memory |
| `PATCH` | `/agents/{agentId}/memories/reorder` | Yes | Reorder agent memories |
| `GET` | `/agents/{agentId}/memories/{memoryId}` | Yes | Get agent memory |
| `PUT` | `/agents/{agentId}/memories/{memoryId}` | Yes | Update agent memory |
| `DELETE` | `/agents/{agentId}/memories/{memoryId}` | Yes | Delete agent memory |
| `GET` | `/user-preferences/llm` | Yes | Get user's LLM preferences |
| `PUT` | `/user-preferences/llm` | Yes | Update user's LLM preferences |
| `GET` | `/me/profile` | Yes | Get current user profile |
| `PUT` | `/me/profile` | Yes | Update current user profile |
| `GET` | `/me/locations` | Yes | List current user locations |
| `POST` | `/me/locations` | Yes | Add a current user location |
| `PUT` | `/me/locations/{id}` | Yes | Update a current user location |
| `DELETE` | `/me/locations/{id}` | Yes | Delete a current user location |
| `GET` | `/users` | Yes (Admin) | List users (admin only) |
| `POST` | `/users` | Yes (Admin) | Create user (admin only) |
| `GET` | `/users/{id}` | Yes (Admin) | Get user (admin only) |
| `PUT` | `/users/{id}` | Yes (Admin) | Update user (admin only) |
| `PATCH` | `/users/{id}` | Yes (Admin) | Patch user (admin only) |
| `DELETE` | `/users/{id}` | Yes (Admin) | Delete user (admin only) |
| `GET` | `/users/{id}/roles` | Yes (Admin) | List user roles (admin only) |
| `POST` | `/users/{id}/roles` | Yes (Admin) | Grant a role (admin only) |
| `DELETE` | `/users/{id}/roles/{role}` | Yes (Admin) | Revoke a role (admin only) |
| `GET` | `/mail-config` | Yes (Admin) | Get mail transport config (admin only) |
| `PUT` | `/mail-config` | Yes (Admin) | Update mail transport config (admin only) |
| `POST` | `/mail-config/test` | Yes (Admin) | Send a test email (admin only) |
| `GET` | `/mail-templates` | Yes (Admin) | List mail templates (admin only) |
| `POST` | `/mail-templates` | Yes (Admin) | Create mail template (admin only) |
| `GET` | `/mail-templates/{name}/preview` | Yes (Admin) | Preview rendered mail template (admin only) |
| `GET` | `/mail-templates/{id}` | Yes (Admin) | Get mail template (admin only) |
| `PUT` | `/mail-templates/{id}` | Yes (Admin) | Update mail template (admin only) |
| `DELETE` | `/mail-templates/{id}` | Yes (Admin) | Delete mail template (admin only) |

**`{toolId}`** in paths = URL-encoded tool name (the value of `#[Tool(name: ...)]`, e.g. `serper_search`, `tavily_search`, `read_url`, `email`, `llm_configuration`). It is **not** the FQCN — the controller resolves the name to a class via `ToolConfigService::resolveToolClass()` (`app/Services/ToolConfigService.php:504-507`).

---

## Rate Limiting

Auth endpoints (`/auth/login`, `/auth/register`, `/auth/forgot-password`, `/auth/verification/resend`) are rate-limited: **5 attempts per 60-second sliding window** per client IP (`app/Http/AuthController.php:26-27,408-411`).

**Rate-limited response (429):**
```json
{
  "error": {
    "code": "TOO_MANY_REQUESTS",
    "message": "Too many requests. Please try again later."
  }
}
```

**Headers on rate-limited auth responses:**

| Header | Description |
|---|---|
| `X-RateLimit-Limit` | Maximum attempts per window (5) |
| `X-RateLimit-Remaining` | Remaining attempts in current window |
| `Retry-After` | Seconds until window resets (only on 429) |

A successful login clears the IP's rate-limit bucket (`app/Http/AuthController.php:104`).

---

## Key Design Decisions

**Multi-tenancy: `user_id` scoping** — `LLMDriverConfiguration` records are scoped to the authenticated user. All CRUD endpoints filter by `user_id = currentUserId()`. No user can read, edit, or delete another user's LLM configurations.

**Per-agent tool override has no assignment precondition** — `PUT/DELETE /agents/{id}/tools/{toolId}/override` accepts the request as long as the agent belongs to the calling user; the tool does not need to have been enabled first (`app/Services/AgentService.php:290-311`). The override is just a row in `agent_tool_overrides` keyed by `(agent_id, tool_class)`.

**Three tables for tool config, not a per-agent blob** — credentials belong to the tool installation, not the agent. Cascade: schema defaults → `tool_configurations` (global) → `tool_user_settings` (per-user) → `agent_tool_overrides` (per-agent), merged by `ToolConfigService::getEffectiveSettings()` (`app/Services/ToolConfigService.php:187-223`).

**No `scope` flag on `#[ToolSetting]`** — `ToolSetting` has parameters `(key, label, type, description, default, required, options, validation, exposeToLlm)` (`app/Tools/Attributes/ToolSetting.php:18-37`); there is no `scope: "global" | "agent"` parameter. The "global vs. per-agent" distinction is which of the three tables actually stores the value at runtime, not an attribute on the setting.

**`PUT` for settings, not `PATCH`** — full replacement with `"***"` no-overwrite rule is simpler and safer than deep-merging unknown keys.

**Password fields are write-only** — `GET` returns `"***"` for all `type: "password"` settings. The UI never receives the plaintext (`app/Services/ToolConfigService.php:232-244`).

**CSRF** — `X-CSRF-Token` request header matched against the `csrf_token` value stored in the PHP session (`app/Http/Middleware/CsrfMiddleware.php:30,44`; `app/Security/CsrfTokenService.php:13,43-58`). There is no `XSRF-TOKEN` cookie — the token is read from `$_SESSION` only, and the API client supplies it via the response field `data.csrf_token` (e.g. after `POST /auth/login`).
