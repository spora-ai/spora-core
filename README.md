<!-- Spora Logo -->
<p align="center">
  <img src="public/logo.svg" alt="Spora" width="200">
</p>

<!-- Badges -->
<p align="center">

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Version](https://img.shields.io/badge/version-0.1.0--alpha-red?style=flat-square)](https://github.com/fabeat/spora)
[![PHP 8.4+](https://img.shields.io/badge/PHP-8.4%2B-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net/releases/8.4/en.php)
[![Node 18+](https://img.shields.io/badge/Node-18%2B-339933?style=flat-square&logo=node&logoColor=white)](https://nodejs.org)

</p>

---

## ⚠️ Early Alpha

> **Spora v0.1.0 is early alpha software.** It is functional and runs in production for personal use, but the API, database schema, and plugin system may change in breaking ways before v1.0. Use at your own risk.

---

## What is Spora?

Spora is a **self-hosted AI agent orchestration platform** built in PHP 8.4+.

It is designed to be:

- **Zero-config** — works out of the box with sensible defaults (SQLite, no server required)
- **Portable** — runs on any PHP 8.4+ environment, from a laptop to a shared cPanel/FTP host
- **Extensible** — a WordPress-like plugin system lets you add tools, drivers, and agent behaviors

You define agents, give them tools, and Spora handles the execution loop — tick-based, stateless, with human-in-the-loop approval for write operations.

---

## Key Features

- **Tick-based agent execution** — stateless, LLM-agnostic orchestration loop (Think → Act)
- **Built-in tools** — email, calendar, web search, calculator, web scraping, and more
- **Plugin system** — drop a folder with a `Plugin.php` file, auto-discovered at boot
- **LLM drivers** — OpenAI-compatible and Anthropic-compatible drivers via `DriverFactory`
- **Recipes** — define agent prompts in YAML/JSON, scanned at runtime from `recipes/`
- **Human-in-the-loop** — write tools (email, posting, etc.) require approval before execution

---

## Requirements

| Requirement | Minimum |
|-------------|---------|
| PHP | 8.4+ |
| Node.js | 18+ |
| Database | SQLite (dev) / MySQL 8+ (prod) |

See [Installation Guide](docs/13_installation.md) for full details.

---

## Installation

Detailed installation instructions are in the [Installation Guide](docs/13_installation.md).

---

## Documentation

Full documentation is in the [`docs/`](docs/) directory:

| Document | Coverage |
|----------|----------|
| [Architecture](docs/01_architecture.md) | System overview, config, orchestrator loop, plugin system |
| [API Reference](docs/04_api.md) | REST API endpoints |
| [Plugin System](docs/07_plugins.md) | How to write plugins |
| [Tool Development](docs/06_tools.md) | How to build tools |
| [LLM Drivers](docs/05_drivers.md) | Driver architecture |
| [Worker Modes](docs/11_agent_loop_async.md) | sync, cron, and daemon deployment |
| [Testing](docs/16_testing.md) | How to test Spora |

---

## Contributing

Contributions are welcome! See [CONTRIBUTING.md](CONTRIBUTING.md) for setup instructions and coding standards.

---

## License

Spora is open source software under the MIT License. See the [LICENSE](LICENSE) file for full terms.
