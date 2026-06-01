# Contributing to Spora

Contributions are welcome! Spora is still in early alpha, and there are many ways to help.

## Getting Started

1. Read the [architecture overview](docs/01_architecture.md) to understand how Spora works
2. Check the [plugin system docs](docs/07_plugins.md) if you're interested in extending Spora
3. Look at the [open issues](https://github.com/spora-ai/Spora/issues) for things to work on

## Development Setup

See the [Installation Guide](docs/13_installation.md) for full setup instructions.

## Coding Standards

### Backend (PHP)

- **Static Analysis:** `composer analyse` — must pass (PHPStan level configured in `phpstan.neon`)
- **Tests:** `composer test` — must pass (Pest)
- **Formatting:** `composer format` — run before committing (PHP-CS-Fixer)

### Frontend (JavaScript/TypeScript)

- **Linting:** `cd frontend && npm run lint` — must pass (ESLint + TypeScript)
- **Tests:** `cd frontend && npm test` — must pass (Vitest)

## Testing Your Changes

```bash
# Backend
composer analyse && composer test

# Frontend
cd frontend && npm run lint && npm test

# Both together
composer analyse && composer test && cd frontend && npm run lint && npm test
```

## Pull Request Guidelines

- One focus per PR (one feature, one fix, one refactor)
- Include tests for new functionality
- Update documentation if behavior changes
- Follow the existing code style

