# Spora — Execution Plan

**What is Spora?** The "WordPress of AI Agents" — a portable, zero-config agent orchestration tool in PHP 8.2+. Runs on any shared host (cPanel/FTP).

**Reference docs:** `docs/00_index.md` · `docs/01_architecture.md` · `docs/02_schema.md` · `docs/03_interfaces.md` · `docs/04_api.md` · `docs/05_drivers.md` · `docs/06_tools.md` · `docs/07_plugins.md` · `docs/08_logging.md` · `docs/09_frontend.md`

**Stack:** `symfony/http-foundation`, `symfony/messenger`, `nikic/fast-route`, `php-di/php-di`, `illuminate/database` (Eloquent), `delight-im/auth`, `pestphp/pest`, Vue 3 + Vite + Tailwind + shadcn-vue (frontend).

**Total: 254 backend tests, 622 assertions. 42 frontend tests. PHPStan level 5 clean.**

---

## Completed ✅

| Phase | What | Tests |
|---|---|---|
| Foundation | Kernel, Router, DI container, SecurityManager, Database scaffold (8 tables), config/env | 32 |
| Auth (Layer 1) | `AuthService`, `AuthController`, session guard | 12 |
| ToolConfigService (Layer 2) | Encrypt/decrypt password fields, global + per-agent settings | 9 |
| Agent + Tool endpoints (Layer 3) | `AgentController` (CRUD), `ToolController` | 24 |
| Orchestrator (Layer 4) | Full state machine (`start/tick/resume/reject`), `OrchestratorProxy`, Messenger wiring, `TaskController` | 24 |
| Recipes + Plugins (Layer 5) | `RecipeScanner`, `PluginLoader` | 24 |
| Schema Installer (Layer 6) | `DatabaseSchemaInstaller` + plugin migrations | 17 |
| LLM Drivers (Layer 7) | `OpenAICompatibleDriver`, `AnthropicCompatibleDriver`, `DriverFactory` | 17 |
| PSR-3 Logging (Layer 8) | Monolog, PII-safe argument policy | 3 |
| Core Base Toolset (Layer 9) | 9 built-in tools (search, email, calendar, memory, etc.) | 35 |
| API Polling + Seeders | `?since_sequence=X` polling, `bin/spora db:seed` | 7 |
| Frontend scaffold | Vue 3 + Vite + Tailwind + shadcn-vue + Pinia | — |
| Frontend auth | Login, Register, route guards | — |
| Frontend task chat | WhatsApp-style bubbles, approve/reject panel, 2s polling | — |
| Frontend multi-agent + UX | Dashboard (WhatsApp contact list), AgentPage (sidebar + inline composer), GlobalNavbar, light-default theme | 44 |

---

## Next — UX Fine-tuning ← ACTIVE

### Layout

**Global navbar** (all pages):
- App name/logo (links to Dashboard)
- Dark mode toggle (sun/moon SVG, **light default**)
- User email + sign out

**Dashboard (`/`)** — WhatsApp-style agent contact list:
- Agent avatar (initial circle) + name + last task preview + timestamp
- Tap agent → navigate to `/agents/:id`
- "+ New Agent" FAB or header button
- Empty state with prompt to create first agent

**Agent Page (`/agents/:id`)** — Desktop: persistent left sidebar listing other agents. Main area:
1. Agent identity header (name, description, inline edit)
2. System prompt (collapsible)
3. Inline chat composer (no modal) → submit → navigate to `/tasks/:id`
4. Recent task history (compact list, tap → `/tasks/:id`)
5. Enabled tools list + "Add tools" expand

**Agent Settings (`/agents/:id/settings`)** — Full-page form:
- Identity: name, description, system prompt
- LLM: provider dropdown, model, base URL
- API Keys: OpenAI key, Anthropic key (masked inputs)
- Tools: enable/disable + auto-approve per tool
- Danger zone: delete agent

**Task Chat (`/tasks/:id`)** — Full-screen chat view (existing, preserve).

### Theme default
Light mode by default. `theme.ts` should init to `false` for `isDark`, not system preference.

---

## Backlog

- **Agent-to-Agent Handovers** — message-driven triggers so one agent can prompt another
- **Tool Call Abort/Retry** — abort vs retry distinction, `step_count` reset on retry
- **Multimodal / Image Inputs** — drag-and-drop attachment in composer, Base64 data URI push
- **MCP Server Integration** — plugin-level MCP transport (stdio/HTTP/SSE)
- **User Management** — multi-user, roles, per-user agent isolation
- **Installer** — WordPress-style `install.php` web setup
- **Distribution** — Shared hosting ZIP, FrankenPHP Docker image, one-click deploy manifests
- **Plugin Marketplace** — discovery, install, update, signature verification
- **Mercure/SSE** — optional real-time upgrade (FrankenPHP bundles Mercure natively)
- **Web Push Notifications** — OS notifications on `PENDING_APPROVAL`
