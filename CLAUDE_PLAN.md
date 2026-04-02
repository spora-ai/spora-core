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
| Recipes + Plugins (Layer 5) | `RecipeScanner`, `PluginLoader` (Strict `plugin.json` manifest enforcement, PSR-4 mapping, nested deps, plugin auto-discovery). See `docs/plugins.md` | +24 |
| Schema Installer (Layer 6) | `DatabaseSchemaInstaller` (`schema_versions` wrapping Laravel Migrator). Features O(1) filesystem stamp cache for hot-path skipping + plugin migration support natively. See `docs/schema.md` | +17 |

**Total: 186 tests, 0 failures. PHPStan level 5 clean.**

---

## Phase 3 — Core Infrastructure (Active)

### Layer 7 — LLM Drivers (Completed)

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

### Layer 8 — Core Base Toolset ← NEXT

**Goal:** Before exposing the UI, the agent needs a solid baseline of built-in tools (The "Senses and Hands") to be genuinely useful. These should be built as standard classes implementing `InputToolInterface` or `OutputToolInterface`.

*Always-Active Base Tools (Built-in, non-disableable defaults):*
- **Current Time / Date** (`Input`): **Crucial.** LLMs don't natively "know" the exact current time. To schedule a calendar event for "tomorrow," the agent must implicitly anchor its temporal awareness.
- **Calculator** (`Input`): LLMs hallucinate math. A simple PHP-backed expression evaluator ensures flawless arithmetic for budgets and scheduling.

*Configurable Core Tools:*
- **Web Search** (`Input`): Tavily, Exa, or Brave Search to search the web and return JSON snippets.
- **Read URL** (`Input`): The critical companion to Web Search. The agent takes a URL, scrapes it, strips the HTML, and returns clean Markdown.
- **News** (`Input`): Fetch latest headlines based on keywords or categories.
- **Weather** (`Input`): Use a free API (like Open-Meteo) to fetch local forecasts based on coordinates/city.
- **E-Mail Access** (`Input` / `Output`): IMAP reader to retrieve unread mail; SMTP writer (`requiresApproval: true`) to draft outgoing emails.
- **Calendar Access** (`Input` / `Output`): CalDAV or Google/Outlook API bridge.
- **Internal Scratchpad / Notes** (`Input` / `Output`): A simple text/SQLite-based key-value store or markdown file writer where the agent can save long-term memories or format final reports for the user to read natively in the app.

---

### Layer 9 — API Polling & Seeders ← NEXT

**Goal:** Ensure the backend API correctly supports real-time capable UI fetching, and generate dummy data to accelerate UI scaffolding.

- **API Polling Optimization:** Update `TaskController` to support `?since_sequence=X`. This enables the frontend to safely execute 2-second REST polling for live conversational updates without crushing bandwidth.
- **Local Development Seeders:** Create a CLI fixture command (e.g. `bin/spora db:seed`) that wipes the DB, creates an Admin, an Agent, and seeds simulated `task_history` conversational logs to simulate complex tool runs for Phase 4.

---

## Phase 4 — Frontend

**Stack:** Vue 3 + Vite + TypeScript + Tailwind CSS + shadcn-vue in `frontend/`.

**Build:** `vite.config.ts` outputs to `../public/dist`. Dev proxy `/api/` → PHP server. `public/index.php` serves `dist/index.html` as SPA fallback for non-API routes.

**Real-Time Architecture (UI Updates vs System Notifications):**
Spora distinguishes between live UI updates (when the tab is open) and asynchronous notifications (when the user is away).

1. **Dashboard Updates (Polling vs Mercure/SSE):**
   - **Base (Shared Hosting Safe):** The frontend will use standard REST Long-Polling against `GET /api/v1/tasks/{id}?since_sequence=X` every 2s. This guarantees functionality on cheap cPanel hosts where persistent background daemons are banned, without tying up all PHP-FPM workers.
   - **Optional Enhancement (Mercure / Server-Sent Events):** Because Orchestrator states are entirely one-way (Server → Client), Server-Sent Events (SSE) via **Mercure** are vastly superior to WebSockets. Users with VPS access can run the Mercure Hub binary. Spora will push updates to the Hub using `symfony/mercure`. The Vue app will connect natively via `new EventSource()` (no heavy JS libraries required). The **FrankenPHP-based Docker image** (see Build & Distribution Scripts) bundles the Mercure hub natively, giving any Docker/VPS user both PHP and Mercure in a single container with zero extra setup.

2. **Web Push & Notification Gateways:**
   - When a task transitions to `PENDING_APPROVAL` or an agent replies, Spora needs to alert the user even if their browser is closed.
   - **Native Web Push (VAPID):** Backend signs standard VAPID payloads using `minishlink/web-push` to trigger OS-level Chrome/Safari/Firefox notifications without requiring *any* persistent daemon. Completely compatible with shared hosting.
   - **Third-Party Gateways:** Pluggable notification channels (E-Mail, Telegram, Slack Webhooks, Pushover, Ntfy.sh) available via the Plugin system.

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

### Multimodal / Image Inputs (Vision)
Allowing the agent to "see" by accepting image uploads in the composer.
- **Storage:** Images are uploaded to a `storage/media/` directory. The `task_history.content` column (already `MEDIUMTEXT`) can store a JSON array representing the standard LLM multimodal structure: `[{"type": "text", "text": "..."}, {"type": "image_url", "image_url": {"url": "..."}}]`.
- **Drivers:** `OpenAICompatibleDriver` and `AnthropicDriver` will dynamically read the local image files and convert them to Base64 data URIs right before pushing the request to the API.
- **Frontend:** The Composer UI requires an attachment/drag-and-drop zone.

### MCP Server Integration
Connect to Model Context Protocol servers as a source of tools. Question to resolve: is an MCP connection a special "driver", a meta-tool, or a plugin type? Likely a plugin-level contribution that registers a batch of tools at boot, with the MCP transport (stdio/HTTP/SSE) managed inside the plugin.

### User Management
Multi-user: user list, role management (admin/user), per-user agent isolation. Requires `roles_mask` (already in schema) and a UI section.

### Installer (`install.php`)
WordPress-style web installer: DB connection form, generate `config.php`, place encryption key at `~/.spora/secret.key`, create first admin user, verify file permissions.

### Build & Distribution Scripts
- **Shared hosting:** single ZIP (no `vendor/` excluded, `composer install --no-dev` pre-run, htaccess included)
- **Docker (standard):** `Dockerfile` + `docker-compose.yml` (PHP-FPM + nginx + optional MySQL)
- **Docker (FrankenPHP):** Publish a `Dockerfile.frankenphp` image based on
  [`dunglas/frankenphp`](https://hub.docker.com/r/dunglas/frankenphp) as a
  named release artifact (e.g. `ghcr.io/fabeat/spora:latest-frankenphp`).
  FrankenPHP ships with a **built-in Mercure hub**, so a single container
  provides PHP + Mercure with no extra services — the recommended image for
  any VPS or Docker-capable shared host that wants real-time SSE updates.
- **One-click deploy:** Cloudron, Coolify, Railway manifests
- Frontend build baked into release artifact (`public/dist/` committed or built in CI)

### Plugin Marketplace
Discovery, install, and update flow for community plugins (similar to wp-plugin directory). Requires signature verification.
