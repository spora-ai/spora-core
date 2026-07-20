# Spora

**What:** A portable, zero-configuration AI agent orchestration platform that runs on any shared host (cPanel/FTP) without system dependencies beyond PHP 8.4+.

**Stack:** Spora is standalone PHP — NOT Laravel. It borrows components from the Laravel and Symfony ecosystems for portability and reliability, but runs no full stack framework.

| Package | Role | Why |
|---|---|---|
| `symfony/http-foundation` | Request/response objects | Cleaner API than raw PHP globals |
| `symfony/console` | CLI framework | `bin/spora` commands |
| `symfony/http-client` | HTTP client | HTTP transport for LLM driver requests |
| `symfony/mailer` | Email sending | `SendEmailTool` via Symfony Mailer |
| `symfony/yaml` | YAML parsing | Parsing recipe definition files |
| `nikic/fast-route` | Routing | Fast, standalone router |
| `php-di/php-di` | Dependency injection | Framework-agnostic DI container |
| `illuminate/database` (Eloquent) | Database ORM | Zero-config ORM with SQLite support |
| `delight-im/auth` | Authentication | Lightweight, standalone auth |
| `monolog/monolog` | Logging | PSR-3 logger, PII-safe argument policy |
| `dragonmantank/cron-expression` | Cron parsing | Parsing `cron_expression` for scheduled runs |
| `vlucas/phpdotenv` | Env loading | Loading `.env` files on boot |
| `webklex/php-imap` | Email reading | IMAP access for `ReadEmailTool` |
| `chriskonnertz/string-calc` | Math expressions | Evaluating math strings in `CalculatorTool` |
| `pestphp/pest` | PHP testing | Elegant testing framework |
| Vue 3 + Vite + Tailwind + radix-vue + lucide-vue-next | Frontend | Modern JS stack (shared with Laravel Breeze/Fortify) |

---

## Development Rules

### Enforceable
- `declare(strict_types=1)` on every PHP file
- `final` on all classes unless inheritance is required
- No DB calls in constructors — boot explicitly via `Database::bootDatabaseConnectionOnly()`
- No mocks for integration tests that already boot the DB via `beforeEach`
- Don't add error handling, fallbacks, or abstractions beyond what the task requires
- **Never commit or push directly to `main`.** Every change goes through a feature branch and a pull request — even single-commit fixes. Branch naming: `<scope>/<phase-or-feature>` (e.g. `coverage/phase-1.4-orchestrator-and-controllers`, `feat/tool-authooring-dx`, `fix/logout`). Local `main` may be fast-forwarded via `git pull --ff-only` but never receives a direct push. SonarQube's `new_coverage` metric is calculated per PR, so bypassing the PR flow breaks the quality-gate signal.

### CLI Entry Points

Spora has two CLI binaries, one for runtime/operations and one for build-time
tools that must run in a clean checkout with only the source tree.

**Runtime: `bin/spora`** — boots the Kernel, DI container, and `storage/secret.key`.
This is the operator-facing CLI for installing, migrating, seeding, running workers.
- `spora:install` — Initial setup (idempotent migrations)
- `db:reset` — Wipe the database (SQLite file or MySQL DROP+CREATE) and clear the schema stamp
- `db:seed` — Seed database with sample data
- `worker:run` — Run async worker (queued mode)
- `worker:run --scheduled` — Run scheduled tasks worker
- `spora:openapi [--output=openapi.json] [--check]` — Generate / drift-check the OpenAPI spec
- Full CLI reference: `bin/spora --help`

**Build: `bin/spora-build`** — deliberately skips the Kernel / DI / secret-key boot
because downstream tooling (CI, sibling-repo docs builds) checks out spora-core
into a sibling directory and runs this binary without an installed app. Lives
in `app/Build/`; gates on the `zircote/swagger-php` dev dependency. Today:
- `openapi:generate [output]` — emit `openapi.json` to the given path (default
  `openapi.json` resolved against `BASE_PATH` or the package root)
- `openapi:check [reference]` — exit non-zero if the freshly regenerated spec
  differs from `reference` (default `openapi.json`)
- Composer alias: `composer openapi` (alias for `openapi:generate`)

### Storage
`storage/` directory:
- `database.sqlite` — Application database
- `spora.log` — Application logs (PSR-3, Monolog)
- `php.log` — PHP error/fatal logs

### Environment Variables
- `SPORA_SYNC_MODE` — Worker execution mode: `true` = inline/dev (synchronous), `false` = queued/worker (async)
- `APP_ENV` — Environment (`dev`, `prod`)

### CI
GitHub Actions runs on push to `main`, on `v*` tags, and on pull requests (see [`.github/workflows/ci.yml`](.github/workflows/ci.yml)). Jobs: `php-lint` (PHPStan), `php-test` (Pest), `frontend-lint` (ESLint + tsc), `frontend-test` (Vitest), `build-docker`, `push-ghcr`.

### SonarQube (MCP)
- Project key `spora-ai_Spora` (see `sonar-project.properties`).
- For PRs use the `pullRequest` key from `list_pull_requests`; for long-lived branches use `branch`. Never pass a git branch name to `pullRequest`.
- Tool names vary by MCP server version — read the server's tool list, don't memorise.

> **Architecture deep-dive:** The Orchestrator loop, tick phases, worker modes, and plugin system are documented in [docs/01_architecture.md](docs/01_architecture.md) and [docs/11_agent_loop_async.md](docs/11_agent_loop_async.md).

### Testing
- Backend: `composer test` (Pest)
- Frontend unit: `composer frontend:test` (Vitest)
- E2E: not wired up — no Playwright dep, no `frontend/tests/e2e/` (see [docs/09_frontend.md](docs/09_frontend.md))

### Code Comments
See [docs/14_code_documentation.md](docs/14_code_documentation.md) for comment standards (DELETE noise, KEEP rationale, ADD docblocks).

---

## Project Structure

```
/app               — PHP application code (MVC-style)
  /Agents          — Agent models and orchestration logic
  /Auth            — Authentication (AuthService, AuthController)
  /Console         — CLI commands (install, worker, etc.)
  /Core            — Kernel, Router, DI container, base classes
  /Drivers         — LLM driver implementations (OpenAI, Anthropic)
  /Http            — Controllers, middleware, request/response handling
  /Models          — Eloquent models
  /Plugins         — Plugin loader and hooks
  /Recipes         — Recipe scanner
  /Services        — Business logic (ToolConfigService, NotificationService, etc.)
  /Tools           — Built-in tool implementations
/bin/spora        — CLI entry point
/config.php        — Application configuration
/database          — Migrations and seeders
/frontend          — Vue 3 + Vite + Tailwind frontend
/plugins           — Installed plugins (auto-discovered)
/public            — Web root (PHP built-in server)
/recipes           — Recipe definitions (YAML)
/storage           — Runtime files (SQLite DB, logs)
/tests             — Pest test suites
```

---

## Local Development

### 1. Install dependencies
```bash
composer install
cd frontend && npm install
```

### 2. Start the dev server
```bash
composer dev   # Starts PHP built-in server + Vite concurrently
```

### 3. Run tests
```bash
composer test          # Backend (Pest)
composer frontend:test  # Frontend unit (Vitest)
```

### 4. Database
- SQLite at `storage/database.sqlite`
- Run migrations: `bin/spora spora:install` (idempotent; safe to re-run)
- Seed: `bin/spora db:seed` (skips itself if users/agents already exist)
- Reset: `php bin/spora db:reset --force` (wipes the file or drops + recreates the MySQL DB; without `--force` it prompts)

---

## Docker Deployment

Docker configuration is in the `docker/` subfolder:

```
docker/
├── docker-compose.yml    # spora + mariadb (no separate Mercure)
├── Dockerfile
├── frankenphp.conf       # FrankenPHP config with native Mercure hub
├── supervisord.conf
└── .env.local.example    # Template — copy to .env.local and configure
```

Key differences from local dev:
- `SPORA_DB_HOST=mariadb` (Docker service name, not `127.0.0.1`)
- `SPORA_MERCURE_URL=http://localhost/.well-known/mercure` (FrankenPHP native)

Start: `docker compose -f docker/docker-compose.yml up`

---

## How to Add a Tool

1. **Create the tool class** in `app/Tools/`:
   ```php
   final class MyTool implements ToolInterface
   {
       public function name(): string { return 'my_tool'; }
       public function description(): string { return 'Does something'; }
       public function execute(array $params): ToolResult { ... }
   }
   ```

2. **Register it** via `ToolConfigService` or a plugin's `register()` hook.

3. **Add tests** in `tests/` using Pest.

4. **Document** the tool parameters and behavior — tools are user-facing in the agent config UI.

For plugin-based tools, place the class in your plugin directory and use the `PluginLoader` hook system.

> **Full tool system docs:** Naming conventions, `#[Tool]` attribute, `#[ToolSetting]`, `#[ToolParameter]`, `InputToolInterface` vs `OutputToolInterface`, and the settings key convention are in [docs/06_tools.md](docs/06_tools.md).

---

## Feature Overview

For a complete list of what's implemented, see the [Documentation Index](docs/00_index.md).

Key areas:
- **Orchestrator loop, config priority, plugin system, recipes** → [docs/01_architecture.md](docs/01_architecture.md)
- **Database schema and migrations** → [docs/02_schema.md](docs/02_schema.md)
- **REST API reference** → [docs/04_api.md](docs/04_api.md)
- **LLM drivers** → [docs/05_drivers.md](docs/05_drivers.md)
- **Tool system and settings** → [docs/06_tools.md](docs/06_tools.md)
- **Plugin system** → [docs/07_plugins.md](docs/07_plugins.md)
- **Async workers and deployment** → [docs/11_agent_loop_async.md](docs/11_agent_loop_async.md)
- **Frontend architecture** → [docs/09_frontend.md](docs/09_frontend.md)

## Backlog

See [docs/backlog.md](docs/backlog.md) for detailed descriptions, implementation notes, and dependencies.
