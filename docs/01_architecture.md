# Spora: Architecture

## Configuration

Priority: `OS env` → `.env` → `config.php` → built-in defaults.

- **Shared hosting:** `config.php` (gitignored, like `wp-config.php`) — editable over FTP.
- **Docker/VPS/CI:** `SPORA_*` env vars, skip `config.php` entirely.

**Encryption key separation:** The DB stores encrypted tool credentials; the key must never be in the same backup. The path is recorded in `config.php` as `key_path` (default install writes `storage/secret.key`). `SPORA_SECRET_KEY` (base64 env var) bypasses the file entirely for containers; `SPORA_KEY_PATH` overrides the file path instead.

---

## Tool Taxonomy

**ToolInterface** — every tool implements `Spora\Tools\ToolInterface`. Input vs. output is a per-operation flag, not a class distinction. Read-only / generative operations (`requiresApprovalByDefault: false`) execute without approval; operations marked `requiresApprovalByDefault: true` are intercepted by the Orchestrator for human approval.

Approval resolution for an operation:
1. `agent_tool_operation_overrides.default_requires_approval` per-agent, per-operation override (0/1/null)
2. Fall back to the operation's `#[ToolOperation(requiresApprovalByDefault:)]` class default

If approval required → serialize `AgentState` to DB as `PENDING_APPROVAL`, PHP process exits. On human approval → status set to `RUNNING` (Sync) or `QUEUED` (Worker). `tick()` is invoked again only in sync mode; in worker mode the daemon picks up the task on its next drain cycle (see `Orchestrator::resume()` at `app/Agents/Orchestrator.php:585-589`).

---

## Orchestrator Loop

Stateless and short-lived. Each `tick()` is one full LLM turn (Think → Act). Structured in three phases to avoid holding a DB connection during network I/O:

1. **Claim** — short `lockForUpdate()` transaction: validate status. Lock released before any network call.
2. **LLM call** — blocking HTTP call outside any transaction. `step_count` is incremented after the lock is released.
3. **Write** — append history rows, update task status.

```
start()  → create Task (QUEUED or RUNNING depending on SPORA_SYNC_MODE), call tick() [Sync mode only]
tick()   → [claim] → [LLM call] → branch:
             text response   → COMPLETED
             InputTool call  → execute, append, call tick() again
             OutputTool call → resolve approval:
                               auto-approved     → execute, append, call tick() again
                               requires approval → serialize AgentState → PENDING_APPROVAL, halt
resume() → execute approved tools, write history, set RUNNING → [tick()]
reject() → inject rejection rows, set RUNNING → [tick()]
           (agent chooses alternative action)
step_count >= max_steps → FAILED ("Max steps reached.")
```

Status transitions: `QUEUED → RUNNING → COMPLETED | FAILED | PENDING_APPROVAL ⇄ RUNNING → CANCELLED` (PENDING is the initial value written by the migration; in practice the worker transitions QUEUED→RUNNING before the first tick. The `CANCELLED` terminal status is set by `TaskService::cancelRetryChain` — `REJECTED` is the analogous status for `tool_calls` rows, not `tasks`.)

### Worker Modes (`SPORA_SYNC_MODE`)

`SPORA_SYNC_MODE` is a boolean that flips a single `worker_mode` config flag (`true` → Sync, `false` → Worker). Only two worker modes exist: Sync and Worker.

| Mode | Default | Behaviour |
|---|---|---|
| `sync` (SPORA_SYNC_MODE=true) | ✓ | `start()` creates task as `RUNNING` and calls `tick()` inline. HTTP response blocked until agent completes. Suitable for dev and lightweight deployments. |
| `worker` (SPORA_SYNC_MODE=false) | | `start()` creates task as `QUEUED` and returns immediately. Run `php bin/spora worker:run` (default = daemon, `--once` for cron, `--once --include-queue` for cron-with-queue) to drain. |

In Worker mode, multi-step tasks (multiple LLM turns) still run synchronously within a single worker invocation — the loop continues until `COMPLETED`, `FAILED`, or `PENDING_APPROVAL`.

---

## Plugin System

Drop a folder into `plugins/` with a `plugin.json` manifest (and optional `Plugin.php`). Auto-discovered at boot — no manual registration.

Boot sequence (`app/Plugins/PluginLoader.php`):
1. Glob `plugins/*/plugin.json` and read each manifest
2. Register PSR-4 mappings from `autoload.psr-4` with the Composer classloader
3. `require_once` bootstrap files from `autoload.files` (e.g. the plugin's own `vendor/autoload.php`)
4. `require_once` the manifest's `file` (default `Plugin.php`)
5. Instantiate the declared class; call its `autoload()` for additional PSR-4 bindings
6. `tools()`, `drivers()`, `recipePaths()`, `schemaVersion()`, `migrationsPath()` → register contributions
7. `register(ContainerBuilder)` → arbitrary DI bindings

Plugins can contribute: tools, LLM drivers, recipes, and database migrations. See `app/Plugins/PluginInterface.php` and `docs/07_plugins.md`.

**Status: WIP** — the plugin system is currently a work-in-progress. The hook methods (`tools()`, `drivers()`, `recipePaths()`, `register()`) are declared on the interface and surfaced by the manifest, but the explicit `PluginLoader → DI container` injection path is not yet fully wired up. New drivers, tools, and recipes contributed via plugins may not take effect without additional glue in `app/Plugins/PluginLoader.php` or direct registration via `config.php`.

**Plugin conflicts:** duplicate slugs or duplicate entry-point FQCNs are silently skipped — first-loaded wins. Plugin Composer dependencies are isolated by shipping a separate `vendor/` per plugin (declared in `autoload.files`); the host vendor tree is not affected.

---

## Database

SQLite by default (zero config), MySQL/MariaDB supported via `config.php` or env vars (`SPORA_DB_DRIVER=mysql` + `SPORA_DB_HOST/PORT/NAME/USER/PASSWORD`). All schema managed by `DatabaseSchemaInstaller` using Illuminate Schema Builder — versioned, component-aware, with a hot-path stamp cache. See `docs/02_schema.md`.

**Runtime artifacts in `storage/`:** `.schema_stamp` (DB installer cache) and `spora-worker.lock` (single-instance worker lock) are runtime state, not data — exclude them from backups.
