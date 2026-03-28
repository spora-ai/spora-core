# Spora: REST API Specification

**Version:** 2.0
**Base Path:** `/api/v1`
**Content-Type:** All requests and responses use `application/json`
**Auth Mechanism:** Session cookie (`PHPSESSID`) managed by `delight-im/auth`. The Vue frontend sends requests with `credentials: 'include'`. CSRF protection via `X-XSRF-TOKEN` header (token set in a readable `XSRF-TOKEN` cookie by the server on first load).

**Response envelope — success:**
```json
{ "data": { ... } }
```

**Response envelope — error:**
```json
{ "error": { "code": "MACHINE_READABLE_CODE", "message": "Human-readable description." } }
```

**Standard HTTP status codes:**

| Code | Meaning |
|---|---|
| `200` | Success |
| `201` | Resource created |
| `204` | Success, no body |
| `401` | Not authenticated |
| `403` | Authenticated but not authorized |
| `404` | Resource not found |
| `409` | Conflict (e.g. duplicate) |
| `422` | Validation / semantic error |
| `500` | Internal server error |

**Identifier convention:** Throughout this spec, `{toolClass}` in URL paths is the URL-encoded tool FQCN, e.g. `Spora%5CTools%5CBuiltin%5CSearchWebTool`. The controller decodes this to the full class name.

---

## Auth Endpoints

### `POST /api/v1/auth/login`

**Auth Required:** No

**Request:**
```json
{ "email": "user@example.com", "password": "s3cur3p@ss", "remember_me": false }
```

**Response `200`:**
```json
{ "data": { "user": { "id": 1, "email": "user@example.com", "username": "Alice" } } }
```

**Errors:** `INVALID_CREDENTIALS` (401), `ACCOUNT_UNVERIFIED` (403), `ACCOUNT_SUSPENDED` (403)

---

### `POST /api/v1/auth/logout`

**Auth Required:** Yes | **Response:** `204` No body

---

### `GET /api/v1/auth/me`

**Auth Required:** Yes

**Response `200`:**
```json
{ "data": { "user": { "id": 1, "email": "user@example.com", "username": "Alice", "registered": 1700000000 } } }
```

**Errors:** `UNAUTHENTICATED` (401)

---

### `POST /api/v1/auth/register`

Controlled by `config.php` flag `allow_registration`.

**Auth Required:** No

**Request:**
```json
{ "email": "newuser@example.com", "password": "s3cur3p@ss", "username": "Bob" }
```

**Response `201`:**
```json
{ "data": { "user": { "id": 2, "email": "newuser@example.com", "username": "Bob" } } }
```

**Errors:** `EMAIL_ALREADY_EXISTS` (409), `REGISTRATION_DISABLED` (403), `VALIDATION_ERROR` (422)

---

## Agent Endpoints

### `GET /api/v1/agent`

Return the current user's agent. In V1 always a single agent ("My Assistant").

**Auth Required:** Yes

**Response `200`:**
```json
{
  "data": {
    "agent": {
      "id": 1,
      "user_id": 1,
      "name": "My Assistant",
      "description": "A general-purpose assistant.",
      "recipe_id": "general_assistant",
      "llm_provider": "openai",
      "llm_model": "gpt-4o",
      "max_steps": 10,
      "is_active": true,
      "tools": [
        {
          "tool_class": "Spora\\Tools\\Builtin\\SearchWebTool",
          "tool_name": "search_web",
          "has_override": false
        },
        {
          "tool_class": "Spora\\Tools\\Builtin\\SendEmailTool",
          "tool_name": "send_email",
          "has_override": true
        }
      ],
      "created_at": "2026-01-01T00:00:00Z",
      "updated_at": "2026-03-28T10:00:00Z"
    }
  }
}
```

**Notes:**
- `tools` lists all enabled tools from `agent_tools` junction table with a flag indicating whether an `agent_tool_overrides` row exists for that tool.
- Credential values are never included here. Use `GET /api/v1/tools/{toolClass}/settings` and `GET /api/v1/agent/tools/{toolClass}/override`.

---

### `PATCH /api/v1/agent`

Update agent identity and LLM configuration. Tool enablement is managed separately via the agent tools endpoints.

**Auth Required:** Yes

**Request (all fields optional):**
```json
{
  "name": "My Assistant",
  "description": "Updated description.",
  "recipe_id": "general_assistant",
  "llm_provider": "anthropic",
  "llm_model": "claude-3-5-sonnet-20241022",
  "max_steps": 15,
  "is_active": true
}
```

**Response `200`:**
```json
{ "data": { "agent": { /* same shape as GET /api/v1/agent */ } } }
```

**Errors:** `VALIDATION_ERROR` (422) — invalid `llm_provider`, unknown `recipe_id`, `max_steps` out of range [1, 50]

---

## Tool Registry Endpoints

### `GET /api/v1/tools`

List all tools registered in the system (built-in + `plugins/` directory), with metadata, settings schema, and status relative to the current user's agent.

**Auth Required:** Yes

**Response `200`:**
```json
{
  "data": {
    "tools": [
      {
        "tool_class": "Spora\\Tools\\Builtin\\SearchWebTool",
        "tool_name": "search_web",
        "description": "Search the web and return relevant results.",
        "type": "input",
        "parameters": {
          "type": "object",
          "properties": {
            "query": { "type": "string", "description": "The search query." },
            "max_results": { "type": "number", "description": "Max results to return." }
          },
          "required": ["query"]
        },
        "settings": [
          {
            "key": "api_key",
            "label": "SerpAPI Key",
            "type": "password",
            "description": "Your SerpAPI.com API key.",
            "required": true,
            "scope": "agent"
          }
        ],
        "is_globally_configured": true,
        "is_enabled_for_agent": true,
        "agent_has_override": false
      },
      {
        "tool_class": "Spora\\Tools\\Builtin\\SendEmailTool",
        "tool_name": "send_email",
        "description": "Send an email to a specified address.",
        "type": "output",
        "parameters": {
          "type": "object",
          "properties": {
            "to":      { "type": "string", "description": "Recipient email address." },
            "subject": { "type": "string", "description": "Subject line." },
            "body":    { "type": "string", "description": "Email body (plain text or HTML)." }
          },
          "required": ["to", "subject", "body"]
        },
        "settings": [
          {
            "key": "smtp_host",
            "label": "SMTP Host",
            "type": "text",
            "description": "SMTP server hostname.",
            "required": true,
            "scope": "global"
          },
          {
            "key": "smtp_port",
            "label": "SMTP Port",
            "type": "text",
            "description": "SMTP port (587 or 465).",
            "required": true,
            "scope": "global",
            "default": "587"
          },
          {
            "key": "from_address",
            "label": "From Address",
            "type": "text",
            "description": "The sender email address.",
            "required": true,
            "scope": "agent"
          },
          {
            "key": "password",
            "label": "SMTP Password",
            "type": "password",
            "description": "SMTP authentication password.",
            "required": true,
            "scope": "global"
          }
        ],
        "is_globally_configured": false,
        "is_enabled_for_agent": false,
        "agent_has_override": false
      }
    ]
  }
}
```

**Field notes:**
- `is_globally_configured`: `true` if all `required: true` settings in `tool_configurations` have non-empty values.
- `is_enabled_for_agent`: `true` if an `agent_tools` row exists for this tool and the current agent.
- `agent_has_override`: `true` if an `agent_tool_overrides` row exists for this tool and the current agent.
- `settings[].type === "password"` fields never expose stored values here.

---

### `GET /api/v1/tools/{toolClass}/settings`

Get global configuration for a tool. Password fields masked as `"***"`.

**Auth Required:** Yes

**Response `200`:**
```json
{
  "data": {
    "tool_class": "Spora\\Tools\\Builtin\\SendEmailTool",
    "settings": {
      "smtp_host": "smtp.example.com",
      "smtp_port": "587",
      "from_address": "assistant@example.com",
      "password": "***"
    }
  }
}
```

**Notes:**
- Returns empty object `{}` for `settings` if no `tool_configurations` row exists yet.
- Password fields return `"***"` — they are write-only from the UI's perspective.

**Errors:** `NOT_FOUND` (404) — tool class not registered in the system

---

### `PUT /api/v1/tools/{toolClass}/settings`

Save global configuration for a tool. Replaces all settings. Password fields with value `"***"` are preserved unchanged.

**Auth Required:** Yes

**Request:**
```json
{
  "settings": {
    "smtp_host": "smtp.example.com",
    "smtp_port": "587",
    "from_address": "assistant@example.com",
    "password": "mySmtpP@ss"
  }
}
```

**Response `200`:**
```json
{ "data": { "message": "Global tool settings saved." } }
```

**Security notes:**
- Password fields (`type: "password"`) are encrypted via Libsodium `secretbox` before storage.
- A fresh 24-byte nonce is generated per field per write — two saves of the same value produce different ciphertext (by design).
- If a password field value equals `"***"`, the existing encrypted blob is preserved unchanged.
- `scope: "global"` fields are stored here. `scope: "agent"` fields are also accepted here as the global default.

**Errors:** `NOT_FOUND` (404), `VALIDATION_ERROR` (422)

---

## Agent Tool Endpoints

### `POST /api/v1/agent/tools/{toolClass}/enable`

Enable a tool for the current agent. Creates a row in `agent_tools`.

**Auth Required:** Yes

**Request:** No body required.

**Response `201`:**
```json
{
  "data": {
    "message": "Tool enabled for agent.",
    "tool_class": "Spora\\Tools\\Builtin\\SearchWebTool",
    "tool_name": "search_web"
  }
}
```

**Errors:** `NOT_FOUND` (404) — tool not registered, `CONFLICT` (409) — tool already enabled, `TOOL_NOT_CONFIGURED` (422) — tool has no global configuration and no agent override (must configure before enabling)

---

### `DELETE /api/v1/agent/tools/{toolClass}/enable`

Disable a tool for the current agent. Deletes the `agent_tools` row. Does not remove overrides.

**Auth Required:** Yes

**Response `204`:** No body.

**Errors:** `NOT_FOUND` (404) — tool not enabled for this agent

---

### `GET /api/v1/agent/tools/{toolClass}/override`

Get the agent-level credential override for a tool. Password fields masked as `"***"`. Returns empty settings if no override exists.

**Auth Required:** Yes

**Response `200`:**
```json
{
  "data": {
    "tool_class": "Spora\\Tools\\Builtin\\SearchWebTool",
    "has_override": true,
    "settings": {
      "api_key": "***"
    }
  }
}
```

**Notes:**
- `has_override: false` with empty `settings: {}` means no override row exists; the tool uses global configuration.
- Only `scope: "agent"` settings can appear here. `scope: "global"` settings are never overridable.

**Errors:** `NOT_FOUND` (404) — tool not registered

---

### `PUT /api/v1/agent/tools/{toolClass}/override`

Save agent-level credential overrides for a tool. Only `scope: "agent"` fields are accepted — `scope: "global"` fields are silently ignored. Same `"***"` no-overwrite rule applies.

**Auth Required:** Yes

**Request:**
```json
{
  "settings": {
    "api_key": "sk-my-personal-serpapi-key"
  }
}
```

**Response `200`:**
```json
{ "data": { "message": "Agent tool override saved." } }
```

**Notes:**
- Creates the `agent_tool_overrides` row if it does not exist; replaces it if it does.
- Global-scoped settings in the request body are silently ignored, not an error.

**Errors:** `NOT_FOUND` (404), `VALIDATION_ERROR` (422)

---

### `DELETE /api/v1/agent/tools/{toolClass}/override`

Remove the agent-level override for a tool. The tool falls back to global configuration.

**Auth Required:** Yes

**Response `204`:** No body.

**Errors:** `NOT_FOUND` (404) — no override exists for this tool and agent

---

## Task Endpoints

### `GET /api/v1/tasks`

List tasks for the current user's agent, newest first.

**Auth Required:** Yes

**Query parameters:**

| Param | Type | Default | Notes |
|---|---|---|---|
| `status` | string | (all) | `PENDING`, `RUNNING`, `PENDING_APPROVAL`, `COMPLETED`, `FAILED`, `REJECTED` |
| `page` | integer | `1` | |
| `per_page` | integer | `20` | Max: `100` |

**Response `200`:**
```json
{
  "data": {
    "tasks": [
      {
        "id": 42,
        "agent_id": 1,
        "status": "COMPLETED",
        "user_prompt": "Summarize the latest AI news.",
        "final_response": "Here are the top AI stories this week…",
        "run_count": 3,
        "max_steps": 10,
        "failure_reason": null,
        "created_at": "2026-03-28T09:00:00Z",
        "updated_at": "2026-03-28T09:02:00Z"
      }
    ],
    "pagination": {
      "current_page": 1,
      "per_page": 20,
      "total": 1,
      "last_page": 1
    }
  }
}
```

---

### `POST /api/v1/tasks`

Create and start a new task.

**Auth Required:** Yes

**Request:**
```json
{ "prompt": "Write a blog post about PHP 8.4 and send it by email." }
```

| Field | Type | Required | Notes |
|---|---|---|---|
| `prompt` | string | Yes | 1–10,000 chars |

**Response `201`:**
```json
{
  "data": {
    "task": {
      "id": 43,
      "agent_id": 1,
      "status": "RUNNING",
      "user_prompt": "Write a blog post about PHP 8.4 and send it by email.",
      "final_response": null,
      "run_count": 0,
      "max_steps": 10,
      "failure_reason": null,
      "created_at": "2026-03-28T10:00:00Z",
      "updated_at": "2026-03-28T10:00:00Z"
    }
  }
}
```

**Errors:**
- `VALIDATION_ERROR` (422) — prompt missing or too long
- `AGENT_NOT_CONFIGURED` (422) — agent has no enabled tools with complete global configuration (at least one tool must be fully configured before a task can run)
- `AGENT_INACTIVE` (422) — `is_active` is false

---

### `GET /api/v1/tasks/{taskId}`

Get full task detail: task record, message history, and pending tool call (if any).

**Auth Required:** Yes

**Response `200`:**
```json
{
  "data": {
    "task": {
      "id": 43,
      "agent_id": 1,
      "status": "PENDING_APPROVAL",
      "user_prompt": "Write a blog post and send it by email.",
      "final_response": null,
      "run_count": 2,
      "max_steps": 10,
      "failure_reason": null,
      "created_at": "2026-03-28T10:00:00Z",
      "updated_at": "2026-03-28T10:01:00Z"
    },
    "history": [
      {
        "id": 1,
        "sequence": 0,
        "role": "user",
        "content": "Write a blog post and send it by email.",
        "tool_call_id": null,
        "tool_name": null,
        "created_at": "2026-03-28T10:00:00Z"
      },
      {
        "id": 3,
        "sequence": 2,
        "role": "tool",
        "content": "Here are the top results for 'PHP 8.4 features'…",
        "tool_call_id": "call_abc123",
        "tool_name": "search_web",
        "created_at": "2026-03-28T10:00:07Z"
      }
    ],
    "pending_tool_call": {
      "id": 7,
      "provider_call_id": "call_xyz789",
      "tool_name": "send_email",
      "tool_class": "Spora\\Tools\\Builtin\\SendEmailTool",
      "tool_type": "output",
      "status": "PENDING",
      "proposed_arguments": {
        "to": "boss@example.com",
        "subject": "Blog post draft: PHP 8.4",
        "body": "Hi, please find the draft below…"
      },
      "human_description": "Send an email to boss@example.com with subject 'Blog post draft: PHP 8.4'.",
      "created_at": "2026-03-28T10:01:00Z"
    }
  }
}
```

**Notes:**
- `pending_tool_call` is present only when `task.status === "PENDING_APPROVAL"`, otherwise `null`.
- `history` excludes `tool_call_payload` (internal Orchestrator field not relevant to the UI).
- `human_description` is the output of `OutputToolInterface::describeAction($proposedArguments)`.

**Errors:** `NOT_FOUND` (404) — task does not exist or belongs to another user

---

### `POST /api/v1/tasks/{taskId}/approve`

Approve the pending OutputTool call (with optional argument edits) and resume the agent.

**Auth Required:** Yes

**Request:**
```json
{
  "arguments": {
    "to": "boss@example.com",
    "subject": "Blog post draft: PHP 8.4 (revised)",
    "body": "Hi, please find the draft below…"
  },
  "note": "Approved with minor subject edit."
}
```

| Field | Type | Required | Notes |
|---|---|---|---|
| `arguments` | object | Yes | Must match the tool's parameter schema |
| `note` | string | No | Stored in `tool_calls.approval_note` |

**Response `200`:**
```json
{
  "data": {
    "message": "Tool call approved. Agent is resuming.",
    "task": { "id": 43, "status": "RUNNING" }
  }
}
```

**Errors:** `NOT_FOUND` (404), `INVALID_STATE` (422) — task not in `PENDING_APPROVAL`, `VALIDATION_ERROR` (422) — arguments fail tool schema

---

### `POST /api/v1/tasks/{taskId}/reject`

Reject the pending OutputTool call. The agent is notified and may choose an alternative.

**Auth Required:** Yes

**Request:**
```json
{ "reason": "Do not send this email. The tone is too informal." }
```

| Field | Type | Required | Notes |
|---|---|---|---|
| `reason` | string | No | Surfaced to the LLM. Defaults to `"Action rejected by user."` |

**Response `200`:**
```json
{
  "data": {
    "message": "Tool call rejected. Agent has been notified.",
    "task": { "id": 43, "status": "RUNNING" }
  }
}
```

**Errors:** `NOT_FOUND` (404), `INVALID_STATE` (422) — task not in `PENDING_APPROVAL`

---

## Recipe Endpoints

### `GET /api/v1/recipes`

List available recipe files from the `/recipes/` directory.

**Auth Required:** Yes

**Response `200`:**
```json
{
  "data": {
    "recipes": [
      {
        "id": "general_assistant",
        "name": "General Assistant",
        "description": "A versatile assistant for everyday tasks.",
        "filename": "general_assistant.json"
      },
      {
        "id": "research_agent",
        "name": "Research Agent",
        "description": "Specialized in deep web research and summarization.",
        "filename": "research_agent.yaml"
      }
    ]
  }
}
```

---

## Summary Table

| Method | Path | Auth | Purpose |
|---|---|---|---|
| `POST` | `/api/v1/auth/login` | No | Log in |
| `POST` | `/api/v1/auth/logout` | Yes | Log out |
| `GET` | `/api/v1/auth/me` | Yes | Current user profile |
| `POST` | `/api/v1/auth/register` | No | Register new user |
| `GET` | `/api/v1/agent` | Yes | Get agent config + enabled tools |
| `PATCH` | `/api/v1/agent` | Yes | Update agent config (not tools) |
| `GET` | `/api/v1/tools` | Yes | List all tools with full metadata and status |
| `GET` | `/api/v1/tools/{toolClass}/settings` | Yes | Get global tool settings (passwords masked) |
| `PUT` | `/api/v1/tools/{toolClass}/settings` | Yes | Save global tool settings |
| `POST` | `/api/v1/agent/tools/{toolClass}/enable` | Yes | Enable a tool for the agent |
| `DELETE` | `/api/v1/agent/tools/{toolClass}/enable` | Yes | Disable a tool for the agent |
| `GET` | `/api/v1/agent/tools/{toolClass}/override` | Yes | Get agent credential override (masked) |
| `PUT` | `/api/v1/agent/tools/{toolClass}/override` | Yes | Save agent credential override |
| `DELETE` | `/api/v1/agent/tools/{toolClass}/override` | Yes | Remove agent credential override |
| `GET` | `/api/v1/tasks` | Yes | List tasks (paginated, filterable) |
| `POST` | `/api/v1/tasks` | Yes | Create and start a task |
| `GET` | `/api/v1/tasks/{taskId}` | Yes | Task detail + history + pending call |
| `POST` | `/api/v1/tasks/{taskId}/approve` | Yes | Approve pending tool call |
| `POST` | `/api/v1/tasks/{taskId}/reject` | Yes | Reject pending tool call |
| `GET` | `/api/v1/recipes` | Yes | List available recipes |

---

## Key Design Decisions

**Why three tables for tool configuration instead of a per-agent blob?**
Tool credentials (API keys, SMTP passwords) belong to the tool installation, not to individual agents. Storing them per-agent would force users to re-enter the same credentials for every agent. The three-table design (`tool_configurations` for global defaults + `agent_tool_overrides` for per-agent overrides + `agent_tools` for enablement) allows credentials to be configured once globally while still supporting per-agent overrides (e.g. separate billing keys for different agents).

**Why `scope: "global"` vs `scope: "agent"` on `ToolSetting`?**
Some settings are infrastructure-level and make no sense to override per-agent (e.g. SMTP server hostname, port). Others are naturally per-agent (e.g. the from-address or a personal API key). The `scope` field makes this explicit and is enforced in `ToolConfigService` — global-scoped settings cannot be saved via the override endpoint.

**Why is `tool_class` URL-encoded in path parameters?**
PHP FQCNs contain backslashes which are not safe in URLs. URL-encoding (`Spora%5CTools%5CBuiltin%5CSearchWebTool`) is the standard approach. The controller decodes on receipt.

**Why `TEXT` instead of `JSON` column type?**
SQLite has no `JSON` column type. `TEXT` + Eloquent `$casts = ['field' => 'array']` is the only approach compatible with SQLite, MySQL 5.7, and MariaDB 10.4 without conditional migrations.

**Why store both `tool_name` and `tool_class` in `tool_calls`?**
`tool_name` (snake_case, e.g. `"send_email"`) is what the LLM uses. `tool_class` (FQCN) is what the PHP runtime uses to instantiate the tool. Both are needed; storing both avoids a registry lookup at audit time and prevents ambiguity if two tools share a name.

**Why is `AgentState.messageSnapshot` frozen rather than re-queried from `task_history`?**
At the moment of a pause, the conversation state is frozen. Re-querying on resume would be vulnerable to race conditions if history rows were externally modified. The snapshot is the authoritative source for the resume path.

**Why `PUT` for settings (not `PATCH`)?**
Settings are a single JSON object per tool. Full replacement (`PUT`) with the `"***"` no-overwrite rule is simpler and safer than deep-merging unknown keys. The `"***"` rule ensures password values are never accidentally cleared.
