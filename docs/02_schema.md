# Spora: Database Schema

**ORM:** Eloquent Capsule | **Engines:** SQLite 3.35+ (default), MySQL 5.7+ / MariaDB 10.4+
**Column types:** cross-engine only — `TEXT` + `$casts` instead of `JSON`, no engine-specific types.
**Schema management:** `DatabaseSchemaInstaller` — versioned, component-aware, idempotent (see `app/Core/DatabaseSchemaInstaller.php`).

---

## Tables

| Table | Model | Purpose |
|---|---|---|
| `users` | `Spora\Models\User` | Managed by `delight-im/auth`. Spora adds `created_at`/`updated_at`. |
| `users_2fa`, `users_audit_log`, `users_confirmations`, `users_otps`, `users_remembered`, `users_resets`, `users_throttling` | — | delight-im/auth auxiliary tables. Do not modify. |
| `llm_driver_configurations` | `Spora\Models\LLMDriverConfiguration` | User-scoped LLM driver configs. One row per user+driver. `settings` is encrypted JSON. `is_default` marks user's fallback. |
| `agents` | `Spora\Models\Agent` | One agent per user. Stores identity, `llm_driver_config_id` (FK), `recipe_id`, `max_steps`. |
| `tool_configurations` | `Spora\Models\ToolConfiguration` | Global per-tool settings. One row per tool class. Password fields encrypted via `SecurityManager`. All access via `ToolConfigService` only. |
| `agent_tools` | `Spora\Models\AgentTool` | Junction: which tools are enabled per agent. `auto_approve` is 3-state: `0`/`1`/`null` — never cast to boolean (null = use class attribute default). |
| `agent_tool_overrides` | `Spora\Models\AgentToolOverride` | Per-agent credential overrides for `scope: "agent"` settings. Merged on top of global settings by `ToolConfigService`. |
| `tasks` | `Spora\Models\Task` | One record per agent run. Status lifecycle: `PENDING → RUNNING → COMPLETED / FAILED / PENDING_APPROVAL ⇄ RUNNING → REJECTED`. `pending_state` is MEDIUMTEXT (full conversation JSON can exceed 65KB). |
| `tool_calls` | `Spora\Models\ToolCall` | Append-only audit log of every tool invocation. Stores `proposed_arguments`, `human_description` (frozen at creation, not recomputed), `approved_arguments`, result. |
| `task_history` | `Spora\Models\TaskHistory` | Append-only LLM conversation history. Ordered by `sequence`. `content` nullable (assistant tool-call messages have no text). |

---

## Key Decisions

**`auto_approve` must NOT use Eloquent boolean cast** — `(bool) null === false` collapses "use class default" into "auto-approve OFF", breaking three-state semantics.

**`TEXT` not `JSON` columns** — SQLite has no JSON type. `TEXT` + `$casts` is the only cross-engine approach for SQLite, MySQL 5.7, and MariaDB 10.4.

**`pending_state` is MEDIUMTEXT** — full conversation history at high step counts exceeds MySQL TEXT's 65,535-byte cap.

**`human_description` stored at creation** — frozen so approval UI stays correct even after a plugin is removed or updated.

**Both `tool_name` and `tool_class` stored in `tool_calls`** — `tool_name` is what the LLM uses; `tool_class` is what PHP uses to instantiate. Both needed for unambiguous resolution and audit.

**Migration order:** `users` → `llm_driver_configurations` → `agents` → `tool_configurations` → `agent_tools` → `agent_tool_overrides` → `tasks` → `tool_calls` → `task_history`

---

## Database Schema Installer

All database tables are created and upgraded automatically by `Spora\Core\DatabaseSchemaInstaller` during application boot. This mechanism wraps Laravel's `Migrator` but is optimized for Spora's zero-config, plugin-heavy environment:

- **O(1) Hot Path Cache:** `install()` is called on every application boot. To prevent executing multiple database queries per request, it computes a composite version hash (`core_v1|plugin-a_v2|plugin-b_v1`) and compares it to a local filesystem stamp (`storage/.schema_stamp`). If the hash matches, the installer returns immediately (0 DB queries).
- **Component isolation:** Each plugin (and the Core itself) has a tracked version in the `schema_versions` table.
- **Migration file format:** Migrations should use numbered formats without dates (e.g. `000001_create_table.php`), an anonymous class pattern (`return new class extends Migration {}`), and directly use `Capsule::schema()` instead of the Laravel `Schema` facade. 
- **Plugin Migration Constraints:** Plugin migration files **must** be globally prefixed with the plugin's slug (`{slug}_000001_name.php`) to avoid file collision in the shared `migrations` tracking table. `RuntimeException` is thrown if this is violated.
- **Boot Lifecycle:** `Database::getCapsule()` exposes the static Eloquent capsule after `bootDatabaseConnectionOnly()` to allow the Installer to retrieve the `DatabaseManager` before the rest of the application loads.
