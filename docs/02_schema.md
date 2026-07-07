# Spora: Database Schema

**ORM:** Eloquent Capsule | **Engines:** SQLite 3.35+ (default), MySQL 5.7+ / MariaDB 10.4+
**Column types:** cross-engine only — `TEXT` + `$casts` for JSON payloads, no engine-specific types for JSON payloads. (The one native `json` column is `agent_prompt_templates.variables`.)
**Schema management:** `DatabaseSchemaInstaller` — versioned, component-aware, idempotent (see `app/Core/DatabaseSchemaInstaller.php`).

---

## Tables

| Table | Model | Purpose |
|---|---|---|
| `users` | `Spora\Models\User` | Managed by `delight-im/auth`. Spora adds `created_at`/`updated_at`, plus profile fields (`name`, `date_of_birth`, `about_me`, `height_cm`, `weight_kg`). |
| `users_2fa`, `users_audit_log`, `users_confirmations`, `users_otps`, `users_remembered`, `users_resets`, `users_throttling` | — | delight-im/auth auxiliary tables. Do not modify. |
| `agents` | `Spora\Models\Agent` | One agent per user. Stores identity, `llm_driver_config_id` (nullable FK), `recipe_id`, `system_prompt`, `max_steps`, `allow_followup`, `retry_after_minutes`, `max_retries`. |
| `tool_configurations` | `Spora\Models\ToolConfiguration` | Global per-tool settings. One row per tool class. `settings` is encrypted via `SecurityManager`. All access via `ToolConfigService` only. |
| `agent_tools` | `Spora\Models\AgentTool` | Junction: which tools are enabled per agent. Per-operation approval lives in `agent_tool_operation_overrides`. |
| `agent_tool_overrides` | `Spora\Models\AgentToolOverride` | Per-agent credential overrides. Merged on top of global settings by `ToolConfigService`. |
| `agent_tool_operation_overrides` | `Spora\Models\AgentToolOperationOverride` | Per-agent per-operation enablement/approval flags. `enabled` and `default_requires_approval` are nullable 3-state — never cast to boolean. |
| `tasks` | `Spora\Models\Task` | One record per agent run. Status lifecycle: `QUEUED → RUNNING → COMPLETED / FAILED / PENDING_APPROVAL ⇄ RUNNING / CANCELLED` (DB default `PENDING`, but the orchestrator always sets `QUEUED` or `RUNNING` on create). `CANCELLED` is set by `TaskService::cancelRetryChain` (`app/Services/TaskService.php:250`). `parent_task_id` enables follow-up lineage; retry columns (`retry_of_task_id`, `retry_count`, `retry_after`) from migration 0042. Both `pending_state` and `data` are MEDIUMTEXT. `error_code`/`error_message` from migration 0017. |
| `tool_calls` | `Spora\Models\ToolCall` | Audit log of every tool invocation. Stores `proposed_arguments`, `human_description` (frozen at creation), `approved_arguments`, result. Status: `PENDING` → `APPROVED` / `REJECTED` (set on execution). |
| `task_history` | `Spora\Models\TaskHistory` | Append-only LLM conversation history. Ordered by `sequence`. `content` nullable (assistant tool-call messages have no text). `reasoning` stores CoT output. `summarized_sequence_range` records compaction (migration 0045). |
| `llm_driver_configurations` | `Spora\Models\LLMDriverConfiguration` | User-scoped LLM driver configs (`user_id` nullable for global configs as of migration 0047). `settings` is encrypted JSON. `is_default` and `is_global` flags. `context_window` and `max_tokens_output` override defaults. |
| `notifications` | `Spora\Models\Notification` | User notification inbox. `type` in `{task_completed, task_failed, pending_approval, scheduled_run_completed}`. `data` is MEDIUMTEXT and carries `{task_id, agent_id}` (plus `run_id` for `scheduled_run_completed`). `read_at` marks read state. |
| `agent_prompt_templates` | `Spora\Models\AgentPromptTemplate` | Reusable prompt templates per agent. Stores `prompt_template` with Mustache vars, `variables` (native JSON), `max_steps` override. |
| `scheduled_runs` | `Spora\Models\ScheduledRun` | Scheduled or one-shot task triggers. Stores `cron_expression` or `run_at`, `template_id` FK, `next_run_at` precomputed. `scheduled_runs_next` holds one concrete due execution per row. |
| `scheduled_runs_next` | `Spora\Models\ScheduledRunNext` | One row per concrete scheduled execution. Status enum: `PENDING → CLAIMED → DONE / SKIPPED`. Atomic claim: `UPDATE scheduled_runs_next SET status = 'CLAIMED' WHERE status = 'PENDING' AND due_at <= $now LIMIT 1`. Unique `(scheduled_run_id, due_at)` prevents duplicates. `next_run_at` on `scheduled_runs` is a cached derivative updated whenever a new PENDING row is inserted. |
| `user_locations` | `Spora\Models\UserLocation` | Per-user saved locations (e.g. for location-aware tool settings). |
| `tool_user_settings` | `Spora\Models\ToolUserSetting` | Per-user tool settings overrides. Merged between global and agent overrides by `ToolConfigService`. |
| `user_preferences` | `Spora\Models\UserPreference` | Per-user LLM-driver preference (`preferred_llm_config_id` FK). One row per user. |
| `mail_templates` | `Spora\Models\MailTemplate` | Editable email templates. Rendered with `{{var}}` placeholders. |
| `memories` | `Spora\Models\Memory` | Per-agent (or global) persistent memory entries. `content` is LONGTEXT. |
| `media_assets` | `Spora\Models\MediaAsset` | Plugin-archived media (images/audio/video/documents) — UUID PK, nullable FKs to `agents`/`tasks`/`tool_calls`, MIME sniff, dimensions, `storage_mode` ∈ {local, data_url, external}. Idempotent on `(tool_call_id, asset_url)`. See [docs/21_media_archive.md](21_media_archive.md). |

---

## Key Decisions

**Nullable TINYINT columns must NOT use Eloquent boolean cast** — `(bool) null === false` collapses "use class default" into "OFF", breaking three-state semantics. `agent_tool_operation_overrides.enabled` and `default_requires_approval` both rely on this.

**`TEXT` not `JSON` columns** — SQLite has no JSON type. `TEXT` + `$casts` is the only cross-engine approach for SQLite, MySQL 5.7, and MariaDB 10.4. (The one exception is `agent_prompt_templates.variables`, a native `json` column — kept narrow on purpose.)

**`pending_state` is MEDIUMTEXT** — full conversation history at high step counts exceeds MySQL TEXT's 65,535-byte cap. (`tasks.data` and `notifications.data` are also MEDIUMTEXT for the same reason.)

**`human_description` stored at creation** — frozen so approval UI stays correct even after a plugin is removed or updated.

**Both `tool_name` and `tool_class` stored in `tool_calls`** — `tool_name` is what the LLM uses; `tool_class` is what PHP uses to instantiate. Both needed for unambiguous resolution and audit.

**Migration order (on disk):** `users` (+ auth) → `agents` → `tool_configurations` / `agent_tools` / `agent_tool_overrides` → `tasks` → `tool_calls` → `task_history` → `llm_driver_configurations` → `notifications` → `agent_prompt_templates` / `scheduled_runs` → `scheduled_runs_next` → `agent_tool_operation_overrides` → `user_locations` / `tool_user_settings` / `user_preferences` / `mail_templates` / `memories` (plus assorted column-altering migrations interleaved). The version number in the stamp hash is the highest numbered filename.

---

## Database Schema Installer

All database tables are created and upgraded automatically by `Spora\Core\DatabaseSchemaInstaller` during application boot. This mechanism wraps Laravel's `Migrator` but is optimized for Spora's zero-config, plugin-heavy environment:

- **Auto-derived core version:** The core schema version is derived at runtime by scanning `database/migrations/` for the highest-numbered file. There is no `CORE_VERSION` constant to bump — adding a new migration file (e.g. `0050_new_feature.php`) automatically increments the effective version.
- **O(1) Hot Path Cache:** `install()` is called on every application boot. To prevent executing multiple database queries per request, it computes a composite version hash (`core_v49|plugin-a_v2|plugin-b_v1`) and compares it to a local filesystem stamp (`storage/.schema_stamp`). If the hash matches, the installer returns immediately (0 DB queries).
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
