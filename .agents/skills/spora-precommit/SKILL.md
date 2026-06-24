---
name: spora-precommit
description: Final review before a commit. Runs tests, linters, builds, checks recent changes, reviews against Spora's architecture, and drafts a commit message if no major issues exist. Use when the user wants to check their code before committing.
---

# Spora Pre-Commit Skill

Follow these steps strictly to ensure code quality and adherence to Spora's architecture before a commit.

## 1. Review Recent Changes
- Check the current changes in the repository (staged and unstaged) to understand what is about to be committed.
- Identify the scope of the changes (Backend, Frontend, Tools, Plugins, Docs).

## 2. Run Quality Checks
Execute the following commands in the terminal. Wait for each command to finish successfully before proceeding to the next:
1. **PHP Linting**: `composer analyse`
2. **PHP Tests**: `composer test`
3. **PHP Code Style Fix**: `composer format`
3. **Frontend Linting**: `cd frontend && npm run lint`
4. **Frontend Tests**: `cd frontend && npm run test`
5. **Frontend Build**: `cd frontend && npm run build`
6. **SonarQube (MCP)**: query the quality gate + open issues for this PR via the sonarqube MCP. If the gate isn't OK or there are CRITICAL/MAJOR issues in your changed files, stop. Use `list_pull_requests` to find the PR key — never pass a git branch name.

*Note: If any of these checks fail, **stop the process immediately**. Report the errors to the user and suggest fixes. Do not proceed to draft a commit message.*

## 3. Architecture & Security Review
Review the modified code against Spora's core rules (from `AGENTS.md`):
- **Backend Rules**: Verify `declare(strict_types=1)` is present, classes are `final` where applicable, strict Eloquent usage, no database calls in constructors, and correct DI via `php-di/php-di`.
- **Frontend Rules**: Verify UI components, API integrations, and state management logic.
- **Security Check**: Verify routes and endpoints have proper access controls. Ensure no secrets/credentials are hardcoded.
- **Tools/Plugins**: If modifying tools, assure it implements `name()`, `description()`, and `execute()` and is registered correctly.

## 3b. Code Comments Review
Review the diff against the comment standards in `docs/14_code_documentation.md`. Aim for *clarity over ceremony* — comments should explain *why*, not *what*.

**DELETE — visual noise:**
- Decorative section separators (e.g. `// ── Section ──`, `// -----`, `// ========`)
- Comments that restate what the code does (e.g. `// Get the user` above `$user = ...`)

**KEEP — genuine value:**
- *Security rationale* — why a guard exists (e.g. SSRF allowlist, RFC compliance)
- *Non-obvious business logic or algorithms* — diff strategies, conditional seeding, etc.
- *API contracts* — sync/async behavior, layer responsibilities on interfaces/traits
- *Cross-cutting concerns* — singletons, DI registration, the single permitted reader/writer of a column

**ADD — missing documentation:**
- Docblocks on all attributes in `app/Tools/Attributes/` with a usage example
- JSDoc on Vue stores and composables describing their responsibility

## 4. Report & Draft Commit Message
- **If major issues or check failures exist:** Inform the user and suggest fixes. Do NOT output a commit message draft.
- **If everything passes:** Provide a final review summary and create a commit message draft. If the current branch is `main`, suggest a branch name as well. Do not commit or create the branch.

### Commit Message Format
Output the commit message draft as a markdown snippet inside a bash/text codeblock so the user can easily copy it. Follow the Conventional Commits format:

```text
type(scope): concise description

- Bullet point details of what changed
- Another bullet point if necessary
```
*Common types: `feat`, `fix`, `refactor`, `docs`, `chore`, `test`, `perf`.*