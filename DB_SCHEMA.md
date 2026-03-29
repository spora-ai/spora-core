# Spora: Database Schema

**Version:** 2.0
**ORM:** `illuminate/database` (Eloquent Capsule)
**Supported Engines:** SQLite 3.35+ (default), MySQL 5.7+ / MariaDB 10.4+
**Migration Strategy:** Eloquent Schema Builder (`Illuminate\Database\Schema\Blueprint`). All column types are chosen for cross-engine compatibility — no engine-specific types. JSON values use `TEXT` + Eloquent `$casts` for SQLite/MySQL portability.
**Charset (MySQL/MariaDB):** `utf8mb4` / `utf8mb4_unicode_ci` on all tables.

---

## Table: `users`

**Eloquent Model:** `Spora\Models\User`
**Purpose:** Managed by `delight-im/auth`. Spora adds `created_at`/`updated_at` after the auth installer runs.

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` auto-increment | No | — | Primary key |
| `email` | `VARCHAR(249)` | No | — | Unique. 249 is delight-im/auth's required max. |
| `password` | `VARCHAR(255)` | No | — | bcrypt hash, managed by delight-im/auth |
| `username` | `VARCHAR(100)` | Yes | NULL | Optional display name |
| `status` | `TINYINT(1)` | No | `0` | delight-im/auth: 0=normal, 1=archived |
| `verified` | `TINYINT(1)` | No | `0` | Email verified flag |
| `resettable` | `TINYINT(1)` | No | `1` | Password reset allowed |
| `roles_mask` | `INT UNSIGNED` | No | `0` | Role bitmask |
| `registered` | `INT UNSIGNED` | No | — | Unix timestamp of registration. delight-im/auth manages this as a Unix int. The API layer converts to ISO 8601 (`DateTime::createFromTimestamp`) when returning it in responses. |
| `last_login` | `INT UNSIGNED` | Yes | NULL | Unix timestamp of last login |
| `force_logout` | `MEDIUMINT UNSIGNED` | No | `0` | Forced-logout counter |
| `created_at` | `TIMESTAMP` | Yes | NULL | Eloquent standard |
| `updated_at` | `TIMESTAMP` | Yes | NULL | Eloquent standard |

**Indexes:** `PRIMARY KEY (id)`, `UNIQUE KEY uq_users_email (email)`

**Notes:** delight-im/auth creates this table via `\Delight\Auth\Auth::install()`. Do NOT rename or remove auth-managed columns.

---

## Table: `agents`

**Eloquent Model:** `Spora\Models\Agent`
**Purpose:** One agent per user in V1 ("My Assistant"). Stores the agent's identity, LLM configuration, and recipe. Tool enablement and credentials are managed via `agent_tools`, `tool_configurations`, and `agent_tool_overrides`.

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` auto-increment | No | — | Primary key |
| `user_id` | `BIGINT UNSIGNED` | No | — | FK → `users.id` |
| `name` | `VARCHAR(100)` | No | `'My Assistant'` | Display name |
| `description` | `TEXT` | Yes | NULL | Human-written description of the agent's purpose |
| `recipe_id` | `VARCHAR(100)` | Yes | NULL | Filename stem of active recipe in `/recipes/`, e.g. `"general_assistant"` |
| `llm_provider` | `VARCHAR(50)` | No | `'openai_compatible'` | `"openai_compatible"`, `"anthropic"`, `"gemini"`, `"mistral"` |
| `llm_model` | `VARCHAR(100)` | No | `'gpt-4o'` | Provider model ID, e.g. `"gpt-4o"`, `"claude-3-5-sonnet-20241022"` |
| `llm_base_url` | `VARCHAR(255)` | Yes | NULL | Base URL override for `openai_compatible` driver (e.g. `https://api.groq.com/openai/v1`). NULL = use driver default (`https://api.openai.com/v1`). Ignored by other drivers. |
| `max_steps` | `TINYINT UNSIGNED` | No | `10` | Hard orchestrator iteration cap [1–50] |
| `is_active` | `TINYINT(1)` | No | `1` | Soft-disable without deletion |
| `created_at` | `TIMESTAMP` | Yes | NULL | Eloquent standard |
| `updated_at` | `TIMESTAMP` | Yes | NULL | Eloquent standard |

**Indexes:** `PRIMARY KEY (id)`, `INDEX idx_agents_user_id (user_id)`

**Foreign Keys:** `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`

**Eloquent Casts:**
```php
protected $casts = [
    'is_active' => 'boolean',
    'max_steps' => 'integer',
];
```

---

## Table: `tool_configurations`

**Eloquent Model:** `Spora\Models\ToolConfiguration`
**Purpose:** Global per-tool settings and credentials. One row per registered tool class. These are the default credentials used by all agents unless overridden via `agent_tool_overrides`. All password-type settings (as declared by `#[ToolSetting(type: "password")]`) are individually encrypted using Libsodium `secretbox`. All reads/writes MUST go through `ToolConfigService`.

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` auto-increment | No | — | Primary key |
| `tool_class` | `VARCHAR(200)` | No | — | Unique. Fully-qualified class name, e.g. `"Spora\\Tools\\Builtin\\SearchWebTool"` |
| `tool_name` | `VARCHAR(100)` | No | — | The `#[Tool(name:)]` value, e.g. `"search_web"`. Denormalized from Attribute for query convenience. |
| `settings` | `TEXT` | Yes | NULL | JSON object keyed by setting key. Password fields stored as `base64_encode(nonce . ciphertext)`. Non-password fields stored as plain strings. e.g. `{"api_key": "<encrypted>", "max_results": "10"}` |
| `created_at` | `TIMESTAMP` | Yes | NULL | Eloquent standard |
| `updated_at` | `TIMESTAMP` | Yes | NULL | Eloquent standard |

**Indexes:** `PRIMARY KEY (id)`, `UNIQUE KEY uq_tool_configurations_class (tool_class)`, `INDEX idx_tool_configurations_name (tool_name)`

**Encryption rule:** Only settings with `#[ToolSetting(type: "password")]` are encrypted. All other types stored as plain strings. The `ToolConfigService` determines which fields to encrypt by reflecting the tool class `#[ToolSetting]` attributes.

**Eloquent Casts:** The `settings` column is intentionally NOT in `$casts` — all access is via `ToolConfigService`, never direct. A guard accessor on the model throws `\LogicException` if accessed directly.

---

## Table: `agent_tool_overrides`

**Eloquent Model:** `Spora\Models\AgentToolOverride`
**Purpose:** Per-agent credential overrides. Stores only the setting keys being overridden — not a full copy of the tool's settings. Resolution: `ToolConfigService::getEffectiveSettings()` starts from `tool_configurations.settings` and merges these overrides on top. Allows an agent to use a separate API key (e.g. separate billing) without duplicating all settings.

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` auto-increment | No | — | Primary key |
| `agent_id` | `BIGINT UNSIGNED` | No | — | FK → `agents.id` |
| `tool_class` | `VARCHAR(200)` | No | — | The tool being overridden. Must match a `tool_configurations.tool_class` value. |
| `settings` | `TEXT` | No | — | JSON object containing only the overridden keys. Same encryption rules as `tool_configurations.settings`. e.g. `{"api_key": "<encrypted>"}` |
| `created_at` | `TIMESTAMP` | Yes | NULL | Eloquent standard |
| `updated_at` | `TIMESTAMP` | Yes | NULL | Eloquent standard |

**Indexes:** `PRIMARY KEY (id)`, `UNIQUE KEY uq_agent_tool_overrides (agent_id, tool_class)`, `INDEX idx_agent_tool_overrides_tool (tool_class)`

**Foreign Keys:** `FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE`

**Constraint:** Only settings with `#[ToolSetting(scope: "agent")]` may be overridden. Settings with `scope: "global"` are silently ignored if present in this table. Enforcement is in `ToolConfigService`, not the DB.

**Eloquent Casts:** Same guard pattern as `tool_configurations.settings` — no direct access, all via `ToolConfigService`.

---

## Table: `agent_tools`

**Eloquent Model:** `Spora\Models\AgentTool`
**Purpose:** Junction table — which tools are enabled for which agent. Replaces the former `agents.enabled_tools` JSON blob. Enables efficient querying in both directions.

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` auto-increment | No | — | Primary key |
| `agent_id` | `BIGINT UNSIGNED` | No | — | FK → `agents.id` |
| `tool_class` | `VARCHAR(200)` | No | — | The enabled tool's FQCN |
| `tool_name` | `VARCHAR(100)` | No | — | Denormalized `#[Tool(name:)]` value for query convenience |
| `auto_approve` | `TINYINT(1)` | Yes | NULL | Per-agent approval override. NULL = use `#[OutputTool(requiresApproval:)]` class default. Only meaningful for OutputTools; ignored for InputTools. |
| `created_at` | `TIMESTAMP` | Yes | NULL | Eloquent standard (when the tool was enabled for this agent) |
| `updated_at` | `TIMESTAMP` | Yes | NULL | Set when `auto_approve` is changed after initial enable |

**Indexes:** `PRIMARY KEY (id)`, `UNIQUE KEY uq_agent_tools (agent_id, tool_class)`, `INDEX idx_agent_tools_tool_name (tool_name)`

**Foreign Keys:** `FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE`

**Eloquent Casts:** none for `auto_approve` — intentionally omitted.

**Notes:**
- To enable a tool: insert a row. To disable: delete the row.
- `tool_name` is denormalized to avoid a Reflection call on every query listing enabled tools.
- `auto_approve = NULL` means "defer to class default" — the Orchestrator falls back to the `#[OutputTool]` attribute. `true`/`false` explicitly override it for this agent.
- `auto_approve` must NOT be cast to `boolean` in Eloquent. The cast would coerce `NULL` to `false` via `(bool) null`, silently collapsing "use class default" into "auto-approve OFF" and breaking the three-state semantics. Read it as raw `0`/`1`/`null` and resolve in the Orchestrator.

---

## Table: `tasks`

**Eloquent Model:** `Spora\Models\Task`
**Purpose:** One record per agent task/run. Tracks lifecycle from creation through completion or failure. Stores the serialized `AgentState` when paused awaiting human approval.

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` auto-increment | No | — | Primary key |
| `agent_id` | `BIGINT UNSIGNED` | No | — | FK → `agents.id` |
| `user_id` | `BIGINT UNSIGNED` | No | — | Denormalized for query efficiency; FK → `users.id` |
| `status` | `VARCHAR(30)` | No | `'PENDING'` | `PENDING`, `RUNNING`, `PENDING_APPROVAL`, `COMPLETED`, `FAILED`, `REJECTED` |
| `user_prompt` | `TEXT` | No | — | The original user instruction |
| `final_response` | `TEXT` | Yes | NULL | LLM's terminal text output when status becomes `COMPLETED` |
| `step_count` | `SMALLINT UNSIGNED` | No | `0` | Number of orchestrator iterations consumed |
| `max_steps` | `TINYINT UNSIGNED` | No | `10` | Copied from `agents.max_steps` at creation time — in-flight tasks are unaffected by agent config changes |
| `pending_state` | `MEDIUMTEXT` | Yes | NULL | JSON-encoded `AgentState`. Only populated when `status = PENDING_APPROVAL`. Cleared on resume or reject. MEDIUMTEXT (16MB) required — `AgentState.messageSnapshot` contains the full conversation history which can exceed MySQL TEXT's 65,535-byte cap at high step counts. |
| `failure_reason` | `TEXT` | Yes | NULL | Failure description, e.g. `"max_steps_exceeded"` or a provider error message. TEXT to accommodate long messages. |
| `created_at` | `TIMESTAMP` | Yes | NULL | Eloquent standard |
| `updated_at` | `TIMESTAMP` | Yes | NULL | Eloquent standard |

**Indexes:** `PRIMARY KEY (id)`, `INDEX idx_tasks_agent_id (agent_id)`, `INDEX idx_tasks_user_id (user_id)`, `INDEX idx_tasks_status (status)`, `INDEX idx_tasks_created_at (created_at)`

**Foreign Keys:** `FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE`, `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`

**Eloquent Casts:**
```php
protected $casts = [
    'step_count' => 'integer',
    'max_steps' => 'integer',
];
```

**Status Transition Rules (enforced in Orchestrator, not DB):**
```
PENDING            → RUNNING
RUNNING            → PENDING_APPROVAL   (OutputTool intercepted, approval required)
RUNNING            → COMPLETED          (LLM returned text, no tool call)
RUNNING            → FAILED             (max_steps exceeded or provider error)
PENDING_APPROVAL   → RUNNING            (human approved OR rejected a tool call → agent resumes loop)
PENDING_APPROVAL   → REJECTED           (user hard-cancels the task entirely — no loop resume)
```

---

## Table: `tool_calls`

**Eloquent Model:** `Spora\Models\ToolCall`
**Purpose:** Append-only audit log of every tool invocation within a task (both Input and Output). Provides the human-review UI with the proposed action details for OutputTools pending approval.

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` auto-increment | No | — | Primary key |
| `task_id` | `BIGINT UNSIGNED` | No | — | FK → `tasks.id` |
| `agent_id` | `BIGINT UNSIGNED` | No | — | Denormalized; FK → `agents.id` |
| `provider_call_id` | `VARCHAR(100)` | No | — | Provider-issued tool call ID, e.g. `"call_abc123"`. Correlates tool results back to the LLM. |
| `tool_name` | `VARCHAR(100)` | No | — | The `#[Tool(name:)]` value, e.g. `"send_email"`. **Not** the class name. |
| `tool_class` | `VARCHAR(200)` | No | — | FQCN of the tool class, e.g. `"Spora\\Tools\\Builtin\\SendEmailTool"`. Stored alongside `tool_name` for unambiguous resolution. |
| `tool_type` | `VARCHAR(10)` | No | — | `"input"` or `"output"` |
| `status` | `VARCHAR(20)` | No | `'PENDING'` | `PENDING`, `APPROVED`, `REJECTED`, `EXECUTED`, `FAILED`. InputTools skip `APPROVED` — they transition PENDING → EXECUTED immediately within the same `tick()`. `APPROVED` and `REJECTED` are OutputTool-only states. |
| `proposed_arguments` | `TEXT` | No | — | JSON-encoded arguments as originally proposed by the LLM |
| `human_description` | `TEXT` | Yes | NULL | Output of `OutputToolInterface::describeAction($proposedArguments)` stored at row creation. NULL for InputTools. Stored (not computed at read time) so the approval UI remains correct even if the plugin is later removed or updated. |
| `approved_arguments` | `TEXT` | Yes | NULL | JSON-encoded arguments after optional human edit. NULL until approved. |
| `result_content` | `TEXT` | Yes | NULL | `ToolResult.content` string after execution |
| `result_data` | `TEXT` | Yes | NULL | JSON-encoded `ToolResult.data` after execution. NULL if not yet executed or data was null. |
| `approved_by` | `BIGINT UNSIGNED` | Yes | NULL | FK → `users.id`. NULL for InputTools (auto-executed). |
| `approval_note` | `VARCHAR(500)` | Yes | NULL | Optional human note at approval or rejection |
| `executed_at` | `TIMESTAMP` | Yes | NULL | When the tool was executed (post-approval for OutputTools) |
| `created_at` | `TIMESTAMP` | Yes | NULL | When the LLM first requested this tool call |
| `updated_at` | `TIMESTAMP` | Yes | NULL | Eloquent standard |

**Indexes:** `PRIMARY KEY (id)`, `INDEX idx_tool_calls_task_id (task_id)`, `INDEX idx_tool_calls_agent_id (agent_id)`, `INDEX idx_tool_calls_status (status)`, `INDEX idx_tool_calls_tool_name (tool_name)`

**Foreign Keys:** `FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE`, `FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE`, `FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL`

**Eloquent Casts:**
```php
protected $casts = [
    'proposed_arguments' => 'array',
    'approved_arguments' => 'array',
    'result_data'        => 'array',
    'executed_at'        => 'datetime',
];
```

---

## Table: `task_history`

**Eloquent Model:** `Spora\Models\TaskHistory`
**Purpose:** Append-only conversation history for each task in LLM-compatible format. Each row is one message. The Orchestrator reads all rows ordered by `sequence` to reconstruct the `messages` array for `LLMRequest`.

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` auto-increment | No | — | Primary key |
| `task_id` | `BIGINT UNSIGNED` | No | — | FK → `tasks.id` |
| `sequence` | `SMALLINT UNSIGNED` | No | — | 0-indexed message ordering. Canonical order is `ORDER BY sequence ASC`. |
| `role` | `VARCHAR(20)` | No | — | `"user"`, `"assistant"`, `"tool"`, `"system"` |
| `content` | `TEXT` | Yes | NULL | Message text. Nullable because `role = "assistant"` messages that only contain a tool call have no text content (`tool_call_payload` carries the data). `role = "tool"` messages contain `ToolResult.content`. |
| `tool_call_id` | `VARCHAR(100)` | Yes | NULL | Set only when `role = "tool"`. Matches `tool_calls.provider_call_id`. Required by providers to correlate results. |
| `tool_name` | `VARCHAR(100)` | Yes | NULL | Set only when `role = "tool"`. The `#[Tool(name:)]` value of the tool that produced this result. |
| `tool_call_payload` | `TEXT` | Yes | NULL | JSON-encoded raw `tool_calls` block from the LLM assistant message. Set only when `role = "assistant"` and a tool was called. Required to reconstruct the exact provider message format on resume. |
| `input_tokens` | `INT UNSIGNED` | Yes | NULL | Input token count. Set only when `role = "assistant"`. |
| `output_tokens` | `INT UNSIGNED` | Yes | NULL | Output token count. Set only when `role = "assistant"`. |
| `created_at` | `TIMESTAMP` | Yes | NULL | Eloquent standard |

**Indexes:** `PRIMARY KEY (id)`, `INDEX idx_task_history_task_id (task_id)`, `UNIQUE KEY uq_task_history_sequence (task_id, sequence)`

**Foreign Keys:** `FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE`

**Notes:**
- No `updated_at` — append-only, rows are never modified.
- `AgentState.messageSnapshot` is the authoritative source for the resume path, not a live re-query of this table.

---

## Eloquent Relationship Summary

```
User              hasOne     Agent                  (V1: one assistant per user)
Agent             hasMany    Task
Agent             hasMany    ToolCall               (via tasks, denormalized)
Agent             hasMany    AgentTool              (enabled tools junction)
Agent             hasMany    AgentToolOverride      (per-agent credential overrides)
Agent             belongsTo  User
Task              hasMany    TaskHistory
Task              hasMany    ToolCall
Task              belongsTo  Agent
TaskHistory       belongsTo  Task
ToolCall          belongsTo  Task
ToolCall          belongsTo  Agent
ToolCall          belongsTo  User                   (approvedBy)
ToolConfiguration hasOne     —                      (global, not agent-specific)
AgentTool         belongsTo  Agent
AgentToolOverride belongsTo  Agent
```

---

## Migration Execution Order

1. `users` — delight-im/auth installer, then Spora `created_at`/`updated_at` columns added
2. `agents` — depends on `users`
3. `tool_configurations` — independent, no FK dependencies
4. `agent_tools` — depends on `agents`
5. `agent_tool_overrides` — depends on `agents`
6. `tasks` — depends on `agents`, `users`
7. `tool_calls` — depends on `tasks`, `agents`, `users`
8. `task_history` — depends on `tasks`

All migrations run inside `app/Core/Database.php` on first boot via Eloquent Schema Builder, guarded by `Schema::hasTable()` checks so they are idempotent.

---

## Key Design Decisions

**Why three tables instead of a single `agents.settings` blob?**
Tool credentials (API keys, SMTP passwords) are not agent-specific — they belong to the tool installation. A single blob forced users to re-enter the same credentials for every agent. The three-table design allows global credentials with optional per-agent overrides (e.g. separate billing keys), matching how real multi-agent setups work.

**Why TEXT instead of JSON column type?**
SQLite has no `JSON` column type. `TEXT` + Eloquent `$casts` is the only approach compatible with SQLite, MySQL 5.7, and MariaDB 10.4 without conditional migrations.

**Why store both `tool_name` and `tool_class` in `tool_calls`?**
`tool_name` is the snake_case identifier sent to/from the LLM (e.g. `"send_email"`). `tool_class` is the PHP FQCN needed to instantiate the tool for execution (e.g. `"Spora\Tools\Builtin\SendEmailTool"`). Both are needed; storing both avoids a registry lookup at audit/review time.

**Why is `task_history.content` nullable?**
When the LLM issues a tool call, its assistant message contains a `tool_calls` block but often no text content. Forcing `content` to be non-null would require storing an empty string, which is semantically misleading. Nullable with `tool_call_payload` populated is the correct model.
