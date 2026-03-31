# Spora Handover & Execution Plan 

**Welcome, Claude!** 
You are tasked with building the foundation of **Spora**, the "WordPress of AI Agents." Spora is a highly portable, zero-configuration agent orchestration tool built in modern PHP 8.1+ and designed to run on any standard web host (cPanel/FTP).

## 1. Project Context & Paradigms
- **The "Digital Employee"**: Spora operates as a single "My Assistant" for the user. While the DB structure should use `agent_id` for future scale, V1 is a single-agent UX.
- **Zero-Config Database**: Spora uses SQLite by default to remove "Create Database" friction. **However, it fully supports standard MySQL/MariaDB.** The configuration (`config.php` or `.env`) must gracefully fallback to MySQL if credentials are provided.
- **The State Machine (Human-in-the-Loop)**: The most critical architectural feature. Refer to `ARCHITECTURE.md` in this directory. 
  - `InputToolInterface` = Safe, read-only tools. Agent runs instantly.
  - `OutputToolInterface` = Unsafe, write tools. Agent execution strictly pauses, saves state to DB as `PENDING_APPROVAL`, and waits for the Human to approve via the UI before resuming via a Queue message.
- **Plugin & Tools Ecosystem**: Tools are standard PHP classes that use **PHP 8 Attributes** to define metadata (Name, Description, Settings) for the LLM and the UI. "Recipes" (agentic workflows) are defined via JSON/YAML files. Keep this Attribute-first design in mind when scaffolding the Core.

## 2. Technical Stack
**Backend (PHP 8.1+)**
- `symfony/http-foundation` (Request/Response & JSON REST support)
- `symfony/messenger` (Custom Orchestrator Loop & Queue. Supports daemon workers OR synchronous web-request execution).
- `nikic/fast-route` (Attribute-based Routing using `php-di`).
- `php-di/php-di` (Dependency Injection Container for the micro-kernel).
- `illuminate/database` (Laravel Eloquent ORM - perfect zero-config DB abstraction for SQLite and MySQL/MariaDB).
- `delight-im/auth` (Standalone, headless User Authentication logic).
- `pestphp/pest` (Testing).

**Frontend (Vue 3)**
- Vue 3 (Composition API)
- Vite (Used *locally only* to output to `../public/dist`. Prod host needs no Node.js. Dev mode should use API proxying to the PHP backend).
- Tailwind CSS
- `shadcn-vue` (Premium UI component library)

## 3. Directory Structure
Please enforce this exact structure. The base PHP namespace is `Spora\` mapping to the `app/` directory.

```text
/
├── .htaccess          # Forwards all HTTP traffic to public/index.php
├── app/
│   ├── Core/          # Kernel, DI Container, Router
│   ├── Auth/          # delight-im/auth wrapper
│   ├── Agents/        # Agent Orchestrator and Custom Loop
│   ├── Tools/         # InputToolInterface, OutputToolInterface & Annotations/Attributes
│   ├── Drivers/       # OpenAI / Anthropic Integrations
│   ├── Http/          # REST API Controllers (JSON headers)
│   └── Models/        # Eloquent Models (User, Agent, Task, ToolCall)
├── frontend/          # Vue + shadcn-vue source code
├── plugins/           # Custom user-added Tools
├── recipes/           # Agentic Workflows (JSON/YAML)
├── storage/           # SQLite DB (.sqlite), Logs, and App Secrets
├── public/
│   ├── dist/          # COMPILED Vue Assets (JS/CSS)
│   └── index.php      # Main PHP Entry Point
└── config.php         # Zero-config environment arrays
```

## 4. Phase 1 — Foundation ✅ COMPLETE

All tasks verified. 32 Pest tests passing, 0 failures.

- [x] **Task 1: Project Initialization**
  - `composer.json` configured with PHP 8.2+, all dependencies at latest stable.
  - `pestphp/pest ^3.8` (PHPUnit 11), `php-cs-fixer` configured in `.php-cs-fixer.php`.
  - Full folder structure in place.

- [x] **Task 2: Core Micro-Kernel**
  - `config.php`, `Kernel.php`, `Router.php`, `public/index.php` all implemented.
  - `app/Core/routes.php` maps all 21 API routes per `API_SPEC.md` (`/api/v1/` prefix).
  - PHP-DI container wired in `app/Core/container.php`.

- [x] **Task 3: Security & Database Scaffold**
  - `SecurityManager` uses libsodium secretbox; key detection by byte-length (not `/` check).
  - `Database.php` runs all 8 migrations idempotently via `Schema::hasTable()` guards.
  - All models correct: `auto_approve` raw 0/1/null, `pending_state` MEDIUMTEXT, `task_history` append-only.
  - `AgentState` value object: JSON serialize/deserialize roundtrip tested.

- [ ] **Task 4: Frontend Scaffold** *(see Phase 3 below)*

---

## 5. Phase 2 — Application Layer ✅ COMPLETE

### Layer 1 — Auth ✅ COMPLETE

- [x] `AuthService`, `AuthController`, auth middleware
- [x] Tests (`tests/Unit/AuthTest.php`) — 12 tests passing

### Layer 2 — ToolConfigService ✅ COMPLETE

- [x] `ToolConfigService` with encrypt/decrypt, `getGlobalSettings`, `putGlobalSettings`
- [x] Tests (`tests/Unit/ToolConfigServiceTest.php`) — 9 tests passing

### Layer 3 — Agent + Tool endpoints ✅ COMPLETE

- [x] `AgentController` — all 8 methods implemented
- [x] `ToolController` — all 3 methods implemented
- [x] Tests — 24 tests passing

### Layer 4 — Orchestrator ✅ COMPLETE

- [x] Full state machine: `start()`, `tick()`, all branches (text, InputTool, OutputTool auto/manual approval)
- [x] Symfony Messenger wiring, `TickMessage`, `TickHandler`
- [x] `TaskController` — all 5 methods implemented
- [x] `OrchestratorProxy` — breaks circular DI, lazy delegate pattern
- [x] Tests — 24 tests passing

### Layer 5 — Recipes + Plugins ✅ COMPLETE

- [x] `RecipeScanner` — scans `recipes/` + plugin `recipePaths()` for `.json`/`.yaml`/`.yml` files
- [x] `PluginLoader` — discovers `plugins/*/Plugin.php` via token parsing, boots each, registers autoload
- [x] `RecipeController::index` — auth-gated, returns merged recipe list
- [x] Tests — `RecipeScannerTest`, `PluginLoaderTest`, `RecipeControllerTest` — all passing
- [x] `symfony/yaml ^8.0` added as dependency
- [x] Coverage at 89.7%, PHPStan level 5 clean

**Total: 147 tests, 0 failures.**

---

## 6. Phase 3 — Infrastructure Hardening + Drivers

Implement in order. Each layer below is a dependency of the next.

---

### Layer 6 — WordPress-Style Schema Installer *(next up)*

**Goal:** Replace the flat `hasTable()` guard approach with a versioned, component-aware schema installer that supports safe upgrades for both Core and Plugins — no manual SQL, no downtime.

**Design:**

- `schema_versions` table — one row per component (`core`, or plugin name e.g. `my_plugin`):
  ```
  component   VARCHAR(100) PK
  version     UNSIGNED INT NOT NULL
  updated_at  TIMESTAMP
  ```
- `DatabaseSchemaInstaller` is the single entry point. The Kernel calls it on every request (fast no-op if versions match, like WordPress).
- Each **component** (Core or Plugin) provides:
  - `schemaVersion(): int` — the current required integer version (increment on any schema change)
  - `schemaTables(): array<string, callable(Blueprint): void>` — full desired table definitions, keyed by table name
  - `schemaUpgrades(): array<int, callable(Schema): void>` — version-keyed upgrade callbacks (e.g. `[2 => fn($s) => $s->table('agents', fn($t) => $t->string('new_col'))]`)

**Installer logic per component (idempotent):**
1. Read `schema_versions` row for component (0 if missing).
2. Compare to `schemaVersion()`. If equal → skip.
3. For each table in `schemaTables()`:
   - If table missing → **create** it.
   - If table exists → **compare columns**: for each column in definition not present in actual schema → **add** it. (No column removal — safe for production data.)
4. Run all `schemaUpgrades()` callbacks for versions `> current AND <= target` in order.
5. Upsert `schema_versions` row with new version.

**Plugin integration:**
- `PluginInterface` gains two new optional methods with default implementations: `schemaVersion(): int` (returns 0) and `schemaDefinition(): ?SchemaDefinition` (returns null).
- `PluginLoader` exposes `pluginSchemaDefinitions(): array` — `PluginLoader` is injected into `DatabaseSchemaInstaller`.
- On boot, `DatabaseSchemaInstaller::install()` processes Core first, then each plugin.

**Files to create/modify:**
- `app/Core/DatabaseSchemaInstaller.php` — full rewrite
- `app/Core/SchemaDefinition.php` — value object wrapping version + tables + upgrades
- `app/Plugins/PluginInterface.php` — add `schemaVersion()` and `schemaDefinition()` with default implementations
- `app/Core/Database.php` — call `DatabaseSchemaInstaller::install()` after connection setup
- `app/Core/container.php` — wire `DatabaseSchemaInstaller` with `PluginLoader` injection
- **Tests:** `tests/Unit/DatabaseSchemaInstallerTest.php` — fresh DB install, upgrade path (add column), plugin schema registration, idempotency

---

### Layer 7 — LLM Drivers

**Goal:** Implement `LLMDriverInterface` for the two most important providers. The Orchestrator already calls `LLMDriverInterface::complete()` — wiring these unlocks end-to-end task execution.

**Interface contract** (already defined, do not change):
```php
interface LLMDriverInterface {
    public function complete(array $messages, array $tools): LLMResponse;
}
// LLMResponse: { content: ?string, toolCalls: ToolCallRequest[] }
// ToolCallRequest: { id: string, name: string, arguments: array }
```

#### Driver A — `OpenAICompatibleDriver` *(highest priority)*

- **File:** `app/Drivers/OpenAICompatibleDriver.php`
- **Provider name:** `openai_compatible` (matches `agents.llm_provider` default)
- Reads `base_url`, `api_key`, `model` from `ToolConfigService` (global settings for tool class `OpenAICompatibleDriver::class`) **OR** from `Agent` row fields as fallback.
- Calls `POST {base_url}/chat/completions` via `symfony/http-client` (add to `composer.json`).
- Request format: standard OpenAI chat completions with `tools` array.
- Response parsing: handle `finish_reason: tool_calls` vs text.
- Works out of the box for: OpenAI API, Azure OpenAI (with base_url override), Ollama, LM Studio, any OpenAI-compatible endpoint.

#### Driver B — `AnthropicDriver`

- **File:** `app/Drivers/AnthropicDriver.php`
- **Provider name:** `anthropic`
- Calls `POST https://api.anthropic.com/v1/messages` with `anthropic-version: 2023-06-01` header.
- Request format: Anthropic Messages API — `system` prompt separate, `tools` array uses Anthropic schema.
- Response parsing: `stop_reason: tool_use` → extract `tool_use` content blocks; `stop_reason: end_turn` → extract `text` blocks.
- Reads `api_key` and `model` from `ToolConfigService`.

**Shared infrastructure:**
- `app/Drivers/DriverFactory.php` — resolves provider name → driver class. Registered drivers from `PluginLoader::drivers()` are merged in. Replaces the current unbound `LLMDriverInterface` binding in `container.php`.
- `app/Drivers/LLMResponse.php` + `app/Drivers/ToolCallRequest.php` — value objects (if not already defined).

**Files to create/modify:**
- `app/Drivers/OpenAICompatibleDriver.php`
- `app/Drivers/AnthropicDriver.php`
- `app/Drivers/DriverFactory.php`
- `app/Core/container.php` — bind `LLMDriverInterface` via `DriverFactory`, using `Agent` row to pick provider
- `composer.json` — add `symfony/http-client ^7.0`
- **Tests:** `tests/Unit/OpenAICompatibleDriverTest.php`, `tests/Unit/AnthropicDriverTest.php` — use mock HTTP responses (no real API calls); test tool call parsing, text response parsing, error handling.

---

## 7. Phase 4 — Frontend

**Task 4: Frontend Scaffold**

- [ ] `frontend/` — Vue 3 + Vite + TypeScript + Tailwind CSS + shadcn-vue
- [ ] `vite.config.ts` — build output to `../public/dist`, dev proxy `/api/` → PHP server (`localhost:8080` or configurable)
- [ ] Global API client (`frontend/src/api/client.ts`) — thin fetch wrapper, reads base URL from `import.meta.env.VITE_API_URL`, attaches session cookie automatically
- [ ] Auth store (Pinia) — `useAuthStore`: `login()`, `logout()`, `me()`, persists user state
- [ ] Pages: Login, Register (redirect to dashboard on success)
- [ ] Dashboard: task list (`GET /api/v1/tasks`), task detail modal with approve/reject buttons for `PENDING_APPROVAL` tasks
- [ ] Agent Settings page: agent name/description/model form (`PATCH /api/v1/agent`), tool enable/disable toggle, per-tool settings form driven by `#[ToolSetting]` attribute schema
- [ ] Recipe picker: list from `GET /api/v1/recipes`, select sets `agent.recipe_id`
- [ ] Composer (start task): textarea + submit → `POST /api/v1/tasks`

**Build integration:**
- `public/index.php` serves `public/dist/index.html` for non-API routes (SPA fallback)
- `composer.json` scripts: `"frontend:dev": "cd frontend && npm run dev"`, `"frontend:build": "cd frontend && npm run build"`

---

## 8. Appendix: Web Search API Research

For the `SearchWebTool`, Spora requires APIs optimized for LLMs (providing clean, parsed content rather than raw HTML links).

### 1. Built Specifically for AI Agents (Highly Recommended)
*   **Tavily (tavily.com)**: Designed from the ground up for AI agents. Visits sites, extracts relevant content, strips noise, and returns clean JSON context.
    *   *Best for*: Agents doing deep research.
*   **Exa (exa.ai, formerly Metaphor)**: Uses "neural search" (meaning-based rather than keyword matching). Returns clean, parsed HTML/text for the LLM.

### 2. Independent & Cost-Effective
*   **Brave Search API**: Excellent built-in privacy, massive independent index. Very fast, returns clean data, and has a generous free tier.
    *   *Best for*: Passing concise search snippets to the LLM to decide on further actions.

### 3. Enterprise Heavyweights
*   **Bing Web Search API**: The backbone of most major AI search features today. Reliable and comprehensive, but can be pricey at scale.
*   **SerpApi / Serper.dev**: Scraping APIs that return literal Google Search outputs, structured for AI workflows.

**Recommendation for Spora V1**: Default to **Tavily** for an all-in-one research tool, or **Brave Search** if relying on a secondary `ReadUrlTool` to dive deeper into extracted links. Long term: Support all major APIs.
