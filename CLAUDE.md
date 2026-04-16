# Spora

**What:** "WordPress of AI Agents" — portable, zero-config agent orchestration in PHP 8.2+. Runs on any shared host (cPanel/FTP).

**Stack:** `symfony/http-foundation`, `nikic/fast-route`, `php-di/php-di`, `illuminate/database` (Eloquent), `delight-im/auth`, `pestphp/pest`, Vue 3 + Vite + Tailwind + shadcn-vue.

---

## Rules

- `declare(strict_types=1)` on every PHP file
- `final` on all classes unless inheritance is required
- No DB calls in constructors — boot explicitly via `Database::bootDatabaseConnectionOnly()`
- `bin/spora` is the single CLI entry point (`spora:install`, `db:seed`, `worker:run`, `worker:run --scheduled`)
- **Storage:** `storage/` — `database.sqlite` (app db), `spora.log` / `php.log` (logs)
- **Tests:** Backend Pest (`composer test`), Frontend Vitest (`composer frontend:test`), E2E Playwright (`composer frontend:test:e2e`)
- Worker async mode is controlled by `SPORA_SYNC_MODE` (`true` = inline/dev, `false` = queued/worker)
- `Database` is `final` — cannot be Mockery-mocked; pass a real instance instead
- No mocks for integration tests that already boot the DB via `beforeEach`
- Don't add error handling, fallbacks, or abstractions beyond what the task requires

---

## Completed

| Phase | What |
|---|---|
| Foundation | Kernel, Router, DI container, SecurityManager, Database scaffold |
| Auth | `AuthService`, `AuthController`, session guard |
| ToolConfigService | Encrypt/decrypt password fields, global + per-agent settings |
| Agent + Tool endpoints | `AgentController` (CRUD), `ToolController` |
| Orchestrator | Full state machine, `TaskController` |
| Recipes + Plugins | `RecipeScanner`, `PluginLoader` |
| Schema Installer | `DatabaseSchemaInstaller` + plugin migrations |
| LLM Drivers | `OpenAICompatibleDriver`, `AnthropicCompatibleDriver`, `DriverFactory` |
| LLM Driver Config | `LLMDriverConfiguration` model, `LLMConfigController` REST API |
| PSR-3 Logging | Monolog, PII-safe argument policy |
| Core Toolset | 9 built-in tools (search, email, calendar, memory) |
| Security Hardening | Multi-tenancy isolation, auth rate limiting, no path leak in prod logs |
| Frontend | Vue 3 scaffold, auth, task chat, multi-agent dashboard, settings UI |
| Async Agent Loop | `SPORA_SYNC_MODE`, `QUEUED` status, `WorkerRunCommand`, Mercure SSE |
| Prompt Templates | `AgentPromptTemplate` model, `PromptTemplateController` CRUD |
| Scheduled Runs | `ScheduledRun` model, `ScheduledRunController` CRUD, `--scheduled` worker flag, cron scheduling |
| Follow-Up Questions | `parent_task_id` lineage, `allow_followup` agent setting, deep-copy history |
| Tool Approval UI | Sticky approval bar in `TaskChatPage.vue` with per-tool approve/reject |
| Notification Centre | `Notification` model, `NotificationService`, `NotificationController`, real-time via Mercure + SSE |
| Docker / FrankenPHP | `Dockerfile`, `docker-compose.yml`, Playwright E2E test suite |
| SSE Realtime | `useRealtime` composable, `SseController`, `GET /api/v1/sse/auth` endpoint |
| Tests | Comprehensive Pest test suites for NotificationService/Controller, PromptTemplateController, ScheduledRunController, SseController |

---

## Backlog

- Agent-to-Agent Handovers
- Tool Call Abort/Retry
- Multimodal / Image Inputs
- Apps from plugins or the core with specific logic (dashboards, etc.)
- MCP Server Integration
- User Management (multi-user, roles)
- WordPress-style web installer
- Web Push Notifications on `PENDING_APPROVAL`
- Scheduled Runs frontend UI (`ScheduledRunsPage.vue`, `SharedScheduleEditor.vue`, composer redesign)
