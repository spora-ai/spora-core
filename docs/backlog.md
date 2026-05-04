# Backlog

---

## High

### Tool Call Abort/Retry
Stop mid-flight or replay a failing tool call without restarting the task or burning an LLM call.

### Parallel Tool Calls
Run independent tools simultaneously instead of sequentially to reduce wall-clock wait.

---

## Medium

### Web Push Notifications
Surface `PENDING_APPROVAL` alerts even when the browser tab is closed/inactive.

### Agent-to-Agent Handovers
Transfer task context from one agent to another (e.g., researcher → writer).

### Notification Optimizations
Bulk clear all notifications; e-mail alerts for scheduled run completions; deduplicate when a scheduled run fires both "scheduled run complete" and "task completed" for the same event.

### Mobile UI Improvements
Fix broken Agent sidebar on small screens; optimize Settings Menu for mobile viewports.

---

## Low / Exploratory

### Multi-User with Groups & Fine-Grained Access
Basic user roles exist; extend with groups and per-resource permissions (agents, tasks, credentials, plugins).

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