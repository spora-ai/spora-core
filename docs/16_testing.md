# Testing in Spora

---

## Backend (Pest)

```bash
composer test              # Run all tests
composer test:coverage     # With coverage
./vendor/bin/pest --filter="pattern"  # Filter tests
```

### Structure

```
tests/
├── Unit/
├── Feature/
└── TestCase.php
```

---

## Frontend (Vitest)

```bash
cd frontend
npm test              # Run all
npm run test:watch    # Watch mode
```

### Structure

```
frontend/tests/
├── components/
├── composables/
├── stores/
├── pages/
└── setup.ts
```

---

## Static Analysis

```bash
composer analyse   # PHPStan level 8
cd frontend && npm run lint   # ESLint + types
```

---

## CI

All PRs must pass: `composer analyse && composer test && cd frontend && npm run lint && npm test`
