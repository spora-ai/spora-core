<!-- Spora Logo -->
<p align="center">
  <img src="public/logo.png" alt="Spora">
</p>

<!-- Badges -->
<p align="center">

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Version](https://img.shields.io/badge/version-0.1.0--alpha-red?style=flat-square)](https://github.com/spora-ai/Spora)
[![PHP 8.4+](https://img.shields.io/badge/PHP-8.4%2B-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net/releases/8.4/en.php)

</p>

---

## ⚠️ Early Alpha

> **Spora v0.1.0 is early alpha software.** It is functional and runs in production for personal use, but the API, database schema, and plugin system may change in breaking ways before v1.0. Use at your own risk.

---

## What is Spora?

Spora is a **self-hosted AI agent orchestration platform** built in PHP 8.4+.

It is designed to be:

- **Zero-config** — works out of the box with sensible defaults (SQLite for small-scale use, MySQL/MariaDB for team or production use)
- **Portable** — runs on any PHP 8.4+ environment, from a laptop to a shared cPanel/FTP host or as a Docker Container
- **Extensible** — a WordPress-like plugin system lets you add tools, drivers, and agent behaviors
- **Bring your own model** — connect any OpenAI- or Anthropic-compatible endpoint, from hosted APIs to local Ollama and LM Studio, and switch providers at any time
- **Yours to keep** — every tool call, prompt, and result stays on your infrastructure; no vendor lock-in, no third-party telemetry

You define agents, give them tools, and Spora handles the execution loop — tick-based, stateless, with human-in-the-loop approval for write operations.

---

## Key Features

- **Tick-based agent execution** — stateless, LLM-agnostic orchestration loop (Think → Act)
- **Built-in tools** — email, calendar, web search, calculator, web scraping, and more
- **Plugin system** — drop a plugin folder with a `plugin.json` manifest, auto-discovered at boot
- **LLM drivers** — OpenAI-compatible and Anthropic-compatible drivers via `DriverFactory`
- **Human-in-the-loop** — write tools (email, posting, etc.) require approval before execution

---

## Requirements

| Requirement | Minimum | Notes |
|-------------|---------|-------|
| PHP | 8.4+ | Required |
| Node.js | 20+ | Only for frontend builds (Vite 6 / ESLint 10 require ≥ 20) |
| Database | SQLite (dev) / MySQL 8+ (prod) | |

---

## Installation

Step-by-step setup — including `composer install`, frontend build, and the optional Docker stack — is in the [Installation Guide](docs/13_installation.md).

---

## Documentation

Full documentation is in the [`docs/`](docs/) directory:

| Document | Coverage |
|----------|----------|
| [Index](docs/00_index.md) | Documentation index and feature overview |
| [Architecture](docs/01_architecture.md) | System overview, config, orchestrator loop, plugin system |
| [Database Schema](docs/02_schema.md) | Tables, columns, migrations |
| [Interfaces](docs/03_interfaces.md) | PHP interfaces and contracts |
| [API Reference](docs/04_api.md) | REST API endpoints |
| [LLM Drivers](docs/05_drivers.md) | Driver architecture |
| [Tool Development](docs/06_tools.md) | How to build tools |
| [Plugin System](docs/07_plugins.md) | How to write plugins |
| [Logging](docs/08_logging.md) | Logging conventions and PII policy |
| [Frontend](docs/09_frontend.md) | Vue 3 app, stores, polling, SSE |
| [Error Handling](docs/10_error_handling.md) | Error envelope and code registry |
| [Worker & Async](docs/11_agent_loop_async.md) | sync mode and `worker:run` deployment |
| [Worker Deployment](docs/12_worker_deployment.md) | Docker, systemd, supervisord |
| [Installation](docs/13_installation.md) | Setup requirements and steps |
| [Code Documentation](docs/14_code_documentation.md) | Comment standards |
| [Security](docs/15_security.md) | Encryption, CSRF, rate limits |
| [Testing](docs/16_testing.md) | Backend and frontend test setup |

---

## Contributing

Contributions are welcome! See [CONTRIBUTING.md](CONTRIBUTING.md) for setup instructions and coding standards.

---

## License

Spora is open source software under the MIT License. See the [LICENSE](LICENSE) file for full terms.
