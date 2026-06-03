# Backlog

---

## High

*No high-priority items currently — see below for medium and low priority items.*

---

## Medium

### Parallel Tool Calls
Run multiple independent tool calls simultaneously within a single agent turn to reduce wall-clock wait. Note: The system currently handles parallel **Tasks** (multiple agents running concurrently), not parallel **tool calls** within a single LLM response.

### Web Push Notifications
Surface `PENDING_APPROVAL` alerts even when the browser tab is closed/inactive. Currently implemented via Mercure/SSE for real-time web notifications, but NOT browser Web Push API (Service Workers).

### Agent-to-Agent Handovers
Handovers with various documents (conversation context, intermediate results, files, partial state) can happen in the future. The current `parent_task_id` chaining is the seed mechanism — it lets a follow-up task inherit the prior task's `task_history`, so a second agent can pick up where the first left off — but it is not yet a first-class UX surface (no "send to agent X with N more steps" shortcut in the UI, no explicit per-document handoff format). Add: (1) a UI action to forward a `COMPLETED` task to a chosen agent with optional additional steps, (2) a typed handoff document schema (which sections of history, which intermediate results, which files), (3) per-agent routing rules for automated handoffs.

### Notification Optimizations
Bulk clear all notifications; e-mail alerts for scheduled run completions. Both are implemented: `markAllAsRead()` (`/api/v1/notifications/read-all`) and `deleteAllForUser()` (`DELETE /api/v1/notifications`) routes exist, and `sendEmailForScheduledRun()` runs after scheduled-run completion. Scheduled-run deduplication is already handled — `Orchestrator::tick()` suppresses `notifyTaskCompleted()` for tasks with a `run_id` (`app/Agents/Orchestrator.php:238`), so only `notifyScheduledRunCompleted()` fires.

### Mobile UI Improvements
Fix broken Agent sidebar on small screens; optimize Settings Menu for mobile viewports. Responsive sidebar implemented in AgentLayout.vue, AppsLayout.vue, and SettingsSidebar.vue.

### Recipe System (not yet implemented end-to-end)
The recipe scaffolding exists in the backend (`agents.recipe_id` column, `RecipeScanner`, `RecipeController`, `GET /api/v1/recipes`, `PluginInterface::recipePaths()`), but the system is **not shipped and not usable** as of this release:

- `recipes/` is empty — no recipes are bundled with Spora.
- No frontend integration: `recipe_id` exists in `frontend/src/types/agent.ts` but is not consumed by any page or store.
- No plugin ships a recipe either.

To finish: (1) author at least one bundled recipe (e.g. `general_assistant.yaml`) so the scanner has something to return, (2) wire the `recipe_id` field into the agent create/edit UI, (3) add a recipe picker to the agent run flow so `agents.recipe_id` actually drives the system prompt, (4) document the recipe YAML schema, available variables, `{{var}}` templating in prompts, and how recipes differ from agent templates.

Note: the current variable substitution is a simple `{{var}}` regex (`app/Services/ScheduledRunService.php:416`, `app/Console/Commands/WorkerRunCommand.php:384`), not full Mustache (no `{{#section}}`/`{{^inverted}}`).

---

## Low / Exploratory

### Multi-User with Groups & Fine-Grained Access
Basic user roles exist; extend with groups and per-resource permissions (agents, tasks, credentials, plugins). Currently only basic roles are implemented (`hasRole()`, `grantRole()`, `revokeRole()`), no groups or per-resource permissions.

### MCP Server Integration
Act as an MCP client or server to connect to external tools/data sources.

### Multimodal / Image Inputs
Allow agents to accept screenshots or photos as part of task prompts.

### Plugin-Contributed UI Pages
Plugins can add full dashboards and pages, not just tools/recipes.

### Web Installer
cPanel/no-shell setup via browser — run `spora:install` without CLI access.

### AI Image Generation
Agents generate images via DALL-E (or similar) as part of task completion.

### Visual Markdown Editor for System Prompt
Rich editor with live preview for crafting system prompts — optional for the Initial Chat window as well.
