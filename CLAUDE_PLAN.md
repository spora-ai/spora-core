# Spora — Execution Plan

**What is Spora?** The "WordPress of AI Agents" — a portable, zero-config agent orchestration tool in PHP 8.2+. Runs on any shared host (cPanel/FTP).

**Stack:** `symfony/http-foundation`, `symfony/messenger`, `nikic/fast-route`, `php-di/php-di`, `illuminate/database` (Eloquent), `delight-im/auth`, `pestphp/pest`, Vue 3 + Vite + Tailwind + shadcn-vue (frontend).

**Tests:** 260 PHP tests, 631 assertions. 56 frontend tests.

---

## Completed ✅

| Phase | What | Tests |
|---|---|---|
| Foundation | Kernel, Router, DI container, SecurityManager, Database scaffold | 32 |
| Auth (Layer 1) | `AuthService`, `AuthController`, session guard | 12 |
| ToolConfigService (Layer 2) | Encrypt/decrypt password fields, global + per-agent settings | 9 |
| Agent + Tool endpoints (Layer 3) | `AgentController` (CRUD), `ToolController` | 24 |
| Orchestrator (Layer 4) | Full state machine, `OrchestratorProxy`, `TaskController` | 24 |
| Recipes + Plugins (Layer 5) | `RecipeScanner`, `PluginLoader` | 24 |
| Schema Installer (Layer 6) | `DatabaseSchemaInstaller` + plugin migrations | 17 |
| LLM Drivers (Layer 7) | `OpenAICompatibleDriver`, `AnthropicCompatibleDriver`, `DriverFactory`, `LLMDriverConfigInterface` | 17 |
| LLM Driver Config System | `LLMDriverConfiguration` model, `LLMConfigController` REST API, multi-tenant user scoping | 14 |
| PSR-3 Logging (Layer 8) | Monolog, PII-safe argument policy | 3 |
| Core Base Toolset (Layer 9) | 9 built-in tools (search, email, calendar, memory) | 35 |
| Security Hardening | Multi-tenancy isolation on LLM configs, tool override validation (403), auth rate limiting (5/60s), JSON_THROW_ON_ERROR, no file-path leak in production logs | +13 |
| Frontend scaffold | Vue 3 + Vite + Tailwind + shadcn-vue + Pinia | — |
| Frontend auth | Login, Register, route guards | — |
| Frontend task chat | WhatsApp-style bubbles, approve/reject panel, 2s polling | — |
| Frontend multi-agent + UX | Dashboard, AgentPage, GlobalNavbar, light-default theme | 44 |
| Global Settings UI | Schema-driven ToolSettingsForm, per-agent LLM config via ToolConfigService | 12 |
| **Async Agent Loop** | `tick()` wrapped in `lockForUpdate()` transaction; `SPORA_WORKER_MODE` (`sync`|`cron`|`worker`); `QUEUED` status; `bin/worker.php` CLI; `WorkerRunCommand` as cron/daemon drain; `MercurePublisher` + `MercurePublisherInterface` for SSE | +7 |

---

## In Progress — Frontend Fixes

| Issue | Fix | Status |
|---|---|---|
| Split Settings/LLMs nav links | Unified `/settings` page with sidebar tabs: "Tools" + "LLM Drivers". Remove separate `/settings/llm` route. | ✅ Done |
| Driver pre-selected in LLM create form | `formDriverClass` starts empty (`''`); settings fields only appear after driver is selected. | ✅ Done |
| Global tool config unclear | `GlobalSettingsPage.vue` already handles global tool settings — now properly integrated in unified sidebar. | ✅ Done |
| Agent uses old `llm_provider/model/base_url` fields | `AgentSettingsPage.vue` now uses `llm_driver_config_id` dropdown of user's saved configs + "Use global default" option. Old fields and "API Keys" section removed. Type updated in `types/agent.ts` and `stores/agent.ts`. | ✅ Done |
| InputTools auto-approve off by default | `enableTool()` now sets `auto_approve = true` for `InputToolInterface` tools, seeds agent override with schema defaults if no global config exists. | ✅ Done |

---

## Completed — Frontend Architecture Refactor ✅

| Task | Detail |
|---|---|
| Nested settings routes | `/settings/overview`, `/settings/tools`, `/settings/llm` with `GlobalSettingsLayout.vue` (sidebar + `<RouterView />`). |
| Split GlobalSettingsPage | 703-line monolith replaced by `SettingsOverviewPage`, `SettingsToolsPage`, `SettingsLLMPage` (~50–80 lines each). Deleted. |
| Tools components | `src/components/settings/tools/ToolList.vue` + `ToolSettingsPanel.vue` (mirrors LLM structure). |
| LLM components | `src/components/settings/llm/{LLMConfigList,LLMConfigCreateForm,LLMConfigEditForm}.vue`. `Modal.vue` for delete confirmation. |
| Reusable UI primitives | `AlertBanner.vue` + `ListItemButton.vue` (with `ChevronRight` from lucide). |
| SettingsSidebar | Uses `router.push()` + `useRoute()` for active state; dropped `selectedSection`/`selectedConfigId` props. |

---

## Backlog

- **Agent-to-Agent Handovers** — message-driven triggers so one agent can prompt another
- **Tool Call Abort/Retry** — abort vs retry distinction, `step_count` reset on retry
- **Multimodal / Image Inputs** — drag-and-drop attachment in composer, Base64 data URI push
- **MCP Server Integration** — plugin-level MCP transport (stdio/HTTP/SSE)
- **User Management** — multi-user, roles, per-user agent isolation
- **Installer** — WordPress-style `install.php` web setup
- **Web Push Notifications** — OS notifications on `PENDING_APPROVAL`
