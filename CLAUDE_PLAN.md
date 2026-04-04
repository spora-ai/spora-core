# Spora — Execution Plan

**What is Spora?** The "WordPress of AI Agents" — a portable, zero-config agent orchestration tool in PHP 8.2+. Runs on any shared host (cPanel/FTP). Single "My Assistant" UX in V1, multi-agent DB structure for future scale.

**Reference docs:** `docs/00_index.md` · `docs/01_architecture.md` · `docs/02_schema.md` · `docs/03_interfaces.md` · `docs/04_api.md` · `docs/05_drivers.md` · `docs/06_tools.md` · `docs/07_plugins.md` · `docs/08_logging.md`

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
| Recipes + Plugins (Layer 5) | `RecipeScanner`, `PluginLoader` (strict `plugin.json` manifest enforcement, PSR-4 mapping, nested deps, plugin auto-discovery). See `docs/07_plugins.md` | +24 |
| Schema Installer (Layer 6) | `DatabaseSchemaInstaller` (`schema_versions` wrapping Laravel Migrator). O(1) filesystem stamp cache, plugin migration support. See `docs/02_schema.md` | +17 |
| LLM Drivers (Layer 7) | `OpenAICompatibleDriver`, `AnthropicCompatibleDriver`, `DriverFactory` (plugin-extensible provider registry). `symfony/http-client` transport. See `docs/05_drivers.md` | +17 |
| PSR-3 Logging | `LoggerInterface` (Monolog) bound in DI container from `SPORA_LOG_LEVEL` env var. Injected into LLM drivers and `Orchestrator::safeExecute()`. PII policy: arguments logged at DEBUG only, never at ERROR. See `docs/08_logging.md` | +3 |
| Core Base Toolset (Layer 8) | Always-active: `CurrentTimeTool`, `CalculatorTool`, `ScratchpadTool` (key-value agent memory, `agent_memory` table). Configurable: `TavilySearchTool`, `SerperSearchTool`, `GNewsTool`, `NewsApiTool`, `ReadUrlTool` (SSRF protection, Markdown conversion, truncation), `ReadEmailTool` (IMAP, explicit `mark_as_read` opt-in), `SendEmailTool` (SMTP, allowlist), `CalDavCalendarTool` (RFC 5545 unfolding). See `docs/06_tools.md` | +35 |
| API Polling + Seeders (Layer 9) | `TaskController` `?since_sequence=X` for efficient frontend polling. `bin/spora db:seed` (`SeedCommand` + `DatabaseSeeder`): creates admin user, default agent, enables base tools. | +7 |

**Total: 254 tests, 622 assertions. PHPStan level 5 clean.**

---

## Phase 4 — Frontend ← ACTIVE

**Stack:** Vue 3 + Vite + TypeScript + Tailwind CSS + shadcn-vue in `frontend/`.

**Build:** `vite.config.ts` outputs to `../public/dist`. Dev proxy `/api/` → PHP server. `public/index.php` serves `dist/index.html` as SPA fallback for non-API routes.

**`composer.json` scripts:** `"frontend:dev": "cd frontend && npm run dev"`, `"frontend:build": "cd frontend && npm run build"`

### Step 1 — Scaffold & Auth Pages ✅

- [x] Init Vite + Vue 3 + TypeScript + Tailwind + shadcn-vue in `frontend/`
- [x] Global API client (`frontend/src/api/client.ts`) — fetch wrapper, session cookie, `VITE_API_URL`
- [x] Pinia auth store — `login()`, `logout()`, `me()`, persisted user state
- [x] Pages: Login, Register (route guards: redirect to dashboard if already authenticated)
- [x] SPA fallback in `public/index.php` — non-`/api/` routes serve `public/dist/index.html`
- [x] `composer frontend:dev` / `composer frontend:build` scripts

### Step 2 — Dashboard & Task Chat

- [ ] Task list view: status badge, last-updated timestamp, link to detail
- [ ] Task detail — **WhatsApp-style chat UI**: agent messages as "bubbles", tool calls inline with expandable arguments, system messages (task started/completed/failed) as centered pills
- [ ] Approve / Reject UI for `PENDING_APPROVAL` tasks: show `human_description`, editable arguments form, confirm button
- [ ] Polling loop: `GET /api/v1/tasks/{id}?since_sequence=X` every 2 s; update only new `task_history` entries
- [ ] Composer: textarea + submit → `POST /api/v1/tasks`

### Step 3 — Agent Settings

- [ ] Agent config form: name, description, LLM provider/model, `max_steps`
- [ ] Tool enable/disable toggle (driven by `GET /api/v1/tools`, updates `agent_tools`)
- [ ] Per-tool settings form rendered from `#[ToolSetting]` schema (`key`, `type`, `label`, `description`)
- [ ] Recipe picker: list from `GET /api/v1/recipes`, selection sets `agent.recipe_id`

### Real-Time Architecture

1. **Base (Shared Hosting Safe):** REST polling against `GET /api/v1/tasks/{id}?since_sequence=X` every 2 s. Zero persistent daemons; works on cPanel.
2. **Optional Enhancement:** Mercure / SSE. Server-Sent Events via `symfony/mercure`. The FrankenPHP Docker image bundles Mercure natively — single container, zero extra services.
3. **Web Push & Notifications:** `minishlink/web-push` (VAPID) for OS-level browser notifications when `PENDING_APPROVAL`. Pluggable gateways (Email, Telegram, Slack, Pushover, Ntfy.sh) via Plugin system.

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

### Multimodal / Image Inputs (Vision)
Allowing the agent to "see" by accepting image uploads in the composer.
- **Storage:** Images are uploaded to a `storage/media/` directory. The `task_history.content` column (already `MEDIUMTEXT`) can store a JSON array representing the standard LLM multimodal structure: `[{"type": "text", "text": "..."}, {"type": "image_url", "image_url": {"url": "..."}}]`.
- **Drivers:** `OpenAICompatibleDriver` and `AnthropicCompatibleDriver` will dynamically read the local image files and convert them to Base64 data URIs right before pushing the request to the API.
- **Frontend:** The Composer UI requires an attachment/drag-and-drop zone.

### MCP Server Integration
Connect to Model Context Protocol servers as a source of tools. Question to resolve: is an MCP connection a special "driver", a meta-tool, or a plugin type? Likely a plugin-level contribution that registers a batch of tools at boot, with the MCP transport (stdio/HTTP/SSE) managed inside the plugin.

### User Management
Multi-user: user list, role management (admin/user), per-user agent isolation. Requires `roles_mask` (already in schema) and a UI section.

### Installer (`install.php`)
WordPress-style web installer: DB connection form, generate `config.php`, place encryption key at `~/.spora/secret.key`, create first admin user, verify file permissions.

### Build & Distribution Scripts
- **Shared hosting:** single ZIP (no `vendor/` excluded, `composer install --no-dev` pre-run, htaccess included)
- **Docker (FrankenPHP):** `Dockerfile` + `docker-compose.yml` based on
  [`dunglas/frankenphp`](https://hub.docker.com/r/dunglas/frankenphp), published
  as a release artifact (e.g. `ghcr.io/fabeat/spora:latest`). FrankenPHP ships
  with a **built-in Mercure hub**, so a single container provides PHP + Mercure
  with no extra services — compatible with any VPS or Docker-capable host.
- **One-click deploy:** Cloudron, Coolify, Railway manifests
- Frontend build baked into release artifact (`public/dist/` committed or built in CI)

### Plugin Marketplace
Discovery, install, and update flow for community plugins (similar to wp-plugin directory). Requires signature verification.
