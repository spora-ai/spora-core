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
| `agents` | `Spora\Models\Agent` | One agent per user. Stores identity, `llm_driver_config_id` (FK), `recipe_id`, `max_steps`, `allow_followup`. |
| `agent_prompt_templates` | `Spora\Models\AgentPromptTemplate` | Reusable prompt templates per agent. Stores `prompt_template` with Mustache vars, `variables` JSON schema, `max_steps` override. |
| `scheduled_runs` | `Spora\Models\ScheduledRun` | Scheduled or one-shot task triggers. Stores `cron_expression` or `run_at`, `template_id` FK, `next_run_at` precomputed. |
| `tool_configurations` | `Spora\Models\ToolConfiguration` | Global per-tool settings. One row per tool class. Password fields encrypted via `SecurityManager`. All access via `ToolConfigService` only. |
| `agent_tools` | `Spora\Models\AgentTool` | Junction: which tools are enabled per agent. `auto_approve` is 3-state: `0`/`1`/`null` — never cast to boolean (null = use class attribute default). |
| `agent_tool_overrides` | `Spora\Models\AgentToolOverride` | Per-agent credential overrides for `scope: "agent"` settings. Merged on top of global settings by `ToolConfigService`. |
| `tasks` | `Spora\Models\Task` | One record per agent run. Status lifecycle: `QUEUED → RUNNING → COMPLETED / FAILED / PENDING_APPROVAL ⇄ RUNNING`. `parent_task_id` enables follow-up lineage. `pending_state` is MEDIUMTEXT. |
| `tool_calls` | `Spora\Models\ToolCall` | Append-only audit log of every tool invocation. Stores `proposed_arguments`, `human_description` (frozen at creation), `approved_arguments`, result. |
| `task_history` | `Spora\Models\TaskHistory` | Append-only LLM conversation history. Ordered by `sequence`. `content` nullable (assistant tool-call messages have no text). `reasoning` stores CoT output. |
| `notifications` | `Spora\Models\Notification` | User notification inbox. `type` in `{task_completed, task_failed, pending_approval, scheduled_run_completed}`. `data` JSON carries `{task_id, agent_id}`. `read_at` marks read state. |

---

## Key Decisions

**`auto_approve` must NOT use Eloquent boolean cast** — `(bool) null === false` collapses "use class default" into "auto-approve OFF", breaking three-state semantics.

**`TEXT` not `JSON` columns** — SQLite has no JSON type. `TEXT` + `$casts` is the only cross-engine approach for SQLite, MySQL 5.7, and MariaDB 10.4.

**`pending_state` is MEDIUMTEXT** — full conversation history at high step counts exceeds MySQL TEXT's 65,535-byte cap.

**`human_description` stored at creation** — frozen so approval UI stays correct even after a plugin is removed or updated.

**Both `tool_name` and `tool_class` stored in `tool_calls`** — `tool_name` is what the LLM uses; `tool_class` is what PHP uses to instantiate. Both needed for unambiguous resolution and audit.

**Migration order:** `users` → `llm_driver_configurations` → `agents` → `agent_prompt_templates` → `scheduled_runs` → `tool_configurations` → `agent_tools` → `agent_tool_overrides` → `tasks` → `tool_calls` → `task_history` → `notifications`

---

## Database Schema Installer

All database tables are created and upgraded automatically by `Spora\Core\DatabaseSchemaInstaller` during application boot. This mechanism wraps Laravel's `Migrator` but is optimized for Spora's zero-config, plugin-heavy environment:

- **Auto-derived core version:** The core schema version is derived at runtime by scanning `database/migrations/` for the highest-numbered file. There is no `CORE_VERSION` constant to bump — adding a new migration file (e.g. `0017_new_feature.php`) automatically increments the effective version.
- **O(1) Hot Path Cache:** `install()` is called on every application boot. To prevent executing multiple database queries per request, it computes a composite version hash (`core_v5|plugin-a_v2|plugin-b_v1`) and compares it to a local filesystem stamp (`storage/.schema_stamp`). If the hash matches, the installer returns immediately (0 DB queries).
- **Component isolation:** Each plugin (and the Core itself) has a tracked version in the `schema_versions` table. Plugins declare a static `schemaVersion()` in their manifest — see [plugins.md](07_plugins.md).
- **Migration file format:** Migrations should use zero-padded numbers (e.g. `000001_create_table.php`), an anonymous class pattern (`return new class extends Migration {}`), and directly use `Capsule::schema()` instead of the Laravel `Schema` facade.
- **Plugin Migration Constraints:** Plugin migration files **must** be globally prefixed with the plugin's slug (`{slug}_000001_name.php`) to avoid file collision in the shared `migrations` tracking table. `RuntimeException` is thrown if this is violated.
- **Boot Lifecycle:** `Database::getCapsule()` exposes the static Eloquent capsule after `bootDatabaseConnectionOnly()` to allow the Installer to retrieve the `DatabaseManager` before the rest of the application loads.

### Schema Installer API (for UI Install Script)

For shared-host deployments that cannot run CLI commands, invoke the installer directly from PHP:

```php
use Spora\Core\Database;
use Spora\Core\DatabaseSchemaInstaller;

Database::boot(); // runs install() automatically

// Or manually:
$installer = new DatabaseSchemaInstaller(pluginLoader: null, stampPath: null);
$installer->install();
```

The `DatabaseSchemaInstaller` constructor accepts:
- `$pluginLoader` — pass `null` during early install before plugins are loaded
- `$stampPath` — pass `null` to force migrations to run (no stamp caching), useful for one-shot install scripts

After pulling new code, touching the `storage/.schema_stamp` file (e.g. deleting it) will force the installer to re-run all migrations on the next boot.
