# Frontend Architecture (V1)

**Stack:** Vue 3 + Vite + TypeScript + Tailwind CSS + radix-vue + lucide-vue-next + Pinia.
**Build:** `vite.config.ts` → `../public/dist` (outDir in `vite.config.ts:15`). `public/index.php` serves `dist/index.html` as SPA fallback.
**Dev:** `composer frontend:dev` / `composer frontend:build`. Unit tests: `composer frontend:test` (`npm test` — Vitest + Vue Test Utils + Happy DOM). E2E: not yet wired (no `frontend/tests/e2e/` directory).

---

## Pages & Routes

Routes are defined in `frontend/src/router/index.ts`.

| Path | Component | Purpose |
|---|---|---|
| `/login` | `LoginPage.vue` | Auth — login form |
| `/register` | `RegisterPage.vue` | Auth — register form (gated by `isRegistrationEnabled()`) |
| `/forgot-password` | `ForgotPasswordPage.vue` | Request password reset email |
| `/auth/verify/:selector` | `VerifyEmailPage.vue` | Email verification link |
| `/auth/reset-password/:selector` | `ResetPasswordPage.vue` | Password reset form |
| `/` | `DashboardPage.vue` | Agent contact list (WhatsApp-style) |
| `/account` | `AccountPage.vue` | User account (email, password, name) |
| `/agents/:id` | `AgentPage.vue` | Agent detail — chat + config, follow-up input |
| `/agents/:id/settings` | `AgentSettingsPage.vue` | Agent settings — identity, LLM, tools, danger zone |
| `/agents/:id/scheduled-runs` | `ScheduledRunsPage.vue` | Manage scheduled runs for an agent |
| `/profile` | `ProfileSettingsPage.vue` | User profile (locations, personal info) |
| `/settings` | `GlobalSettingsLayout.vue` (redirects to `/settings/overview`) | Global settings shell |
| `/settings/overview` | `SettingsOverviewPage.vue` | Settings overview |
| `/settings/tools` | `SettingsToolsPage.vue` | Per-user tool settings |
| `/settings/llm` | `SettingsLLMPage.vue` | LLM Driver Configurations — create, edit, delete, set default |
| `/settings/admin/users` | `admin/UsersPage.vue` | Admin — user management |
| `/settings/admin/drivers` | `admin/DriversSettingsPage.vue` | Admin — global LLM driver defaults |
| `/settings/admin/tools` | `admin/ToolsSettingsPage.vue` | Admin — global tool defaults |
| `/settings/admin/mail-templates` | `admin/MailTemplatesPage.vue` | Admin — mail template management |
| `/apps/memories` | `apps/memories/pages/GlobalMemoriesPage.vue` | Global memory store |
| `/apps/memories/agents/:id?` | `apps/memories/pages/AgentMemoriesPage.vue` | Per-agent memory list |
| `/apps/memories/agents/:id/:memoryId` | `apps/memories/pages/AgentMemoriesPage.vue` | Memory edit view |
| `/tasks/:id` | `TaskChatPage.vue` | Full-screen task chat (polling detail view) + approval bar |

Note: `pages/LLMConfigsPage.vue` and `pages/admin/GlobalSettingsPage.vue` exist but the first is a legacy redirect to `settings-llm`, and the second is an admin tool defaults page (not the global settings layout).

---

## State (Pinia stores)

All stores live in `frontend/src/stores/`.

| Store id | File | Responsibility |
|---|---|---|
| `auth` | `auth.ts` | User session, login/logout/register, CSRF token, init deduplication |
| `theme` | `theme.ts` | Dark mode toggle, `localStorage` persistence, `dark` class on `<html>` |
| `agent` | `agent.ts` | Multi-agent CRUD, tool enable/disable, operation overrides, composer drafts (sessionStorage) |
| `llmConfigs` | `llmConfigs.ts` | LLM Driver Configurations CRUD, set-default, global/personal split |
| `llmPreferences` | `llmPreferencesStore.ts` | User's preferred LLM config (cross-store pointer) |
| `tasks` | `tasks.ts` | Task list, task detail, polling (3s/10s list, 2s detail, 30s dashboard), approve/reject/retry/continue, SSE `applyTaskUpdate` / `applySseEventToTasks` |
| `notifications` | `notifications.ts` | Notification list, unread count, mark-read, SSE `prependFromSSE`, 60s polling |
| `promptTemplates` | `promptTemplates.ts` | Per-agent prompt template CRUD |
| `scheduledRuns` | `scheduledRuns.ts` | Per-agent scheduled run CRUD + manual trigger |
| `globalSettings` | `globalSettings.ts` | Admin — global LLM driver and tool defaults |
| `adminSettings` | `adminSettings.ts` | Admin — active sidebar section |
| `mailConfig` | `mailConfig.ts` | Admin — mail config (SMTP) |
| `mailTemplates` | `mailTemplates.ts` | Admin — mail template CRUD |
| `users` | `users.ts` | Admin — user management |

---

## Navigation

```
Dashboard (agent list)
  └─ Agent card tap → AgentPage (chat + config)
                        ├─ Settings button → /agents/:id/settings (full settings page)
                        └─ Scheduled Runs → /agents/:id/scheduled-runs

Navbar (global only — GlobalNavbar.vue)
  ├─ App logo (← Dashboard)
  ├─ Settings link → /settings
  ├─ Notification bell (opens NotificationCenter slide-in) + unread badge
  ├─ Dark mode toggle
  ├─ Apps dropdown (loaded from GET /api/v1/apps)
  └─ User menu (My Account → /account, Profile → /profile, sign out)
```

---

## API Design

### Agents
```
GET    /api/v1/agents                                    → index
POST   /api/v1/agents                                    → store
GET    /api/v1/agents/{id}                               → show
PATCH  /api/v1/agents/{id}                               → update
DELETE /api/v1/agents/{id}                               → destroy
POST   /api/v1/agents/{id}/tools/{toolName}/enable       → enableTool
DELETE /api/v1/agents/{id}/tools/{toolName}/enable       → disableTool
GET    /api/v1/agents/{id}/tools/status                  → getToolsStatus
GET    /api/v1/agents/{id}/tools/{toolName}/status       → getToolStatus
GET    /api/v1/agents/{id}/tools/operations              → getToolsOperations
GET    /api/v1/agents/{id}/tools/{toolName}/operations/{operation}   → getOperationOverride
PATCH  /api/v1/agents/{id}/tools/{toolName}/operations/{operation}   → patchOperationOverride
GET    /api/v1/agents/{id}/tools/{toolName}/override     → getOverride (masked)
PUT    /api/v1/agents/{id}/tools/{toolName}/override     → putOverride
DELETE /api/v1/agents/{id}/tools/{toolName}/override     → deleteOverride
```
The `{toolName}` placeholder is the **snake_case** value declared on `#[Tool(name:)]` (e.g. `serper_search`, `tavily_search`, `read_url`, `email`, `llm_configuration`), URL-encoded by the client. The server-side controller resolves the name to a class via `ToolConfigService::resolveToolClass()` (`app/Services/ToolConfigService.php:504-507`) — it is **not** the FQCN. See `docs/04_api.md` for the canonical definition.

### LLM Driver Configurations
```
GET    /api/v1/llm-drivers                              → drivers (auth-required; public schema discovery for the registry)
GET    /api/v1/llm-configs                              → index (user-scoped)
POST   /api/v1/llm-configs                              → store
GET    /api/v1/llm-configs/global                       → globalConfigs (admin-only)
GET    /api/v1/llm-configs/{id}                         → show
PUT    /api/v1/llm-configs/{id}                         → update
DELETE /api/v1/llm-configs/{id}                         → destroy
POST   /api/v1/llm-configs/{id}/set-default             → setDefault
```

### Tasks
```
GET    /api/v1/tasks                       → index (optional ?agent_id, ?page, ?since=ISO)
POST   /api/v1/tasks                       → store ({ agent_id, prompt, parent_task_id? })
GET    /api/v1/tasks/{id}                  → show (optional ?since_sequence=X)
POST   /api/v1/tasks/{id}/approve          → approve ({ approvals: [{provider_call_id, arguments}] })
POST   /api/v1/tasks/{id}/reject           → reject ({ reason })
POST   /api/v1/tasks/{id}/retry            → retry
POST   /api/v1/tasks/{id}/continue         → continue ({ prompt, additional_steps? })
DELETE /api/v1/tasks/{id}/retry-chain      → cancelRetryChain
DELETE /api/v1/tasks/{id}                  → destroy
```

### Notifications
```
GET    /api/v1/notifications                → index (paginated, ?unread_only=true)
POST   /api/v1/notifications/{id}/read      → markRead
POST   /api/v1/notifications/read-all       → markAllRead
DELETE /api/v1/notifications/{id}          → destroy
DELETE /api/v1/notifications                → destroyAll
```

### Memories
```
GET    /api/v1/memories                            → index
POST   /api/v1/memories                            → store
PATCH  /api/v1/memories/reorder                    → reorder
GET    /api/v1/memories/{id}                       → show
PUT    /api/v1/memories/{id}                       → update
DELETE /api/v1/memories/{id}                       → destroy
GET    /api/v1/agents/{id}/memories                → agent memories index
POST   /api/v1/agents/{id}/memories                → agent memories store
PATCH  /api/v1/agents/{id}/memories/reorder        → reorder agent memories
GET    /api/v1/agents/{id}/memories/{memoryId}     → show
PUT    /api/v1/agents/{id}/memories/{memoryId}     → update
DELETE /api/v1/agents/{id}/memories/{memoryId}     → destroy
```

### User Preferences
```
GET   /api/v1/user-preferences/llm      → show user's preferred LLM config
PUT   /api/v1/user-preferences/llm      → update ({ config_id })
```

### SSE / Realtime
```
GET    /api/v1/sse/status                  → { active, hubUrl? } — used to feature-detect Mercure
GET    /api/v1/sse/auth                    → { hubUrl, token } — Mercure subscriber JWT (1h)
```
The JWT (`SseController::generateSubscriberJwt`) is scoped to topics `user/{userId}/tasks` and `user/{userId}/notifications`.

### Prompt Templates
```
GET    /api/v1/agents/{id}/templates        → index
POST   /api/v1/agents/{id}/templates        → store
GET    /api/v1/agents/{id}/templates/{tid} → show
PUT    /api/v1/agents/{id}/templates/{tid} → update
DELETE /api/v1/agents/{id}/templates/{tid} → delete
```

### Scheduled Runs
```
GET    /api/v1/agents/{id}/scheduled-runs   → index
POST   /api/v1/agents/{id}/scheduled-runs   → store ({ template_id?, raw_prompt, cron_expression?, run_at?, timezone, max_steps_override? })
GET    /api/v1/agents/{id}/scheduled-runs/{rid} → show
PUT    /api/v1/agents/{id}/scheduled-runs/{rid} → update
DELETE /api/v1/agents/{id}/scheduled-runs/{rid} → delete
POST   /api/v1/agents/{id}/scheduled-runs/{rid}/trigger → trigger now
```

---

## Theme

- Default: **light mode** (system preference NOT applied by default).
- `stores/theme.ts` persists to `localStorage.theme = 'dark' | 'light'`.
- Tailwind `darkMode: 'class'` — `dark` class toggled on `<html>`.
- Toggle button in navbar (sun/moon `Icon` glyphs from `components/ui/Icon.vue`).

---

## Tests

Located in `frontend/tests/`. Run with `npm test` (or `composer frontend:test`).

- `stores/theme.spec.ts` — dark mode toggle, localStorage persistence, apply()
- `stores/auth.spec.ts` — login, logout, register, init deduplication
- `stores/agent.spec.ts` — CRUD, tools, LLM config
- `stores/llmConfigs.spec.ts` — CRUD, set-default, API integration
- `stores/tasks.spec.ts` — fetch, create, approve/reject, pendingToolCalls, isTerminal
- `stores/globalSettings.spec.ts` — driver + tool defaults
- `stores/mailConfig.spec.ts`, `stores/mailTemplates.spec.ts`
- `stores/memories.spec.ts`
- `stores/users.spec.ts`
- `components/TaskStatusBadge.spec.ts` — all 6 status variants (`PENDING`, `RUNNING`, `COMPLETED`, `FAILED`, `PENDING_APPROVAL`, `CANCELLED`)
- `components/AlertBanner.spec.ts`, `components/ListItemButton.spec.ts`, `components/SettingsSidebar.spec.ts`
- `components/agent/*` — tool-config modals and tool arguments editor
- `composables/useRealtime.spec.ts` — Mercure status check + auth + polling fallback
- `composables/useToast.spec.ts`, `composables/useToolSettings.spec.ts`, `composables/useToolArgumentFormatter.spec.ts`
- `pages/ProfileSettingsPage.spec.ts`, `pages/ResetPasswordPage.spec.ts`, `pages/SettingsToolsPage.spec.ts`

There is no `frontend/tests/e2e/` directory and no Playwright dependency in `frontend/package.json` — E2E coverage is not currently wired up.

---

## Real-Time

**Primary (polling):** REST polling managed by `stores/tasks.ts` and `stores/notifications.ts`:
- Task list: every 3s while any task is non-terminal, 10s when all terminal
- Task detail: every 2s while active (`since_sequence` delta)
- Dashboard (AgentPage) task list: every 30s with `?since=` cursor
- Notifications: every 60s

**Enhanced (SSE via Mercure):** `useRealtime()` composable (`composables/useRealtime.ts`):
1. First hits `GET /api/v1/sse/status` — falls back to polling if `active === false` or no `hubUrl`.
2. Otherwise calls `GET /api/v1/sse/auth` to fetch `{ hubUrl, token }` (subscriber JWT).
3. Opens a singleton `EventSource` to the Mercure hub subscribing to `user/{userId}/tasks` and `user/{userId}/notifications`. Connection is shared across route changes (no reconnect churn on navigation).
4. Falls back to polling on `onerror`, network failure, or 404 from `/sse/auth`.

**Components:**
- `NotificationCenter.vue` — slide-in notification panel triggered from the navbar bell icon (`Teleport`-ed to `body`)
- `GlobalNavbar.vue` — bell icon with unread badge count, wires `useRealtime()` on mount
- `useRealtime()` composable — auto-connects on mount, leaves the singleton connection alive on unmount (intentional, for cross-route persistence)

## Composer & Templates

The `AgentPage.vue` composer (`components/ComposerInput.vue`) supports:
- **Prompt templates** — select from saved templates (loaded via `usePromptTemplatesStore`), fill `{{var}}` placeholders, submit
- **Save as template** — `components/PromptTemplateDialog.vue` to save current prompt as a reusable template
- **Follow-up questions** — after a task completes, a follow-up input bar appears above the composer to continue the conversation (calls `POST /api/v1/tasks/{id}/continue`)
- **Schedule** — `components/shared/SharedScheduleEditor.vue` modal for one-shot or cron scheduled runs

## E2E Tests

There are no E2E tests wired up in the current frontend. The `frontend/tests/e2e/` directory does not exist and `frontend/package.json` has no Playwright dependency — the previous "Playwright + Docker Compose" plan in this section is aspirational only.
