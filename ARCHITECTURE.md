# Spora: Architectural Principles

This document serves as the north star for Spora's source code architecture and operational philosophy. Any new features, tools, or UI components should map perfectly to this model.

## 1. Configuration Philosophy

Spora supports two configuration modes, designed for different deployment contexts:

- **File-based (shared hosting default):** `config.php` for application settings + `storage/secret.key` for the encryption key. Simple to set up via FTP with no shell access required.
- **Environment variable (Docker / VPS / CI):** All settings via `SPORA_*` env vars or a `.env` file in the project root. `SPORA_SECRET_KEY` (base64 32 bytes) replaces the key file entirely. `config.php` is optional when all required vars are present.

Priority: OS env vars > `.env` file > `config.php`. The Kernel reads all three on every boot.

Passwords (DB password, tool credentials) must always come from env vars — they are never written to `config.php`.

**Why the encryption key is not in `config.php`:**
The key is kept out of `config.php` for two reasons:

1. **It's binary.** `sodium_crypto_secretbox_keygen()` produces 32 raw bytes, not a printable string. Embedding binary in a PHP file requires encoding it first — which is exactly what the env var path does (`SPORA_SECRET_KEY` is base64-encoded). Storing it as a dedicated file avoids that round-trip.

2. **Key and ciphertext must never travel together.** The encrypted tool credentials live in the database. If the key lived in the same directory, a single backup or accidental commit would hand an attacker both. The key must be stored separately from the data it protects.

**Practical implications for the key file:**

`storage/secret.key` and `storage/database.sqlite` are in the same directory — which defeats the separation principle the moment someone takes a full project backup. Therefore install.php auto-detects the best available location outside the project:

1. `~/.spora/secret.key` — the cPanel home directory is the account root; `public_html/` is a subdirectory, so `~/.spora/` is outside the web root and never captured by a project-level backup. This is the default target on shared hosting.
2. `dirname(project)/.spora/secret.key` — fallback for VPS layouts where `$HOME` is not meaningful.
3. `storage/secret.key` — last resort if neither location is writable. Install.php prints a prominent warning and the user should migrate the key when possible.

The resolved path is stored in `config.php` as `key_path`. The path itself is not secret — only the file it points to is. For Docker/CI, `SPORA_SECRET_KEY` (base64 env var) bypasses the file entirely.

This is the same principle behind SSH private keys living in `~/.ssh/` rather than in the repository they authenticate.

---

## 2. The "Digital Employee" MVP
Spora is "The WordPress of AI Agents," built on the concept of **"My Assistant"**—a single, highly autonomous AI assistant configured uniquely for the user.
- **The Backpack:** Spora is equipped via a UI dashboard where users define its Tools, API connections, and settings.
- **The Engine:** While the UI is simplified to "My Assistant," the underlying database uses an `agent_id` structure. This ensures the future evolution into a multi-agent orchestration tool requires absolutely zero database structural refactoring.

## 3. Tool Taxonomy (Input/Output Isolation)
Spora categorizes tools strictly into two interfaces to resolve the core fear of autonomous AI: "Is it going to break something?"

### A. Input Tools (The Senses & Imagination)
These implement Spora's `InputToolInterface`. 
- **Rule:** Read-only operations or internal asset generation. This includes searching the web, querying a database, or even **Generative AI** (like generating an image or a document). 
- **Behavior:** Entirely safe. Generative tools (like DALL-E) are considered "Inputs" because they simply return data (an image URL) back into Spora's context window. They do not affect the external world. The Agent may invoke these tools autonomously at any time to query information or generate assets. They require *no human approval*.
  
> [!NOTE]
> If Spora generates an image, the human approval happens *later*, when Spora attempts to pass that generated image URL to an `OutputTool` (like sending it in a Slack message).

### B. Output Tools (The Hands)
These implement Spora's `OutputToolInterface`.
- **Rule:** Write actions or actions that affect the real world (e.g., Sending an Email, Posting a Tweet, Creating a Calendar Event).
- **Default Behavior:** Human-in-the-loop — calling an Output Tool suspends the Agent pending user approval.
- **Auto-approve:** Each Output Tool declares a class-level default via `#[OutputTool(requiresApproval: true)]`. The agent owner can override this per-tool via `agent_tools.auto_approve`. When auto-approved, the Orchestrator executes the tool immediately without pausing.

## 4. The Orchestrator Loop & State Machine
Spora requires a *custom-built* Agent Orchestrator. While PHP libraries (like Prism or LLM-Chain) exist, they use synchronous `while()` loops that make it nearly impossible to pause execution across HTTP requests. Spora's loop relies on the SQLite database and `symfony/messenger` queue.

**The Loop Structure (`max_steps` limited):**
A single Agent Task has a `step_count`. To prevent infinite loops (and massive API bills), the Orchestrator enforces a strict limit (e.g., 10 iterations). 

1. **Think:** Spora sends the System Prompt (Recipe), History, and Backpack Tools to the LLM.
2. **Act (Input Tool):** If the LLM decides to use an `InputTool` (e.g., SearchWeb), the Orchestrator executes it instantly, appends the result to the Task history, increments `step_count`, and loops back to Step 1.
3. **Intercept (Output Tool):** If the LLM decides to use an `OutputTool` (e.g., SendEmail), the Orchestrator intercepts the call and checks the approval requirement:
   - **Resolve approval:** Check `agent_tools.auto_approve` for this tool+agent. If `NULL`, fall back to the tool's `#[OutputTool(requiresApproval:)]` class default.
   - **If approval required:** Spora serializes the current state (memory + exactly what arguments the Agent passed to the tool) into the SQLite database as a `PENDING_APPROVAL` status. *The PHP script gracefully stops entirely.* This is crucial for shared hosting.
   - **If auto-approved:** Execute the tool immediately, append the result to history, increment `step_count`, and loop back to Step 1.
4. **The Notification & Review:** (Approval-required path only.) Spora notifies the User via the Dashboard UI (and eventual push notifications/emails) that an action requires review. The user approves, edits, or rejects the drafted action.
5. **Resume (Queue Dispatch):** If approved via the UI, an API call is made. Spora executes the tool, logs it, and dispatches a *new* Message onto the queue to "wake" the agent back up (Step 1) so it can finish its workflow.
6. **Complete:** If the LLM returns standard text instead of a tool call, the Orchestrator marks the Task as `COMPLETED`.

---

## 5. Plugin System

Plugins extend Spora by dropping a folder into `plugins/`. The `Kernel` scans this directory at boot and auto-discovers any class implementing `PluginInterface`. No manual registration is needed — the same "drop and go" philosophy as WordPress plugins.

Plugins can contribute:
- **Input/Output Tools** — declared via `tools()`, auto-registered into the Tool Registry
- **LLM Drivers** — declared via `drivers()` as a `['provider_name' => DriverClass::class]` map, made available in agent config
- **Recipes** — declared via `recipePaths()`, merged with the built-in `/recipes/` directory
- **Arbitrary DI bindings** — via `register(ContainerBuilder $builder)` for anything else (middleware, services, event listeners)

**Class loading:** Plugins are fully self-contained. The Kernel auto-requires `plugins/MyPlugin/vendor/autoload.php` if present (for third-party deps the plugin author bundled via `composer install`), then registers the plugin's own PSR-4 mappings declared in `autoload()`. No assumption is made about the host environment — this is essential for shared hosting compatibility.

**Dependency conflicts:** PHP's autoloader is first-loaded-wins. Plugins bundling the same package at different versions, or conflicting with Spora core deps, will silently use whichever loaded first. The Kernel detects PSR-4 namespace collisions at boot and logs a `WARNING`. Plugin authors are strongly encouraged to use `php-scoper` to prefix their vendor namespaces (e.g. `GuzzleHttp\Client` → `MyPlugin\Deps\GuzzleHttp\Client`), achieving full isolation regardless of load order. Plugins should also prefer packages already provided by Spora core over bundling their own copies.

PHP-DI is the mechanism that makes contributions composable: the Kernel builds the container from core definitions first, then each plugin's `register()` call can add or override bindings before the container is compiled.

---

## Future Release Ideas

### Tool Configuration Inheritance
PHP attribute inheritance already gives derived tools their parent's `#[ToolSetting]` schema for free (via `ReflectionClass`). However, stored configuration data is not shared — a plugin subclassing `SearchWebTool` would still require the user to re-enter credentials.

A future release could introduce an `#[InheritsConfigFrom(ParentTool::class)]` attribute. The `ToolConfigService` would resolve config reads by walking up the inheritance chain, falling back to the parent's `tool_configurations` row if no override exists for the child. This would let plugin authors ship specialised variants of built-in tools without requiring duplicate credential entry.

**Why deferred:** Adds complexity to the registry, override resolution logic, and UI (which tool "owns" the config?). The single-agent v1 use case does not justify it.
