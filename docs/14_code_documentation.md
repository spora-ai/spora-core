# Code Documentation

Guidelines for writing meaningful comments that add value without adding noise.

## Principles

**1. Clarity over ceremony** — Comments should make code *easier* to understand. Decorative comments hinder more than help.

**2. Token efficiency** — Every comment burns tokens during AI processing. Make each word count.

**3. Focus on *why*, not *what*** — Comments serve other developers (and AI agents). The code already shows what it does; comments explain why.

**4. Self-documenting code first** — Well-named variables, functions, and classes reduce the need for comments.

---

## Delete — Visual Noise

These patterns add no information and should be removed:

### Visual Separators

```php
// ── Section Name ──────────────────────────────────────────────────────────────
```

```php
// -------------------------------------------------------------------------
// Private helpers
// -------------------------------------------------------------------------
```

```php
// ======== Section ========
```

**Why:** The code structure (classes, methods) provides all the organization needed. These dividers are redundant.

### Noise Comments

```php
// Get the user  ← DELETE - the code does this already
$user = $this->userService->find($id);

// Check if user is admin  ← DELETE - obvious from the condition
if ($user->isAdmin()) {
```

---

## Keep — Information Value

These patterns provide genuine value:

### Security Rationale

```php
// #1 SSRF guard: allowlist only http/https — blocks file://, ftp://, gopher://,
// cloud metadata endpoints (http://169.254.169.254), etc.
$scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');
```
*(from `app/Tools/ReadUrlTool.php`)*

```php
// Security Barrier: Allowed Recipients check
```
*(from `app/Tools/EmailTool.php`)*

**Why:** Security decisions often look arbitrary without context. The *why* prevents future developers from accidentally weakening protections.

### Complex Business Logic

```php
// Seed schema defaults if no global config AND no agent override exists
$globalSettings = $this->toolConfig->getGlobalSettings($toolClass);
```
*(from `app/Services/AgentService.php`)*

**Why:** Non-obvious algorithms or business rules need explanation.

### API Contracts

```php
/**
 * Send a chat completion request to the LLM and return the normalized response.
 * Intentionally synchronous — async behaviour is managed at the Messenger layer.
 *
 * @throws Exceptions\LLMProviderException   Non-recoverable API error.
 * @throws Exceptions\LLMRateLimitException  HTTP 429; caller should back off.
 */
public function complete(LLMRequest $request): LLMResponse;
```
*(from `app/Drivers/LLMDriverInterface.php`)*

**Why:** Interface contracts may not convey runtime behavior.

### Cross-cutting Concerns

```php
/**
 * Singleton. Registered via PHP-DI factory.
 * Loads master key once at construction. Fails immediately if key is invalid.
 */
final class SecurityManager implements SecurityManagerInterface
```
*(from `app/Core/SecurityManager.php`)*

```php
/**
 * The ONLY class permitted to read or write tool_configurations.settings
 * and agent_tool_overrides.settings columns.
 *
 * The Eloquent models have a guard accessor that throws LogicException on direct
 * `settings` access — all reads/writes must funnel through this service.
 */
class ToolConfigService
```
*(from `app/Services/ToolConfigService.php`)*

**Why:** Architectural decisions and invariants that affect the entire codebase.

---

## Add — Missing Documentation

### Attributes (PHP)

All attributes in `app/Tools/Attributes/` should have docblocks with a usage example:

```php
/**
 * Marks a class as a Tool the agent can invoke.
 *
 * Usage:
 *   #[Tool(name: 'my_tool', description: 'Does something useful')]
 *   final class MyTool implements ToolInterface { ... }
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Tool { ... }
```

### Module-level Documentation (JS/Vue)

Stores and composables should have JSDoc:

```typescript
/**
 * Manages authentication: session init, login, logout, registration,
 * password/email changes, and CSRF token handling.
 */
export const useAuthStore = defineStore('auth', () => { ... });
```
*(from `frontend/src/stores/auth.ts`)*

---

## Quick Reference

| Situation | Action |
|-----------|--------|
| Decorative `// ──` or `// -----` | DELETE |
| Restates what code does | DELETE |
| Explains *why* a guard exists | KEEP |
| Non-obvious algorithm | KEEP |
| Interface/trait purpose | KEEP |
| Attribute usage | ADD docblock |
| Store/composable purpose | ADD JSDoc |

### Decision Tree

1. **Can the code express this?** If yes, refactor the code instead.
2. **Does this explain *why*, not *what*?** If yes, keep it.
3. **Will a developer (or AI) be confused without this?** If yes, keep or add it.
4. **Is this purely decorative or redundant?** If yes, delete it.

---

## See Also

- [Martin Fowler: CodeAsDocumentation](https://martinfowler.com/bliki/CodeAsDocumentation.html)
- [Self-Documenting Code](https://anthonysciamanna.com/2014/04/05/self-documenting-code-and-meaningful-comments.html)
