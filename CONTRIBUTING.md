# Contributing to Spora

Contributions are welcome! Spora is still in early alpha, and there are many ways to help.

This guide covers the **PHP framework** (`spora-core`). For the Vue admin UI, see [`spora-frontend`](https://github.com/spora-ai/spora-frontend).

## Getting Started

1. Read the [architecture overview](https://docs.spora-ai.com/reference/concepts/architecture) to understand how Spora works
2. Check the [plugin system docs](https://docs.spora-ai.com/reference/concepts/plugins-system) if you're interested in extending Spora
3. Look at the [open issues](https://github.com/spora-ai/spora/issues) for things to work on

## Development Setup

See the [Installation Guide](https://docs.spora-ai.com/start/operators/install) for full setup instructions.

## Coding Standards

### Backend (PHP)

- **Static Analysis:** `composer analyse` — must pass (PHPStan level configured in `phpstan.neon`)
- **Tests:** `composer test:parallel` — must pass (Pest, parallel mode)
- **Formatting:** `composer format` — run before committing (PHP-CS-Fixer)

## Testing Your Changes

```bash
# Standard verification — ~22s on a typical PR
composer analyse && composer test:parallel

# Debug a single failing test (serial mode)
./vendor/bin/pest tests/Unit/Agents/OrchestratorTest.php
```

### Running tests in parallel

`composer test:parallel` runs Pest via `--parallel --processes=auto --max-batch-size=50`. Each worker is an isolated PHP process with its own in-memory SQLite, so cross-test state isolation is the same as serial mode.

- **Override process count** for smaller CI runners:
  ```bash
  composer test:parallel -- --processes=4
  ```
- **`--max-batch-size=50`** recycles workers every 50 tests to reclaim memory. Tune down (`--max-batch-size=30`) on memory-constrained runners or up on fast ones.
- The first parallel run may be slightly slower because workers spawn; subsequent tests reuse workers.
- Memory limit per worker is set in `phpunit.xml` (`<ini name="memory_limit" value="1G"/>`). If you see "Premature end of PHP process" errors, lower the parallel `--processes` count or the per-worker memory limit.

## Pull Request Guidelines

- One focus per PR (one feature, one fix, one refactor)
- Include tests for new functionality
- Update documentation if behavior changes
- Follow the existing code style
