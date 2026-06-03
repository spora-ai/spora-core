# Spora — Documentation Index

## Core Internals

| Doc | Description |
|---|---|
| [01_architecture.md](01_architecture.md) | System overview: config priority, orchestrator loop, plugin system, recipes, database strategy |
| [02_schema.md](02_schema.md) | Database tables, column types, versioning model, migration conventions |
| [03_interfaces.md](03_interfaces.md) | PHP interface contracts — intent, rules, and implementation guidance |

## Extending Spora

| Doc | Description |
|---|---|
| [05_drivers.md](05_drivers.md) | LLM driver system — `LLMDriverConfigInterface`, `LLMDriverConfiguration` model, `DriverFactory` |
| [06_tools.md](06_tools.md) | Tool settings key convention, `#[ToolSetting]` architecture, core key reference |
| [07_plugins.md](07_plugins.md) | Plugin manifest, auto-discovery, bundled deps, recipes, contributing tools/drivers |

## Operations

| Doc | Description |
|---|---|
| [04_api.md](04_api.md) | REST API reference — endpoints, request/response envelopes, auth |
| [08_logging.md](08_logging.md) | Log levels, PII policy, what gets logged and where, best practices |
| [10_error_handling.md](10_error_handling.md) | Error codes, exception handling, toast notification system |
| [11_agent_loop_async.md](11_agent_loop_async.md) | Worker modes (`sync`/`worker`), tick structure, Mercure SSE |
| [12_worker_deployment.md](12_worker_deployment.md) | Deployment guide for cron and daemon modes, environment variables |
| [13_installation.md](13_installation.md) | Installing Spora — requirements, Docker, local dev, verification |
| [17_env_vars.md](17_env_vars.md) | Canonical reference for every `SPORA_*` environment variable |
| [15_security.md](15_security.md) | Security overview — credential encryption, API auth, rate limiting, plugin risk |

## Frontend & UI

| Doc | Description |
|---|---|
| [09_frontend.md](09_frontend.md) | Frontend stack, pages, stores, API design, dark mode, realtime updates |

---

## Appendix

| Doc | Description |
|---|---|
| [14_code_documentation.md](14_code_documentation.md) | Code comment standards — what to delete, keep, and add (docblocks) |
| [16_testing.md](16_testing.md) | Testing how-to — Pest (PHP), Vitest (frontend), Playwright (E2E) |
| [backlog.md](backlog.md) | Feature backlog (prioritized) |
