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
