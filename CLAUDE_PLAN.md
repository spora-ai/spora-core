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

- [ ] **Task 4: Frontend Scaffold** *(deferred — implement PHP layers first)*
  - Vue 3 + Vite + Tailwind + shadcn-vue inside `frontend/`.
  - `vite.config.ts` output to `../public/dist`, proxy API to PHP dev server.

---

## 5. Phase 2 — Application Layer (Next Steps)

**TDD REQUIREMENT:** Write tests alongside each implementation. Each layer below is a dependency of the next — implement in order.

---

### Layer 1 — Auth ✅ COMPLETE

**Goal:** A working `POST /api/v1/auth/register`, `POST /api/v1/auth/login`, `POST /api/v1/auth/logout`, `GET /api/v1/auth/me`.

- [x] **`AuthService`** (`app/Auth/AuthService.php`)
  - Wrap `delight-im/auth` (`\Delight\Auth\Auth`).
  - Inject `\Delight\Auth\Auth` via PHP-DI (wire in `container.php`).
  - Expose: `register(email, password): int`, `login(email, password): void`, `logout(): void`, `currentUserId(): ?int`.
  - Throw typed exceptions for duplicate email, wrong password, unverified account.

- [x] **`AuthController`** (`app/Http/AuthController.php`)
- [x] **Auth middleware / guard helper**
- [x] **Tests** (`tests/Unit/AuthTest.php`) — 12 tests passing

---

### Layer 2 — ToolConfigService ✅ COMPLETE

**Goal:** The only class permitted to read/write `tool_configurations.settings` and `agent_tool_overrides.settings`.

- [x] **`ToolConfigService`** (`app/Services/ToolConfigService.php`)
  - Inject `SecurityManagerInterface`.
  - `getGlobalSettings(string $toolClass): array` — load `ToolConfiguration` row, decrypt password fields, return plain array.
  - `putGlobalSettings(string $toolClass, array $settings): void` — encrypt password fields via `SecurityManager`, store JSON.
  - [x] All methods implemented and tested

- [x] **Tests** (`tests/Unit/ToolConfigServiceTest.php`) — 9 tests passing

---

### Layer 3 — Agent + Tool endpoints ✅ COMPLETE

- [x] **`AgentController`** — implement all 8 methods:
  - `show` → load `Agent` for `currentUserId()`.
  - `update` → PATCH agent fields (`name`, `description`, `llm_provider`, `llm_model`, `max_steps`).
  - `enableTool` → upsert `AgentTool` row (`POST /agent/tools/{toolClass}/enable`).
  - `patchTool` → update `auto_approve` on existing `AgentTool` row.
  - `disableTool` → delete `AgentTool` row.
  - [x] All 8 methods implemented

- [x] **`ToolController`** — all 3 methods implemented

- [x] **Tests** (`tests/Unit/AgentControllerTest.php`, `ToolControllerTest.php`) — 24 tests passing

---

### Layer 4 — Orchestrator ✅ COMPLETE

- [x] **`Orchestrator`** (`app/Agents/Orchestrator.php`) — full state machine implemented:
  - `start(agentId, userPrompt, maxSteps)`: create `Task` (status `RUNNING`), dispatch first `TickMessage`.
  - `tick(taskId)`: load Task + history → call `LLMDriverInterface::complete()` → branch:
    - Text response → mark `COMPLETED`, store `final_response`.
    - `InputTool` call → resolve + execute tool, append to `task_history`, increment `step_count`, re-dispatch `TickMessage`.
    - `OutputTool` call → resolve `auto_approve` (check `AgentTool` row, fall back to class attribute default):
      - Auto-approved → execute, append, re-dispatch.
      - Requires approval → serialize `AgentState` to `tasks.pending_state`, set status `PENDING_APPROVAL`, stop.
    - [x] All state machine paths implemented and tested

- [x] **Symfony Messenger wiring** (`container.php`) — synchronous in-process bus
- [x] **`TickMessage`** + **`TickHandler`** (`app/Agents/Messages/`, `app/Agents/Handlers/`)

- [x] **`TaskController`** — all 5 methods implemented

- [x] **Tests** (`tests/Unit/OrchestratorTest.php`, `TaskControllerTest.php`) — 24 tests passing
  - InputTool, OutputTool (approval + auto-approve + row override), max_steps, resume, reject all covered

---

### Layer 5 — Recipes + Plugins *(final layer)*

- [ ] **Recipe scanner** — load `.yaml`/`.json` from `recipes/` + plugin `recipePaths()`.
- [ ] **`PluginLoader`** — scan `plugins/`, auto-discover `PluginInterface`, boot each.
- [ ] **`RecipeController::index`** — list available recipes.
- [ ] **Tests** — scanner finds files, plugin loader discovers and boots correctly.

---

### Task 4 (deferred) — Frontend Scaffold

- [ ] Vue 3 + Vite + Tailwind + shadcn-vue inside `frontend/`.
- [ ] `vite.config.ts`: build to `../public/dist`, proxy `/api/` to PHP dev server.
- [ ] Auth pages: login, register.
- [ ] Dashboard: task list, task detail with approve/reject actions.
- [ ] Settings: agent config, tool enable/disable, tool settings form (driven by `#[ToolSetting]` schema).

**Start with Layer 1. No endpoint can be integration-tested without an authenticated session.**

## 6. Appendix: Web Search API Research

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
