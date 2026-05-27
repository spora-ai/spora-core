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
Transfer task context from one agent to another (e.g., researcher → writer).

### Notification Optimizations
Bulk clear all notifications; e-mail alerts for scheduled run completions. Partially implemented: `markAllAsRead()` and `sendEmailForScheduledRun()` exist, but no deduplication when a scheduled run fires both "scheduled run complete" and "task completed" for the same event.

### Mobile UI Improvements
Fix broken Agent sidebar on small screens; optimize Settings Menu for mobile viewports. Responsive sidebar implemented in AgentLayout.vue, AppsLayout.vue, and SettingsSidebar.vue.

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

### Recipe System Documentation
Document the recipe YAML schema, available variables, Mustache templating in prompts, and how recipes differ from agent templates.
