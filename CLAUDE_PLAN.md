# Spora — Execution Plan

**What is Spora?** The "WordPress of AI Agents" — a portable, zero-config agent orchestration tool in PHP 8.2+. Runs on any shared host (cPanel/FTP). Single "My Assistant" UX in V1, multi-agent DB structure for future scale.

**Reference docs:** `docs/architecture.md` · `docs/api.md` · `docs/schema.md` · `docs/interfaces.md` · `docs/plugins.md`

**Stack:** `symfony/http-foundation`, `symfony/messenger`, `nikic/fast-route`, `php-di/php-di`, `illuminate/database` (Eloquent), `delight-im/auth`, `pestphp/pest`, Vue 3 + Vite + Tailwind + shadcn-vue (frontend).

---

## Completed ✅

| Phase | What was built | Tests |
|---|---|---|
| Foundation | Kernel, Router, DI container, SecurityManager (libsodium), Database scaffold (8 tables), config/env priority chain | 32 |
| Auth (Layer 1) | `AuthService`, `AuthController`, session guard | +12 |
| ToolConfigService (Layer 2) | Encrypt/decrypt password fields, global + per-agent settings | +9 |
| Agent + Tool endpoints (Layer 3) | `AgentController` (8 methods), `ToolController` (3 methods) | +24 |
| Orchestrator (Layer 4) | Full state machine (`start/tick/resume/reject`), `OrchestratorProxy`, Messenger wiring, `TaskController` | +24 |
| Recipes + Plugins (Layer 5) | `RecipeScanner`, `PluginLoader` (manifest-only via `plugin.json`), `RecipeController` | +24 |
| Schema Installer (Layer 6) | `DatabaseSchemaInstaller` (Laravel Migrator + `schema_versions`), filesystem stamp cache, plugin migration support, slug-prefix enforcement | +17 |

**Total: 186 tests, 0 failures. PHPStan level 5 clean.**

### Layer 5 — Plugin system details

The plugin system uses manifest-only discovery (`plugin.json` required in every plugin directory). Key design decisions locked in:

- **`slug`** is required in every manifest — validated against `^[a-z0-9][a-z0-9_-]*$`, throws `RuntimeException` on violation. Structural manifest errors (invalid JSON, missing slug, invalid slug format, missing class) throw immediately; unresolvable class at runtime is silently skipped.
- **`plugin.schema.json`** at repo root documents the full manifest contract. Documented in `docs/plugins.md`.
- **`autoload.psr-4`** — multiple namespace → path mappings registered with the Composer classloader. **`autoload.files`** — array of PHP files to `require_once` before instantiation (use `["vendor/autoload.php"]` for plugins shipping their own Composer dependency trees).
- `PluginLoader::getPlugins()` returns `array<string, PluginInterface>` keyed by slug.
- `pluginMigrationPaths()` returns `array<string, array{path, version}>` keyed by slug.

### Layer 6 — Schema Installer details

- `DatabaseSchemaInstaller::install()` is called on every boot — O(1) filesystem stamp cache (`storage/.schema_stamp`) makes it a zero-query no-op on the hot path when the composite version hash matches.
- Stamp hash format: `"core_v1|slug_v1|..."` (sorted plugin parts). Any version bump invalidates the stamp and triggers a full DB check.
- Migration files: no date prefix, numbered format (`000001_name.php`), anonymous class pattern (`return new class extends Migration {}`), use `Capsule::schema()` not `Schema::` facade.
- Plugin migration files **must** be prefixed with the plugin slug (`{slug}_000001_name.php`) — installer throws `RuntimeException` on violation.
- `Database::getCapsule()` exposes the static Capsule instance after `bootDatabaseConnectionOnly()` (needed by `DatabaseSchemaInstaller::buildMigrator()`).

---

## Phase 3 — Infrastructure + Drivers

### Layer 7 — LLM Drivers ← NEXT

**Goal:** Implement `LLMDriverInterface` for OpenAI-compatible and Anthropic endpoints. Unlocks end-to-end task execution.

**Driver A — `OpenAICompatibleDriver`** (`app/Drivers/OpenAICompatibleDriver.php`)
- Provider name: `openai_compatible` (matches `agents.llm_provider` default)
- `POST {base_url}/chat/completions` — standard OpenAI chat completions format
- `base_url` defaults to `https://api.openai.com/v1`; override for Ollama, Groq, LM Studio, Azure, etc.
- Reads `api_key`, `model` from `ToolConfigService` or Agent row fallback
- Parses `finish_reason: tool_calls` vs text

**Driver B — `AnthropicDriver`** (`app/Drivers/AnthropicDriver.php`)
- Provider name: `anthropic`
- `POST https://api.anthropic.com/v1/messages`, header `anthropic-version: 2023-06-01`
- Anthropic request format: `system` separate, `tools` array uses Anthropic schema
- Parses `stop_reason: tool_use` (extract `tool_use` blocks) vs `stop_reason: end_turn` (extract `text` blocks)

**Shared:**
- `app/Drivers/DriverFactory.php` — resolves `agent.llm_provider` → driver instance; merges plugin-registered drivers
- Bind `LLMDriverInterface` via `DriverFactory` in `container.php` (resolves per-request from Agent row)
- `composer.json` — add `symfony/http-client ^7.0`
- `tests/Unit/OpenAICompatibleDriverTest.php`, `tests/Unit/AnthropicDriverTest.php` — mock HTTP responses; test tool call parsing, text response parsing, error handling, rate limit exception

---

## Phase 4 — Frontend

**Stack:** Vue 3 + Vite + TypeScript + Tailwind CSS + shadcn-vue in `frontend/`.

**Build:** `vite.config.ts` outputs to `../public/dist`. Dev proxy `/api/` → PHP server. `public/index.php` serves `dist/index.html` as SPA fallback for non-API routes.

**Scope:**
- Global API client (`frontend/src/api/client.ts`) — fetch wrapper, session cookie, `VITE_API_URL`
- Pinia auth store — `login()`, `logout()`, `me()`, persisted user state
- Pages: Login, Register
- Dashboard: task list, task detail with approve/reject for `PENDING_APPROVAL` tasks. **UI Concept:** Task interaction should look like a WhatsApp chat, treating the Agent as a "User" with a profile picture. Tool calls, arguments, and approvals should be rendered inline within the chat flow. In later stages, multi-agent workflows could be displayed seamlessly as a "Group Chat".
- Agent Settings: Configuration form for name/description/model, tool enable/disable, and per-tool settings (driven by `#[ToolSetting]` schema from `GET /api/v1/tools`).
- Recipe picker: list from `GET /api/v1/recipes`, selection sets `agent.recipe_id`
- Composer: textarea + submit → `POST /api/v1/tasks`

**`composer.json` scripts:** `"frontend:dev": "cd frontend && npm run dev"`, `"frontend:build": "cd frontend && npm run build"`

---

## Backlog

### Agent Execution Triggers
Expand how an Agent Task can be started beyond a manual UI click:
1. **Manual:** User clicks "run" via the composer UI (current).
2. **Scheduled:** Cron jobs or a daemon worker executes an agent task at a specific time or recurring interval.
3. **Message-Driven:** An agent is configured with a base prompt (e.g., as a research specialist) and triggers a task when it receives a topic/message payload. This natively unlocks **Agent-to-Agent Handovers**, where one agent can prompt another via this message interface.

### Tool Call Rejection & Retries
Refine the `Orchestrator::reject` flow. Currently, it injects the user's rejection reason and forces a loop.
- **Abort vs. Retry:** The UI should let the user decide whether to completely *abort/stop* the task, or provide feedback and let the agent *retry*.
- **Loop Reset:** If the user actively chooses to provide input and retry an action, the agent's `step_count` (used for `max_steps` protection) should be reset or extended so the agent does not unexpectedly die while trying to fix an error.

### MCP Server Integration
Connect to Model Context Protocol servers as a source of tools. Question to resolve: is an MCP connection a special "driver", a meta-tool, or a plugin type? Likely a plugin-level contribution that registers a batch of tools at boot, with the MCP transport (stdio/HTTP/SSE) managed inside the plugin.

### User Management
Multi-user: user list, role management (admin/user), per-user agent isolation. Requires `roles_mask` (already in schema) and a UI section.

### Installer (`install.php`)
WordPress-style web installer: DB connection form, generate `config.php`, place encryption key at `~/.spora/secret.key`, create first admin user, verify file permissions.

### Build & Distribution Scripts
- **Shared hosting:** single ZIP (no `vendor/` excluded, `composer install --no-dev` pre-run, htaccess included)
- **Docker:** `Dockerfile` + `docker-compose.yml` (PHP-FPM + nginx + optional MySQL)
- **One-click deploy:** Cloudron, Coolify, Railway manifests
- Frontend build baked into release artifact (`public/dist/` committed or built in CI)

### Plugin Marketplace
Discovery, install, and update flow for community plugins (similar to wp-plugin directory). Requires signature verification.
