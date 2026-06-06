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

---

## Coverage Policy (SonarQube)

- **Per-PR new-code coverage gate: `new_coverage >= 80%`.** This is the SonarQube quality gate — the "new code" window is the diff of the PR, not the whole repo. Legacy untouched files are excluded from the gate.
- **Whole-repo coverage** is reported as a secondary signal but is not part of the gate.
- For the frontend, lcov is uploaded to SonarQube from `frontend/coverage/lcov.info` (already wired in `sonar-project.properties`).
- Phase 5 raised whole-repo coverage from ~44% → ~67% via (a) extracting sub-components from the largest SFCs (`TaskChatPage.vue`, `AgentSettingsPage.vue`, `SharedScheduleEditor.vue`) and (b) adding page/admin/memory SFC tests. See `docs/09_frontend.md` for the per-phase breakdown.

## Component test patterns

The frontend tests follow these conventions (see `frontend/tests/components/agent/AgentToolConfigModal.spec.ts` and the ScheduleEditor folder for reference):

- **Modal stubs** — wrap the real `Modal.vue` with a stub that uses `v-if="modelValue"` and renders named slots (`<slot name="footer" />`) so action buttons are still findable by the test.
- **Sub-component stubs** — register via `global: { stubs: { Foo: { template: '...' } } }` and use a stable class (e.g. `class="foo-stub"`) so the assertion targets the stub, not the real component.
- **Composable mocks** — for components that own their own state via `useFoo()`, pass the state as props and the actions as `@event` emits. The composable lives in the parent (page) so the child stays purely presentational and testable in isolation.
- **Store mocks** — Pinia auto-unwraps refs accessed via store properties, so mocks must expose `get foo() { return fooRef.value }` rather than the ref itself, otherwise `taskStore.activeTask` returns the ref instead of the value.
