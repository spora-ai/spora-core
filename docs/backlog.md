# Backlog

High-priority and medium-priority features, organized by priority. Each item includes motivation and key implementation notes.

---

## High

### 01 — Tool Call Abort/Retry

**Motivation:** Once a task is running, there is no way to stop it mid-flight or re-run a failing tool call without restarting the entire task. Users need to interrupt or recover from bad tool calls without losing conversation context.

**Key considerations:**
- Abort: Signal the Orchestrator to halt after the current `tick()`. Store enough state to allow a human to inspect and `resume()` with edits.
- Retry: Re-execute the last tool call with fresh arguments without a new LLM call (i.e., replay the tool, append the result to history, continue). This avoids re-sending the full conversation to the LLM.
- The Orchestrator's three-phase tick structure (claim → LLM → write) must be preserved.
- `task.pending_state` already stores full `AgentState` for `PENDING_APPROVAL` — similar machinery could be reused for abort.
- Abort should not leave the task in a zombie `RUNNING` state; use `FAILED` or a new `ABORTED` terminal state.

**Depends on:** Nothing blocking.

---

### 02 — Daemon Runner Optimization: Parallel Tool Calls

**Motivation:** In a single LLM turn, an agent may call multiple *independent* tools (e.g., "search the web AND check my calendar"). Currently these execute sequentially, wasting time on I/O wait.

**Key considerations:**
- Only *independent* tools can be parallelized — tools with data dependencies must remain sequential.
- Detection: The Orchestrator receives all tool calls for a turn simultaneously from the LLM response. It can inspect arguments to determine dependency ordering (e.g., tool A's output is not referenced in tool B's input → independent).
- The LLM is blocked waiting for *all* tool results before it can continue, so parallelizing only speeds up wall-clock time, not the number of LLM calls.
- Careful with rate limits — parallel requests to the same API may hit burst limits.

**Research needed first:** Evaluate parallelization frameworks — candidates include `React\Promise`, `symfony/http-client`'s `AsyncMessage`, or PHP Fibers. Decision affects architecture.

**Depends on:** Nothing blocking. Can be prototyped independently.

---

## Medium

### 03 — Web Push Notifications

**Motivation:** Users miss `PENDING_APPROVAL` notifications when the browser tab is in the background or closed. Push notifications would surface these immediately, improving approval turnaround time.

**Key considerations:**
- Use the **Web Push API** (`navigator.serviceWorker.register`) with a VAPID key pair.
- Store the user's push subscription endpoint in the `users` table.
- Server-side: use `minishlink/web-push` Composer package to send notifications.
- Triggered by `NotificationService::notifyPendingApproval()` — same trigger as the in-app notification.
- Requires HTTPS (or `localhost`) — push cannot be delivered to HTTP origins.
- Should degrade gracefully if the browser doesn't support push or the user denies permission.

**Depends on:** NotificationService already exists (see `app/Services/NotificationService.php`).

---

### 04 — Agent-to-Agent Handovers

**Motivation:** In complex workflows, one agent may need to hand off the task context to a different agent (e.g., a "researcher" agent passes findings to a "writer" agent). Currently there is no mechanism to transfer conversation state between agents.

**Key considerations:**
- A task is already linked to a single `agent_id`. Handover would create a *new* task with a different `agent_id`, copying relevant history from the source task.
- `parent_task_id` already exists for follow-up questions — a handover could use the same mechanism or a dedicated `handover_to_agent_id` column.
- The LLM prompt for the receiving agent should include a synthesized summary of the source conversation, not raw history (to stay within context limits).
- Tool state may need to be transferred if tools are agent-specific (e.g., the writer agent inherits the researcher's search results).

**Depends on:** Nothing blocking. Can be a new `handover()` method on `OrchestratorInterface`.

---

### 05 — User Management: Multi-User with Roles

**Motivation:** Currently Spora is single-tenant (one user per installation). Multi-user support would allow teams to share an instance, each with their own agents, tasks, and credentials.

**Key considerations:**
- The auth layer already uses `delight-im/auth` with session-based auth — extend with a `roles` table and `role_id` on `users`.
- Roles: `admin` (manage other users, global settings), `user` (own agents and tasks only).
- Multi-tenancy isolation is already implemented at the `user_id` level in all models — most CRUD endpoints already scope to `currentUserId()`.
- What needs changes: user management endpoints (create, delete, list users), role assignment, and UI (admin panel).
- Plugin-level isolation: plugins store data keyed by `user_id` — this is already the case if they use the same models.
- Credential encryption (`SecurityManager`) is per-instance, not per-user — investigate if credentials from one user could leak to another via the encryption key.

**Depends on:** Significant. Requires design for role system and admin UI.

---

## Low / Exploratory

### 06 — MCP Server Integration

**Motivation:** The Model Context Protocol (MCP) is an emerging standard for connecting AI models to external tools/data sources. Supporting MCP would allow Spora to act as an MCP client or server.

**Key considerations:**
- MCP could be implemented as an LLM driver (Spora → MCP server → LLM) or as a tool layer (Spora tools → MCP server).
- `mcp/mcp-php` SDK (or equivalent) would be needed.
- This is exploratory — the MCP spec is still evolving. Don't commit to a specific implementation until the protocol stabilizes.

---

### 07 — Multimodal / Image Inputs

**Motivation:** Agents currently only handle text. Adding image input would allow users to upload screenshots, diagrams, or photos as part of task prompts.

**Key considerations:**
- The LLM drivers already exist — need to check if `OpenAICompatibleDriver` and `AnthropicCompatibleDriver` support multimodal models (e.g., `gpt-4o`, `claude-3-opus`).
- Frontend: file upload in `AgentPage.vue` composer, image preview, storage (local filesystem or object storage).
- `task_history` stores `content` — need a new content type for images (base64 or URL reference).
- Size limits needed to prevent abuse.

---

### 08 — Apps from Plugins

**Motivation:** Spora's plugin system currently supports tools, drivers, and recipes. Extending it to "apps" would allow plugins to contribute full UI pages (dashboards, widgets, custom settings).

**Key considerations:**
- Requires a plugin UI registry: which routes, components, or nav items a plugin contributes.
- Frontend needs a dynamic routing mechanism to load plugin Vue components.
- Plugin assets (JS/CSS) would need to be bundled and served — currently no mechanism for this.
- This is closely related to the existing plugin hook system, but extends it to the frontend.

---

### 09 — WordPress-Style Web Installer

**Motivation:** Installing Spora on shared hosting requires FTP/CLI access to run `spora:install`. A web-based installer would allow non-technical users to set up Spora on cPanel hosts without shell access.

**Key considerations:**
- Must not overwrite existing configuration — idempotent.
- Steps: DB init, admin user creation, encryption key generation, recommended `php.ini` settings check.
- `DatabaseSchemaInstaller` already supports programmatic invocation — the installer just needs a UI layer.
- Risk: running schema installation via HTTP on a shared host exposes schema details. Ensure the endpoint requires an unconfigured state (first run only).

**Depends on:** `DatabaseSchemaInstaller` API already supports this (see `docs/02_schema.md`).

---

### 10 — AI Image Generation Tool

**Motivation:** Agents should be able to generate images as part of task completion — creating diagrams, concept art, thumbnails, or visual content on demand via an image generation API.

**Key considerations:**
- Integrate with OpenAI's DALL-E API as the first provider. Tool action: `generate(prompt, size?, format?)` → returns image URL or base64.
- Reuses the existing `symfony/http-client` transport — no new driver needed.
- Prompt handling: the agent composes the prompt from task context; the tool just executes the API call and returns the result.
- Output: store the generated image and return a reference (path/URL) to the agent for inclusion in responses.

### Storage: Filesystem vs. Database for BLOBs

This applies to any future BLOB storage (images, files, etc.). The tradeoffs:

| | Filesystem | Database (BLOB) |
|---|---|---|
| **Pros** | Simple, works on all hosts, no DB bloat, easy to serve via HTTP | Backup/restore in one dump, no path issues, ACID consistency |
| **Cons** | Path coupling, harder to sync across nodes, separate backup strategy | Large DB size, slower queries, memory pressure on the DB server |

**Recommendation: Filesystem** — Spora targets shared hosts (cPanel/FTP) where a filesystem is the most portable option and keeps the database small and portable. Use a consistent storage path: `storage/app/generated_images/{user_id}/{task_id}/{filename}`. Implement a `StorageService` (or `spora/storage` plugin) as a unified layer so tool classes don't hardcode paths.

**Depends on:** Nothing blocking. Can be prototyped with a single tool class + OpenAI driver.
