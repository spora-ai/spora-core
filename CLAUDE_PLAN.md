# Spora — Execution Plan

**What is Spora?** The "WordPress of AI Agents" — a portable, zero-config agent orchestration tool in PHP 8.2+. Runs on any shared host (cPanel/FTP). Single "My Assistant" UX in V1, multi-agent DB structure for future scale.

**Reference docs:** `docs/architecture.md` · `docs/api.md` · `docs/schema.md` · `docs/interfaces.md`

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
| Recipes + Plugins (Layer 5) | `RecipeScanner`, `PluginLoader` (token-based FQCN extraction), `RecipeController` | +24 |

**Total: 147 tests, 0 failures. PHPStan level 5 clean. Coverage ~90%.**

---

## Phase 3 — Infrastructure + Drivers

### Layer 6 — WordPress-Style Schema Installer ← NEXT

**Goal:** Replace `hasTable()` guards with a versioned, component-aware schema installer. Supports safe upgrades for Core and Plugins with no manual SQL.

**Design:**
- `schema_versions` table — one row per component (`core`, or plugin name), columns: `component PK`, `version UINT`, `updated_at`.
- Kernel calls `DatabaseSchemaInstaller::install()` on every boot — fast no-op if versions match.
- Each component provides a `SchemaDefinition`: `schemaVersion(): int`, `schemaTables(): array<table, Blueprint callable>`, `schemaUpgrades(): array<int, Schema callable>`.

**Installer logic per component (idempotent):**
1. Read stored version (0 if missing).
2. If equal to `schemaVersion()` → skip.
3. For each table: missing → create. Exists → add any missing columns (never remove).
4. Run upgrade callbacks for versions `> current AND <= target` in order.
5. Upsert `schema_versions` row.

**Plugin integration:** `PluginInterface` gets `schemaVersion(): int` (default 0) and `schemaDefinition(): ?SchemaDefinition` (default null) — backward-compatible no-ops. `PluginLoader` exposes `pluginSchemaDefinitions()` and is injected into `DatabaseSchemaInstaller`.

**Files:**
- `app/Core/DatabaseSchemaInstaller.php` — full rewrite
- `app/Core/SchemaDefinition.php` — value object (version + tables + upgrades)
- `app/Plugins/PluginInterface.php` — add two default methods
- `app/Core/Database.php` — call installer after connection setup
- `app/Core/container.php` — wire `DatabaseSchemaInstaller` with `PluginLoader`
- `tests/Unit/DatabaseSchemaInstallerTest.php` — fresh install, upgrade path, plugin schema, idempotency

---

### Layer 7 — LLM Drivers

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
- Dashboard: task list, task detail with approve/reject for `PENDING_APPROVAL` tasks
- Agent Settings: name/description/model form, tool enable/disable, per-tool settings form (driven by `#[ToolSetting]` schema from `GET /api/v1/tools`)
- Recipe picker: list from `GET /api/v1/recipes`, selection sets `agent.recipe_id`
- Composer: textarea + submit → `POST /api/v1/tasks`

**`composer.json` scripts:** `"frontend:dev": "cd frontend && npm run dev"`, `"frontend:build": "cd frontend && npm run build"`

---

## Backlog (Future)

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
