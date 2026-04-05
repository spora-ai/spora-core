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
| `/agents/:id` | `AgentPage.vue` | Agent detail — chat + config |
| `/agents/:id/settings` | `AgentSettingsPage.vue` | Agent settings — identity, LLM, tools, danger zone |
| `/settings` | `GlobalSettingsPage.vue` | Global settings — tools, LLM drivers |
| `/settings/llm` | `LLMConfigsPage.vue` | LLM Driver Configurations — create, edit, delete, set default |
| `/tasks/:id` | `TaskChatPage.vue` | Full-screen task chat (polling detail view) |

---

## State (Pinia stores)

| Store | File | Responsibility |
|---|---|---|
| `auth` | `stores/auth.ts` | User session, login/logout/register |
| `theme` | `stores/theme.ts` | Dark mode toggle, `localStorage` persistence, `dark` class on `<html>` |
| `agent` | `stores/agent.ts` | Multi-agent CRUD, tool enable/disable |
| `llmConfigs` | `stores/llmConfigs.ts` | LLM Driver Configurations CRUD, set-default |
| `tasks` | `stores/tasks.ts` | Task list, task detail, polling, approve/reject |

---

## Navigation

```
Dashboard (agent list)
  └─ Agent card tap → AgentPage (chat + config)
                        └─ Settings button → /agents/:id/settings (full settings page)

Navbar (global only)
  ├─ App logo/name (← Dashboard)
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
POST   /api/v1/tasks                       → store ({ agent_id, prompt })
GET    /api/v1/tasks/{id}                  → show (optional ?since_sequence=X)
POST   /api/v1/tasks/{id}/approve          → approve ({ approvals: [{provider_call_id, arguments}] })
POST   /api/v1/tasks/{id}/reject           → reject ({ reason })
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

**Base (shared hosting safe):** REST polling — `GET /api/v1/tasks/{id}?since_sequence=X` every 2 s.
**Optional enhancement:** Mercure/SSE via `symfony/mercure` + FrankenPHP Docker image.
