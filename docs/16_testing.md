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
├── Pest.php            # Pest bootstrap + shared helpers
├── Unit/               # Unit tests (mirrors app/ subpackages)
├── Feature/            # HTTP/controller integration tests
└── Fixtures/           # Test fixtures (plugins, sample data)
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
├── api/                # HTTP client tests
├── apps/               # App-shell tests
├── components/         # Vue component tests
│   ├── agent/
│   └── layout/
├── composables/        # useFoo() composable tests
├── pages/              # Page-level tests
├── stores/             # Pinia store tests
├── unit/               # Misc isolated unit tests
├── utils/              # Pure utility tests
└── setup.ts            # Vitest global setup (mocks browser APIs)
```

---

## Static Analysis

```bash
composer analyse   # PHPStan (level 5, with Larastan + Mockery extensions)
cd frontend && npm run lint   # ESLint (flat config) + vue-tsc
```

---

## CI

All PRs must pass: `composer analyse && composer test && cd frontend && npm run lint && npm test`
