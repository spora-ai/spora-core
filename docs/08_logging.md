# Logging in Spora

Spora uses a [PSR-3](https://www.php-fig.org/psr/psr-3/) logger (Monolog) injected throughout the system. This document covers what gets logged, where, at which levels, and how to handle PII safely.

---

## Configuration

Set the `SPORA_LOG_LEVEL` environment variable (in `.env`) to control verbosity:

```
SPORA_LOG_LEVEL=debug    # Full traces, including tool arguments (⚠ may contain PII)
SPORA_LOG_LEVEL=info     # Informational operational events
SPORA_LOG_LEVEL=warning  # Default — only unexpected conditions
SPORA_LOG_LEVEL=error    # Only failures and exceptions
```

The default is `warning`. Messages below the configured level are silently discarded — no performance cost.

---

## What Gets Logged

### Orchestrator — Tool Dispatch (`app/Agents/Orchestrator.php`)

Every call to `safeExecute()` (the single chokepoint for all tool execution) produces structured log entries.

| Event | Level | Fields |
|---|---|---|
| Tool is about to be called | `DEBUG` | `tool`, `agent_id`, `task_id`, `arguments` |
| Tool returned `success=false` | `ERROR` | `tool`, `agent_id`, `task_id`, `content` |
| Tool threw an unhandled exception | `ERROR` | `tool`, `agent_id`, `task_id`, `exception_class`, `message` |

### LLM Driver HTTP Requests (`app/Drivers/`)

Every HTTP request to an LLM provider (Anthropic, OpenAI-compatible) is logged via Monolog's HTTP client integration at the level configured by `SPORA_LOG_LEVEL`. Request/response bodies are included at `DEBUG`.

---

## PII Policy

**Tool arguments are logged only at `DEBUG` level and never at `ERROR`.**

Tool arguments frequently contain personally identifiable information (PII):

- Email addresses, message subjects, and body content (`send_email`, `read_email`)
- Search queries that may include names, medical terms, or private intent (`tavily_search`, `serper_search`)
- Calendar event titles and descriptions (`calendar_list_events`)
- URL contents fetched on behalf of a user (`read_url`)

### Rules

| Situation | Arguments logged? | Level |
|---|---|---|
| Normal tool dispatch | **Yes** | `DEBUG` only |
| Tool returned `success=false` | **No** | `ERROR` |
| Tool threw an exception | **No** | `ERROR` |

The `content` field on a failed `ToolResult` is safe to log at `ERROR` because it is produced by Spora's own tool code (e.g. `"Tavily API key is not configured"`) and does not echo back user input.

### Before enabling DEBUG logging in production

1. Ensure log files are stored with restricted filesystem permissions (`chmod 640`).
2. If logs are shipped to a third-party aggregator (Datadog, Sentry, Papertrail), verify your data-processing agreement covers PII in log data.
3. Consider your data-retention obligations. DEBUG logs should be rotated aggressively (e.g. 7 days).
4. Never enable `SPORA_LOG_LEVEL=debug` on multi-tenant deployments where log access is shared across tenants.

---

## Adding Logging to a New Tool

Tool classes receive an optional `?LoggerInterface $logger` via constructor injection. Use it for tool-internal errors that are not already captured by the Orchestrator's `safeExecute()` wrapper.

```php
final class MyTool implements InputToolInterface
{
    public function __construct(
        private readonly ToolConfigService $configService,
        private readonly ?LoggerInterface  $logger = null,  // nullable — always optional
    ) {}

    public function execute(array $arguments, int $agentId): ToolResult
    {
        try {
            // ... do work ...
        } catch (Throwable $e) {
            // Log internal errors at the tool level for diagnostics.
            // Do NOT include $arguments here — they may contain PII.
            $this->logger?->error('MyTool: upstream call failed', [
                'exception_class' => get_class($e),
                'message'         => $e->getMessage(),
            ]);
            return new ToolResult(false, 'Service unavailable: ' . $e->getMessage());
        }
    }
}
```

The Orchestrator's `safeExecute()` will also log the `ToolResult(false, ...)` at `ERROR` level, so you get two complementary log entries: one with the tool's internal context (from the tool itself) and one with the orchestration context (`tool`, `agent_id`, `task_id`).

**Never log `$arguments` at `WARNING` or above.** If you need argument context for debugging, log at `DEBUG`:

```php
$this->logger?->debug('MyTool dispatch context', [
    'agent_id'  => $agentId,
    'arguments' => $arguments,  // ⚠ PII risk — DEBUG only
]);
```

---

## Best Practices

**Use structured context arrays, not string interpolation.**

```php
// Good
$this->logger?->error('API call failed', ['status' => $code, 'tool' => 'tavily_search']);

// Bad — unstructured, hard to query in log aggregators
$this->logger?->error("API call to Tavily failed with status {$code}");
```

**Use the right level.**

| Level | When to use |
|---|---|
| `debug` | Full execution traces, argument dumps, HTTP request/response bodies. Only useful during active development or debugging a specific incident. |
| `info` | Notable operational events (agent started, task completed). Currently unused in Spora core. |
| `warning` | Unexpected but recoverable situations that don't require immediate action. |
| `error` | Failures that prevent a tool or request from completing. Always logged — even in production. |
| `critical` / `alert` / `emergency` | Reserved for system-level failures (database unreachable, out of memory). Not used by tools. |

**Never swallow exceptions silently.** If you catch a `Throwable`, either return a `ToolResult(false, ...)` so the Orchestrator logs it, or re-throw. Logging and swallowing together is acceptable only when you have a genuine fallback:

```php
} catch (Throwable $e) {
    $this->logger?->error('Cache miss — falling back to live fetch', ['message' => $e->getMessage()]);
    // Then do the fallback, not just return silently.
}
```

**Match log levels to operator expectations.** Operators watching `ERROR` logs expect to be paged. A tool returning `ToolResult(false, 'API key not configured')` is a legitimate `error` — the agent cannot complete its task. But a tool returning `ToolResult(false, 'No results found')` is a normal empty-result that should be `warning` or `debug` at most in the tool's own logger, even though the Orchestrator will still log it at `error` (because it received `success=false`).

Consider having your tool return `success=true` with a "no results found" message when that is an expected, non-failure outcome — this avoids a spurious `error` log entry at the Orchestrator level.
