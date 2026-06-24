---
name: spora-plan
description: Plan feature implementations with backend and frontend changes, tests, and quality checks. Use when the user wants to plan a feature or create an implementation plan.
---

# Planning Skill

## 1. Understand the Feature
- Clarify goal and expected outcome
- Identify scope (frontend, backend, or both)
- Note constraints and requirements
- Ask the user clarifying questions about architectural decisions or missing details before finalizing the plan

## 2. Plan Backend
- **Framework**: Standalone PHP (self-built micro-framework). No full stack Laravel/Symfony.
- **Database**: New models/tables? Migrations needed? (Use Eloquent strictly, no DB calls in constructors)
- **Architecture rules**: Ensure `declare(strict_types=1)` and `final` classes. Plan DI for `php-di/php-di`.
- **API**: REST contracts (fast-route method/path, request/response validation).
- **Agent Tools/Plugins**: If adding a new Tool, follow the `app/Tools/` pattern and register via `ToolConfigService`.
- **Security**: Permissions, access controls, edge cases
- **Logic**: Services/classes to modify, follow existing patterns
- **Tests**: Unit tests for services, feature tests for API

## 3. Plan Frontend
- **UI & UX**: Components, responsive design, accessibility
- **Frontend Architecture**: Reusability and maintainability, move logic to typescript files, build reusable components
- **State**: Stores, API integration, data refresh on mutations
- **Tests**: Unit tests for TypeScript logic, component tests

## 4. Implementation Order
Build in parallel whereever possible. Add to the plan, which features can be implemented in which order and what can be built in parallel.

## 5. Plan Review
Before sending the plan to the user, review it from:
- Backend (db, API, tests)
- Frontend (UI, state, tests)
- Security (permissions, edge cases, OWASP)
- QA (testing strategy, quality)
- Code documentation (see §7)
- SonarQube gate impact (see §6)

## 6. Quality Checklist
- PHP linting (`composer analyse`)
- PHP tests (`composer test`)
- Frontend linting (`npm run lint` in /frontend)
- Frontend tests (`npm test` in /frontend)
- Frontend builds (`npm run build` in /frontend)
- No hardcoded values (use env variables)
- Specific docs updated (e.g. `docs/04_api.md` for endpoints, `docs/06_tools.md` for tools)
- **SonarQube quality gate** — new code introduces no new bugs, vulnerabilities, code smells, or duplications. `new_coverage` for the PR must not regress.
- **SonarQube MCP pre-flight** — before declaring the plan shippable, query the gate + open issues via the sonarqube MCP and resolve anything CRITICAL/MAJOR in the planned files.
- **PR flow** — change goes through a feature branch + PR. Never direct to `main`; bypassing the PR flow breaks the `new_coverage` per-PR signal.

## 7. Code Documentation Standards

Per `docs/14_code_documentation.md`. Every plan must explicitly call out the comment intent for any new code:

- **DELETE** — visual separators (`// ──` and `// -----`); comments that restate what the code does.
- **KEEP** — comments that explain *why*: security rationale, non-obvious business logic, API/interface contracts, cross-cutting invariants.
- **ADD** — docblocks for PHP attributes (with usage example) in `app/Tools/Attributes/`; JSDoc for stores and composables in `frontend/src/stores/` and `frontend/src/composables/`.
