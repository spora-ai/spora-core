# Spora

**What:** "WordPress of AI Agents" — portable, zero-config agent orchestration in PHP 8.2+. Runs on any shared host (cPanel/FTP).

**Stack:** `symfony/http-foundation`, `nikic/fast-route`, `php-di/php-di`, `illuminate/database` (Eloquent), `delight-im/auth`, `pestphp/pest`, Vue 3 + Vite + Tailwind + shadcn-vue.

---

## Rules

- `declare(strict_types=1)` on every PHP file
- `final` on all classes unless inheritance is required
- No DB calls in constructors — boot explicitly via `Database::bootDatabaseConnectionOnly()`
- `bin/spora` is the single CLI entry point (`spora:install`, `db:seed`, `worker:run`)
- Worker async mode is controlled by `SPORA_SYNC_MODE` (`true` = inline/dev, `false` = queued/worker)
- Tests use Pest — run with `./vendor/bin/pest`
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

---

## Backlog

- Validate Approval Functionality
- Pre-Defined Prompts (System & User)
- Timed Workflows for predefined prompts
- Option to allow Follow-Up Questions
- Notification central (including Notification channels)
- Agent-to-Agent Handovers
- Tool Call Abort/Retry
- Multimodal / Image Inputs
- Apps from plugins or the core with specific logic (dashboards, etc.)
- MCP Server Integration
- User Management (multi-user, roles)
- WordPress-style web installer
- Web Push Notifications on `PENDING_APPROVAL`
