<!-- Spora Logo -->
<p align="center">
  <img src="assets/logo.svg" alt="Spora" width="320">
</p>

<p align="center">

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Version](https://img.shields.io/badge/version-0.1.0--alpha-red?style=flat-square)](https://github.com/spora-ai/spora-core)
[![PHP 8.4+](https://img.shields.io/badge/PHP-8.4%2B-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net/releases/8.4/en.php)
[![CI](https://img.shields.io/github/actions/workflow/status/spora-ai/spora-core/ci.yml?style=flat-square&branch=main&label=CI)](https://github.com/spora-ai/spora-core/actions/workflows/ci.yml)

</p>

---

## What is Spora?

A self-hosted AI agent orchestration platform in PHP 8.4+. Define agents, give them tools, and Spora runs the loop — tick-based, LLM-agnostic, with human-in-the-loop approval for write operations. Zero-config (SQLite out of the box, MySQL for production), portable (any PHP host, Docker), extensible via plugins, BYO model (any OpenAI- or Anthropic-compatible endpoint), and yours to keep — no vendor lock-in, no telemetry.

## Install (operators)

If you want to **run** Spora, install the operator skeleton — it pulls in `spora-core`, the Vue admin UI, and the installer:

```bash
composer create-project spora-ai/spora
```

See [spora-ai/spora](https://github.com/spora-ai/spora) for full setup, Docker deployment, and production guidance.

## Develop (contributors)

If you want to **work on** `spora-core` itself:

```bash
composer install         # dependencies
composer test           # Pest
composer analyse        # PHPStan
composer format         # PHP-CS-Fixer
composer openapi        # regenerate openapi.json
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for coding standards and PR conventions.

## Documentation

Full documentation lives in **[spora-ai/spora-docs](https://github.com/spora-ai/spora-docs)**: architecture, API reference, LLM drivers, plugin system, tool development, frontend, deployment, security.

## License

MIT — see [LICENSE](LICENSE).

---

> **Early alpha.** Functional and runs in production for personal use, but the API, schema, and plugin system may change in breaking ways before v1.0.
