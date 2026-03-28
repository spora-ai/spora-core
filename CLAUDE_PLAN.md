# Spora Handover & Execution Plan 

**Welcome, Claude!** 
You are tasked with building the foundation of **Spora**, the "WordPress of AI Agents." Spora is a highly portable, zero-configuration agent orchestration tool built in modern PHP 8.1+ and designed to run on any standard web host (cPanel/FTP).

## 1. Project Context & Paradigms
- **The "Digital Employee"**: Spora operates as a single "My Assistant" for the user. While the DB structure should use `agent_id` for future scale, V1 is a single-agent UX.
- **Zero-Config Database**: Spora uses SQLite by default to remove "Create Database" friction. **However, it fully supports standard MySQL/MariaDB.** The configuration (`config.php` or `.env`) must gracefully fallback to MySQL if credentials are provided.
- **The State Machine (Human-in-the-Loop)**: The most critical architectural feature. Refer to `ARCHITECTURE.md` in this directory. 
  - `InputToolInterface` = Safe, read-only tools. Agent runs instantly.
  - `OutputToolInterface` = Unsafe, write tools. Agent execution strictly pauses, saves state to DB as `PENDING_APPROVAL`, and waits for the Human to approve via the UI before resuming via a Queue message.
- **Plugin & Tools Ecosystem**: Tools are standard PHP classes that use **PHP 8 Attributes** to define metadata (Name, Description, Settings) for the LLM and the UI. "Recipes" (agentic workflows) are defined via JSON/YAML files. Keep this Attribute-first design in mind when scaffolding the Core.

## 2. Technical Stack
**Backend (PHP 8.1+)**
- `symfony/http-foundation` (Request/Response & JSON REST support)
- `symfony/messenger` (Custom Orchestrator Loop & Queue. Supports daemon workers OR synchronous web-request execution).
- `nikic/fast-route` (Attribute-based Routing using `php-di`).
- `php-di/php-di` (Dependency Injection Container for the micro-kernel).
- `illuminate/database` (Laravel Eloquent ORM - perfect zero-config DB abstraction for SQLite and MySQL/MariaDB).
- `delight-im/auth` (Standalone, headless User Authentication logic).
- `pestphp/pest` (Testing).

**Frontend (Vue 3)**
- Vue 3 (Composition API)
- Vite (Used *locally only* to output to `../public/dist`. Prod host needs no Node.js. Dev mode should use API proxying to the PHP backend).
- Tailwind CSS
- `shadcn-vue` (Premium UI component library)

## 3. Directory Structure
Please enforce this exact structure. The base PHP namespace is `Spora\` mapping to the `app/` directory.

```text
/
├── .htaccess          # Forwards all HTTP traffic to public/index.php
├── app/
│   ├── Core/          # Kernel, DI Container, Router
│   ├── Auth/          # delight-im/auth wrapper
│   ├── Agents/        # Agent Orchestrator and Custom Loop
│   ├── Tools/         # InputToolInterface, OutputToolInterface & Annotations/Attributes
│   ├── Drivers/       # OpenAI / Anthropic Integrations
│   ├── Http/          # REST API Controllers (JSON headers)
│   └── Models/        # Eloquent Models (User, Agent, Task, ToolCall)
├── frontend/          # Vue + shadcn-vue source code
├── plugins/           # Custom user-added Tools
├── recipes/           # Agentic Workflows (JSON/YAML)
├── storage/           # SQLite DB (.sqlite), Logs, and App Secrets
├── public/
│   ├── dist/          # COMPILED Vue Assets (JS/CSS)
│   └── index.php      # Main PHP Entry Point
└── config.php         # Zero-config environment arrays
```

## 4. Phase 1 Execution Roadmap (Your Tasks)
**TDD REQUIREMENT:** We demand high code quality. For every Task, you MUST write the corresponding `Pest` test before or immediately after implementation. Ensure comprehensive unit coverage for the foundational Kernel and Database connections.

- [ ] **Task 1: Project Initialization**
  - Run `composer init` and configure basic `composer.json` (PHP 8.1+, autoload `Spora\\` to `app/`).
  - Require the backend dependencies listed above.
  - Setup the basic folder structure. Add `./vendor/bin/pest --init`.

- [ ] **Task 2: Core Micro-Kernel**
  - Create `config.php` (returning an array pointing DB to `storage/database.sqlite` by default, but supporting MySQL/MariaDB keys).
  - Create `app/Core/Kernel.php` (initializes PHP-DI and handles Request/Response loop).
  - Create `app/Core/Router.php` (wrapping `fast-route` with `php-di` container invocation).
  - Create `public/index.php` and the root `.htaccess`.

- [ ] **Task 3: Security & Database Scaffold**
  - Create `app/Core/SecurityManager.php` using native `libsodium` to generate/read a master `storage/secret.key`.
  - Create `app/Core/Database.php` using `illuminate/database` Capsule to establish an SQLite connection (or MariaDB if configured) and create the `.sqlite` file automatically if it does not exist.
  - *Verify:* Run Pest tests for the Kernel, SecurityManager, and Database connection.

- [ ] **Task 4: Frontend Scaffold**
  - Run the Vite + Vue 3 initialization inside the `frontend/` directory.
  - Install Tailwind CSS and initialize `shadcn-vue`.
  - Configure `vite.config.ts` to output its build artifacts into `../public/dist` and set up API proxying to `localhost:8000` (or your PHP dev server port).

**Context is ready. Begin Task 1!**
