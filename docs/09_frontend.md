# Frontend Architecture (V1)

**Stack:** Vue 3 + Vite + TypeScript + Tailwind CSS + shadcn-vue + Pinia.
**Build:** `vite.config.ts` → `../public/dist`. `public/index.php` serves `dist/index.html` as SPA fallback.
**Dev:** `composer frontend:dev` / `composer frontend:build`. Tests: `npm test` (Vitest + Vue Test Utils + Happy DOM).

---

## Pages & Routes

| Path | Component | Purpose |
|---|---|---|
| `/login` | `LoginPage.vue` | Auth — login form |
| `/register` | `RegisterPage.vue` | Auth — register form |
| `/` | `DashboardPage.vue` | Agent contact list (WhatsApp-style) |
| `/agents/:id` | `AgentPage.vue` | Agent detail — chat + config, follow-up input |
| `/agents/:id/settings` | `AgentSettingsPage.vue` | Agent settings — identity, LLM, tools, danger zone |
| `/agents/:id/scheduled-runs` | `ScheduledRunsPage.vue` | Manage scheduled runs for an agent |
| `/settings` | `GlobalSettingsPage.vue` | Global settings — tools, LLM drivers |
| `/settings/llm` | `LLMConfigsPage.vue` | LLM Driver Configurations — create, edit, delete, set default |
| `/tasks/:id` | `TaskChatPage.vue` | Full-screen task chat (polling detail view) + approval bar |

---

## State (Pinia stores)

| Store | File | Responsibility |
|---|---|---|
| `auth` | `stores/auth.ts` | User session, login/logout/register |
| `theme` | `stores/theme.ts` | Dark mode toggle, `localStorage` persistence, `dark` class on `<html>` |
| `agent` | `stores/agent.ts` | Multi-agent CRUD, tool enable/disable |
| `llmConfigs` | `stores/llmConfigs.ts` | LLM Driver Configurations CRUD, set-default |
| `tasks` | `stores/tasks.ts` | Task list, task detail, polling, approve/reject, SSE applyTaskUpdate |
| `notifications` | `stores/notifications.ts` | Notification list, unread count, mark-read, SSE prepend |

---

## Navigation

```
Dashboard (agent list)
  └─ Agent card tap → AgentPage (chat + config)
                        ├─ Settings button → /agents/:id/settings (full settings page)
                        └─ Scheduled Runs → /agents/:id/scheduled-runs

Navbar (global only)
  ├─ App logo/name (← Dashboard)
  ├─ Notification bell (opens NotificationCenter slide-in)
  ├─ Dark mode toggle
  └─ User menu (email + sign out)
```

---

## API Design

### Agents
```
GET    /api/v1/agents                                    → index
POST   /api/v1/agents                                    → store
GET    /api/v1/agents/{id}                               → show
PATCH  /api/v1/agents/{id}                              → update (name, description, system_prompt, llm_driver_config_id, max_steps)
DELETE /api/v1/agents/{id}                               → destroy
POST   /api/v1/agents/{id}/tools/{toolClass}/enable     → enableTool
PATCH  /api/v1/agents/{id}/tools/{toolClass}            → patchTool (auto_approve)
DELETE /api/v1/agents/{id}/tools/{toolClass}/enable     → disableTool
GET    /api/v1/agents/{id}/tools/{toolClass}/override   → getOverride (masked)
PUT    /api/v1/agents/{id}/tools/{toolClass}/override   → putOverride (403 if tool not assigned)
DELETE /api/v1/agents/{id}/tools/{toolClass}/override   → deleteOverride (403 if tool not assigned)
```

### LLM Driver Configurations
```
GET    /api/v1/llm-drivers                              → getDrivers (public, schema discovery)
GET    /api/v1/llm-configs                              → index (user-scoped)
POST   /api/v1/llm-configs                               → store
GET    /api/v1/llm-configs/{id}                         → show (404 if not owner)
PUT    /api/v1/llm-configs/{id}                         → update (404 if not owner)
DELETE /api/v1/llm-configs/{id}                         → destroy (404 if not owner)
POST   /api/v1/llm-configs/{id}/set-default             → setDefault (user-scoped)
```

### Tasks
```
GET    /api/v1/tasks                       → index (optional ?agent_id=X)
POST   /api/v1/tasks                       → store ({ agent_id, prompt, parent_task_id? })
GET    /api/v1/tasks/{id}                  → show (optional ?since_sequence=X)
POST   /api/v1/tasks/{id}/approve          → approve ({ approvals: [{provider_call_id, arguments}] })
POST   /api/v1/tasks/{id}/reject           → reject ({ reason })
```

### Notifications
```
GET    /api/v1/notifications                → index (paginated, ?unread_only=true)
POST   /api/v1/notifications/{id}/read      → mark read
POST   /api/v1/notifications/read-all       → mark all read
DELETE /api/v1/notifications/{id}          → delete
```

### SSE / Realtime
```
GET    /api/v1/sse/auth                     → { hubUrl, token } for Mercure SSE subscription
```

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
- Toggle button in navbar (sun/moon SVG icons).

---

## Tests

Located in `frontend/tests/`. Run with `npm test`.

- `stores/theme.spec.ts` — dark mode toggle, localStorage persistence, apply()
- `stores/auth.spec.ts` — login, logout, register, init deduplication
- `stores/agent.spec.ts` — CRUD, tools, LLM config
- `stores/llmConfigs.spec.ts` — CRUD, set-default, API integration
- `stores/tasks.spec.ts` — fetch, create, approve/reject, pendingToolCalls, isTerminal
- `components/TaskStatusBadge.spec.ts` — all 5 status variants

---

## Real-Time

**Primary (polling):** REST polling — `GET /api/v1/tasks/{id}?since_sequence=X` every 2s for active tasks, 10s when idle. Notifications polled every 30s.

**Enhanced (SSE via Mercure):** `useRealtime()` composable (`composables/useRealtime.ts`) automatically detects Mercure availability via `GET /api/v1/sse/auth`. When available, opens `EventSource` to the Mercure hub subscribing to `task/*` and `user/{id}/notifications`. Falls back to HTTP polling on network failure or 404.

**Components:**
- `NotificationCenter.vue` — slide-in notification panel triggered from the navbar bell icon
- `GlobalNavbar.vue` — bell icon with unread badge count, wires `useRealtime()`
- `useRealtime()` composable — auto-connects on mount, cleans up on unmount, reconnects with exponential backoff

## Composer & Templates

The `AgentPage.vue` composer supports:
- **Prompt templates** — select from saved templates, fill variables, submit
- **Save as template** — inline mini-modal to save current prompt as a reusable template
- **Follow-up questions** — after a task completes, a follow-up input bar appears above the composer for continuing the conversation (controlled by `allow_followup` agent setting)
- **Schedule** — one-shot scheduled run via date/time picker modal

## E2E Tests

Playwright tests in `frontend/tests/e2e/`. Run with `npm run test:e2e` from the `frontend/` directory (requires Docker Compose for the web server).

| File | Tests |
|---|---|
| `task-lifecycle.spec.ts` | Create task, wait for completion |
| `tool-approval.spec.ts` | Enable approval tool, trigger, approve via UI |
| `scheduled-run.spec.ts` | Create one-shot run, trigger via API |
| `followup.spec.ts` | Run task, submit follow-up, verify continuation |
