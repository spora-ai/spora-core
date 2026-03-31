# Spora: Architecture

## Configuration

Priority: `OS env` → `.env` → `config.php` → built-in defaults.

- **Shared hosting:** `config.php` (gitignored, like `wp-config.php`) — editable over FTP.
- **Docker/VPS/CI:** `SPORA_*` env vars, skip `config.php` entirely.

**Encryption key separation:** The DB stores encrypted tool credentials; the key must never be in the same backup. Key stored at `~/.spora/secret.key` (outside web root), path recorded in `config.php`. `SPORA_SECRET_KEY` (base64 env var) bypasses the file entirely for containers.

---

## Tool Taxonomy

**InputToolInterface** — read-only or generative (web search, image generation, DB queries). Executed instantly by the Orchestrator, no human approval needed.

**OutputToolInterface** — writes to the real world (send email, post tweet, create calendar event). Orchestrator intercepts and checks approval:
1. `agent_tools.auto_approve` per-agent override (0/1/null)
2. Fall back to `#[OutputTool(requiresApproval:)]` class attribute

If approval required → serialize `AgentState` to DB as `PENDING_APPROVAL`, PHP process exits. On human approval → re-enter loop via Symfony Messenger queue.

---

## Orchestrator Loop

Runs via `symfony/messenger` — one queue message per `tick()`. Stateless and short-lived (shared hosting compatible).

```
start()  → create Task (RUNNING), dispatch TickMessage
tick()   → load history → LLM call → branch:
             text response   → COMPLETED
             InputTool call  → execute, append, step_count++, re-dispatch
             OutputTool call → resolve approval:
                               auto-approved     → execute, append, step_count++, re-dispatch
                               requires approval → serialize AgentState → PENDING_APPROVAL, halt
resume() → execute approved tool → re-dispatch tick()
reject() → inject rejection into history → re-dispatch tick()
           (agent chooses alternative action)
step_count >= max_steps → FAILED ("max_steps_exceeded")
```

Status transitions: `PENDING → RUNNING → COMPLETED | FAILED | PENDING_APPROVAL ⇄ RUNNING → REJECTED`

---

## Plugin System

Drop a folder into `plugins/` with a `Plugin.php` implementing `PluginInterface`. Auto-discovered at boot — no manual registration.

Boot sequence:
1. `require_once plugins/MyPlugin/vendor/autoload.php` if present (bundled deps)
2. `autoload()` → register PSR-4 mappings for plugin's own classes
3. `tools()`, `drivers()`, `recipePaths()` → register contributions
4. `register(ContainerBuilder)` → arbitrary DI bindings

Plugins can contribute: tools, LLM drivers, recipes, dashboard widgets, nav items, settings pages, and frontend assets. See `app/Plugins/PluginInterface.php`.

**Dependency conflicts:** first-loaded-wins. Plugin authors should use `php-scoper` to prefix vendor namespaces for full isolation.

---

## Recipes

YAML/JSON files in `recipes/` (+ plugin `recipePaths()`). Provide the system prompt / workflow for an agent run. `agents.recipe_id` stores the filename stem (e.g. `"general_assistant"`). Scanned at runtime by `RecipeScanner`.

---

## Database

SQLite by default (zero config), MySQL/MariaDB supported via `config.php` or env vars. All schema managed by `DatabaseSchemaInstaller` using Eloquent Schema Builder — versioned, component-aware, WordPress-style upgrade model. See `docs/schema.md`.
