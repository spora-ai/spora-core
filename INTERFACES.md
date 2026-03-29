# Spora: PHP Interface Contracts

**Version:** 2.0
**Status:** Binding Contract — do not modify without notifying all downstream agents
**Namespace Base:** `Spora\`
**PHP Minimum:** 8.1

---

## 1. PHP 8 Attributes for Tool Metadata

Applied to every concrete Tool class. The Orchestrator and UI read them via PHP Reflection at runtime.

```php
<?php

namespace Spora\Tools\Attributes;

use Attribute;

/**
 * Applied at class level. Provides the LLM-facing tool name and description.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Tool
{
    public function __construct(
        public readonly string $name,        // snake_case, e.g. "search_web"
        public readonly string $description, // Sent to LLM as function description
    ) {}
}

/**
 * Applied at class level on OutputToolInterface implementors.
 * Declares the class-level approval default.
 *
 * The Orchestrator checks this after intercepting an OutputTool call:
 *   1. Read agent_tools.auto_approve for this tool+agent (the per-agent override).
 *   2. If NULL (not explicitly set), fall back to this attribute's $requiresApproval.
 *   3. If true → pause, serialize AgentState, set PENDING_APPROVAL.
 *      If false → execute immediately without human review.
 *
 * Plugin authors set requiresApproval: false for low-risk output tools (e.g. a
 * self-notification tool where the agent owner is always the recipient).
 * The agent owner can still override this per-agent via the UI.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class OutputTool
{
    public function __construct(
        public readonly bool $requiresApproval = true,
    ) {}
}

/**
 * Applied zero-or-more times at class level (repeatable).
 * Each instance describes one parameter the tool accepts.
 * The Orchestrator collects these and builds the JSON Schema `parameters` block
 * for the LLM function-calling payload.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class ToolParameter
{
    public function __construct(
        public readonly string $name,
        /** JSON Schema primitive: "string"|"number"|"boolean"|"array"|"object" */
        public readonly string $type,
        public readonly string $description,
        public readonly bool   $required = true,
        public readonly mixed  $default  = null,
        /** @var list<string> Only used when type === "string" and values are constrained */
        public readonly array  $enum     = [],
    ) {}
}

/**
 * Applied zero-or-more times at class level (repeatable).
 * Describes a UI-configurable setting stored in tool_configurations or agent_tool_overrides.
 * Never sent directly to the LLM.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class ToolSetting
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        /** "text"|"password"|"select"|"toggle" */
        public readonly string $type,
        public readonly string $description = '',
        public readonly mixed  $default     = null,
        public readonly bool   $required    = false,
        /**
         * "global"  — can only be set in global tool configuration, cannot be overridden per-agent.
         *             Example: SMTP server hostname (shared infrastructure).
         * "agent"   — can be overridden per-agent via agent_tool_overrides.
         *             Example: API key (separate billing per agent), from-address.
         * Default: "agent"
         */
        public readonly string $scope   = 'agent',
        /** @var array<string, string> key => label pairs. Only used when type === "select". */
        public readonly array  $options = [],
    ) {}
}
```

---

## 2. `InputToolInterface`

Implemented by all read-only or generative tools. The Orchestrator executes these instantly without pausing or requesting human approval.

```php
<?php

namespace Spora\Tools;

use Spora\Tools\ValueObjects\ToolResult;

interface InputToolInterface
{
    /**
     * Execute the tool with the named arguments provided by the LLM.
     *
     * MUST NOT throw — all errors must be encoded in the returned ToolResult
     * so the LLM can reason about failures.
     *
     * @param  array<string, mixed> $arguments  Key-value pairs matching #[ToolParameter] names.
     * @return ToolResult
     */
    public function execute(array $arguments): ToolResult;

    /**
     * Return the JSON Schema "parameters" object for the LLM function-calling payload.
     * Implementations MAY use ToolSchemaBuilder::fromAttributes(static::class).
     *
     * @return array{
     *   type: "object",
     *   properties: array<string, array{type: string, description: string}>,
     *   required: list<string>
     * }
     */
    public function getParametersSchema(): array;
}
```

**Example concrete InputTool:**

```php
<?php

namespace Spora\Tools\Builtin;

use Spora\Tools\InputToolInterface;
use Spora\Tools\Attributes\{Tool, ToolParameter, ToolSetting};
use Spora\Tools\ValueObjects\ToolResult;

#[Tool(name: 'search_web', description: 'Search the web and return relevant results.')]
#[ToolParameter(name: 'query', type: 'string', description: 'The search query.')]
#[ToolParameter(name: 'max_results', type: 'number', description: 'Max results.', required: false, default: 5)]
#[ToolSetting(key: 'api_key', label: 'SerpAPI Key', type: 'password', required: true, scope: 'agent')]
final class SearchWebTool implements InputToolInterface
{
    public function execute(array $arguments): ToolResult { /* … */ }
    public function getParametersSchema(): array { /* … */ }
}
```

---

## 3. `OutputToolInterface`

Implemented by all write/real-world-action tools. The Orchestrator MUST NOT call `execute()` directly — it intercepts the call, resolves the approval requirement, then either:
- **Approval required** (`auto_approve` resolves to `false`): serializes `AgentState` as `PENDING_APPROVAL` and halts. `execute()` is called exclusively by `ApprovalResumeHandler` after human approval.
- **Auto-approved** (`auto_approve` resolves to `true`): calls `execute()` immediately, appends the result to history, and continues the loop.

Approval resolution order: `agent_tools.auto_approve` (per-agent) → `#[OutputTool(requiresApproval:)]` class default.

```php
<?php

namespace Spora\Tools;

use Spora\Tools\ValueObjects\ToolResult;

interface OutputToolInterface
{
    /**
     * Execute the tool ONLY after explicit human approval.
     * Called exclusively by ApprovalResumeHandler — never by the Orchestrator loop.
     *
     * MUST NOT throw — all errors encoded in ToolResult.
     *
     * @param  array<string, mixed> $arguments  Arguments confirmed (or edited) by the human.
     * @return ToolResult
     */
    public function execute(array $arguments): ToolResult;

    /**
     * Return a human-readable, markdown-safe description of what this tool WILL DO.
     * Displayed in the approval UI before the user approves or rejects.
     *
     * @param  array<string, mixed> $arguments  Arguments as proposed by the LLM.
     * @return string
     */
    public function describeAction(array $arguments): string;

    /**
     * @return array{
     *   type: "object",
     *   properties: array<string, array{type: string, description: string}>,
     *   required: list<string>
     * }
     */
    public function getParametersSchema(): array;
}
```

---

## 4. `OrchestratorInterface`

The contract for the agent loop. The concrete `Orchestrator` is a singleton registered in PHP-DI.

The Orchestrator drives a Task through its full lifecycle using four focused methods:
- `start()` — creates the Task and fires the first queue message. Returns immediately; no LLM call happens here.
- `tick()` — the stateless loop body, called once per queue message. Loads history, calls the LLM, and handles the response: runs an InputTool and re-dispatches itself, pauses for OutputTool approval, or marks the task complete/failed. Being stateless and short-lived is what makes Spora viable on shared hosting where long-running PHP processes are not available.
- `resume()` — re-enters the loop after a human approves a pending OutputTool call, executing the tool with confirmed (or edited) arguments before re-dispatching `tick()`.
- `reject()` — injects the rejection reason into history as a message the LLM can reason about, then re-dispatches `tick()` so the agent can choose an alternative action.

```php
<?php

namespace Spora\Agents;

use Spora\Models\Task;

interface OrchestratorInterface
{
    /**
     * @param  int    $agentId
     * @param  string $userPrompt  The user's initial instruction.
     * @param  int    $maxSteps    Hard iteration cap. Copied to Task at creation.
     * @return Task                The newly created Task (status: RUNNING).
     */
    public function start(int $agentId, string $userPrompt, int $maxSteps = 10): Task;

    /**
     * One iteration of the loop. Called by the Symfony Messenger handler.
     *
     * 1. Load Task + reconstruct message history from task_history.
     * 2. Load effective settings for all enabled tools via ToolConfigService::getEffectiveSettings().
     * 3. Build LLMRequest (Recipe system prompt + history + tool schemas).
     * 4. Call LLMDriverInterface::complete().
     * 5a. If LLM returns a tool call:
     *     - InputTool       → execute(), append result, increment step_count, re-dispatch.
     *     - OutputTool      → resolve approval (agent_tools.auto_approve ?? #[OutputTool(requiresApproval:)]):
     *         auto-approved     → execute(), append result, increment step_count, re-dispatch.
     *         requires approval → create tool_calls row (PENDING), serialize AgentState to tasks.pending_state,
     *                             set status PENDING_APPROVAL, halt.
     * 5b. LLM returns text → append to history, set status COMPLETED.
     * 5c. step_count >= max_steps → set status FAILED, failure_reason = "max_steps_exceeded".
     */
    public function tick(int $taskId): void;

    /**
     * @param  int                  $taskId
     * @param  array<string, mixed> $approvedArguments  Arguments confirmed (or edited) by the human.
     */
    public function resume(int $taskId, array $approvedArguments): void;

    /**
     * @param  int    $taskId
     * @param  string $reason  Surfaced to the LLM so it can choose an alternative action.
     */
    public function reject(int $taskId, string $reason): void;
}
```

---

## 5. `PluginInterface`

The entry point for any Spora plugin. Drop a folder into `plugins/` with a class implementing this interface — the Kernel discovers and boots it automatically on first request.

**Discovery:** At boot, `Kernel` scans each subdirectory of `plugins/` for a PHP file matching the directory name (e.g. `plugins/MyPlugin/MyPlugin.php`). If the class implements `PluginInterface`, it is instantiated and booted. No manual registration required — this is the "WordPress plugin" model.

**Boot sequence per plugin:**
1. If `plugins/MyPlugin/vendor/autoload.php` exists → `require_once` it automatically (third-party dependencies)
2. Call `autoload()` → register returned PSR-4 mappings for the plugin's own classes
3. Call `tools()`, `drivers()`, `recipePaths()` → register contributions
4. Call `register()` → apply arbitrary DI bindings

**File/library loading:**
- **Plugin's own classes:** declared via `autoload()` as PSR-4 namespace→path pairs. The Kernel registers these with the active Composer `ClassLoader` before any other plugin method is called.
- **Third-party dependencies:** plugin author runs `composer install` inside their plugin folder. The resulting `vendor/autoload.php` is required automatically by the Kernel (step 1 above). Plugins are fully self-contained — no assumption about the host environment.

**Dependency conflicts:**
PHP's autoloader is first-loaded-wins. If two plugins bundle the same package at different versions, or a plugin conflicts with a Spora core dependency, the first-registered autoloader silently wins and the other plugin may fail at runtime in hard-to-diagnose ways.

Mitigations:
- **Use `php-scoper`** (strongly recommended for plugins with third-party deps): prefixes all vendor namespaces so `GuzzleHttp\Client` becomes `MyPlugin\Deps\GuzzleHttp\Client`. Fully isolated, zero conflicts regardless of load order. This is the standard practice for WordPress plugins.
- **Prefer Spora core packages**: Spora publishes a list of bundled packages (see `composer.json`). Plugins that rely on these instead of bundling their own avoid the problem entirely.
- **Kernel conflict detection**: at boot, the Kernel checks PSR-4 namespace prefixes across all plugin `autoload()` declarations and core, and logs a `WARNING` for any collision. This does not resolve the conflict but surfaces it immediately rather than leaving a silent runtime trap.

**What plugins can contribute:**
- **Tools** — any `InputToolInterface` or `OutputToolInterface` implementors, registered via `tools()`
- **LLM Drivers** — new provider drivers, registered via `drivers()`
- **Recipes** — additional recipe files, registered via `recipePaths()`
- **Anything else** — arbitrary DI bindings, middleware, event listeners via `register()`

```php
<?php

namespace Spora\Plugins;

use DI\ContainerBuilder;

interface PluginInterface
{
    /**
     * Human-readable plugin name, shown in the UI and logs.
     */
    public function getName(): string;

    /**
     * PSR-4 autoload mappings for the plugin's own classes.
     * Registered by the Kernel with the active Composer ClassLoader before
     * any other plugin method is called.
     *
     * Example: ['MyVendor\\MyPlugin\\' => __DIR__ . '/src']
     *
     * Third-party dependencies are NOT declared here — ship a vendor/ directory
     * inside the plugin folder instead (run `composer install` in the plugin dir).
     * The Kernel auto-requires plugins/MyPlugin/vendor/autoload.php if present.
     *
     * @return array<string, string> namespace prefix => absolute path
     */
    public function autoload(): array;

    /**
     * Tool classes this plugin contributes to the Tool Registry.
     * Each class must implement InputToolInterface or OutputToolInterface
     * and carry the required #[Tool], #[ToolParameter], #[ToolSetting] attributes.
     *
     * @return array<class-string<\Spora\Tools\InputToolInterface|\Spora\Tools\OutputToolInterface>>
     */
    public function tools(): array;

    /**
     * LLM drivers this plugin contributes.
     * Keys are the llm_provider string stored in agents.llm_provider.
     * Values are the driver class (must implement LLMDriverInterface).
     *
     * Example: ['perplexity' => PerplexityDriver::class]
     *
     * @return array<string, class-string<\Spora\Drivers\LLMDriverInterface>>
     */
    public function drivers(): array;

    /**
     * Absolute paths to directories or individual files containing recipe definitions.
     * Spora merges these with the built-in /recipes/ directory.
     *
     * @return string[]
     */
    public function recipePaths(): array;

    /**
     * Register arbitrary DI bindings, middleware, or services.
     * Called after autoload(), tools(), drivers(), and recipePaths() have been processed.
     * Use for anything not covered by the declarative methods above.
     */
    public function register(ContainerBuilder $builder): void;
}
```

---

## 6. `LLMDriverInterface`

Adapter contract for LLM providers. Concrete implementations:

| Driver | `llm_provider` value | Notes |
|---|---|---|
| `OpenAICompatibleDriver` | `"openai_compatible"` | Default base URL `https://api.openai.com/v1`. Covers OpenAI, Groq, Ollama, Together AI, LM Studio, and any OpenAI-compatible endpoint via `llm_base_url`. |
| `AnthropicDriver` | `"anthropic"` | Anthropic Messages API. |
| `GeminiDriver` | `"gemini"` | Google Gemini API. |
| `MistralDriver` | `"mistral"` | Mistral AI API. |

```php
<?php

namespace Spora\Drivers;

use Spora\Drivers\ValueObjects\LLMRequest;
use Spora\Drivers\ValueObjects\LLMResponse;

interface LLMDriverInterface
{
    /**
     * Send a chat completion request to the LLM and return the normalized response.
     * Intentionally synchronous — async behaviour is managed at the Messenger layer.
     *
     * @throws \Spora\Drivers\Exceptions\LLMProviderException   Non-recoverable API error.
     * @throws \Spora\Drivers\Exceptions\LLMRateLimitException  HTTP 429; caller should back off.
     */
    public function complete(LLMRequest $request): LLMResponse;

    /** e.g. "openai" or "anthropic" */
    public function getProviderName(): string;

    /** e.g. "gpt-4o" or "claude-3-5-sonnet-20241022" */
    public function getModelName(): string;
}
```

---

## 7. Value Objects / DTOs

All value objects are `final readonly` classes (PHP 8.1+). Never persisted directly — serialization to/from DB is handled by Eloquent models and service classes.

### 6.1 `LLMRequest`

```php
<?php

namespace Spora\Drivers\ValueObjects;

final readonly class LLMRequest
{
    public function __construct(
        /** System prompt derived from the active Recipe. */
        public string $systemPrompt,

        /**
         * Full conversation history in OpenAI-compatible format.
         * @var list<array{role: string, content: string|null, tool_calls?: array, tool_call_id?: string, name?: string}>
         */
        public array $messages,

        /**
         * Tool definitions in OpenAI function-calling format.
         * @var list<array{type: "function", function: array{name: string, description: string, parameters: array}}>
         */
        public array $tools,

        public int   $maxTokens   = 4096,
        public float $temperature = 0.7,
    ) {}
}
```

### 6.2 `LLMResponse`

```php
<?php

namespace Spora\Drivers\ValueObjects;

final readonly class LLMResponse
{
    /**
     * Exactly one of $content or $toolCall is non-null per response.
     */
    public function __construct(
        /** Non-null when LLM returns text (task complete or no tool needed). */
        public ?string  $content,

        /** Non-null when LLM requests a tool invocation. */
        public ?ToolCall $toolCall,

        public int    $inputTokens,
        public int    $outputTokens,

        /** Provider-issued completion ID for logging/debugging. */
        public string $completionId,
    ) {}
}
```

### 6.3 `ToolCall`

```php
<?php

namespace Spora\Drivers\ValueObjects;

/**
 * A tool invocation as requested by the LLM.
 * Flows between the LLM driver, Orchestrator, and tool_calls DB table.
 */
final readonly class ToolCall
{
    public function __construct(
        /**
         * Provider-issued call ID, e.g. "call_abc123".
         * Required by OpenAI/Anthropic to correlate tool results.
         */
        public string $providerCallId,

        /**
         * The tool name as declared in #[Tool(name:)], e.g. "send_email".
         * NOT the PHP class name.
         */
        public string $toolName,

        /**
         * Arguments the LLM wants to pass. Keys match #[ToolParameter] names.
         * @var array<string, mixed>
         */
        public array $arguments,
    ) {}
}
```

### 6.4 `ToolResult`

```php
<?php

namespace Spora\Tools\ValueObjects;

/**
 * The normalized result of any tool execution (Input or Output).
 * Always returned — errors are encoded inside so the LLM can reason about failures.
 */
final readonly class ToolResult
{
    public function __construct(
        public bool    $success,

        /**
         * Result injected back into the LLM context window.
         * On success: the data the LLM asked for, or a confirmation message.
         * On failure: a human-readable error the LLM can reason about.
         */
        public string  $content,

        /**
         * Optional structured data stored in tool_calls.result_data for UI/audit.
         * Never sent to the LLM directly.
         *
         * Intentionally LLM-excluded: $data is the full, raw, verbose payload
         * (e.g. all search result objects, full HTTP response body, metadata).
         * $content is the tool author's curated summary — context-window-efficient
         * and shaped for LLM reasoning. If structured data is useful to the LLM,
         * the tool author should serialize the relevant parts into $content (JSON
         * strings are fine — that is the standard convention for tool results).
         *
         * Convention for HTTP-backed tools: store HTTP context here rather than
         * on ToolResult directly, e.g.:
         *   ['http_status' => 429, 'retry_after' => 60]
         * This keeps ToolResult generic for non-HTTP tools.
         *
         * @var array<string, mixed>|null
         */
        public ?array  $data = null,
    ) {}
}
```

### 6.5 `AgentState`

```php
<?php

namespace Spora\Agents\ValueObjects;

use Spora\Drivers\ValueObjects\ToolCall;

/**
 * Snapshot of Orchestrator state at the moment an OutputTool call is intercepted.
 * Stored as JSON in tasks.pending_state. Reconstructed to resume after human approval.
 */
final readonly class AgentState
{
    public function __construct(
        public int     $taskId,
        public int     $agentId,

        /**
         * The exact tool call that triggered the pause.
         */
        public ToolCall $pendingToolCall,

        /**
         * Conversation history frozen at pause time.
         * Authoritative source for the resume path — prevents race conditions
         * if task_history rows are externally modified.
         * @var list<array{role: string, content: string|null, tool_calls?: array, tool_call_id?: string, name?: string}>
         */
        public array   $messageSnapshot,

        public int     $runCount,
        public int     $maxSteps,

        /** ISO 8601 UTC timestamp. */
        public string  $pausedAt,
    ) {}

    public static function fromJson(string $json): static
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return new static(
            taskId:          $data['task_id'],
            agentId:         $data['agent_id'],
            pendingToolCall: new ToolCall(
                providerCallId: $data['pending_tool_call']['provider_call_id'],
                toolName:       $data['pending_tool_call']['tool_name'],
                arguments:      $data['pending_tool_call']['arguments'],
            ),
            messageSnapshot: $data['message_snapshot'],
            runCount:        $data['run_count'],
            maxSteps:        $data['max_steps'],
            pausedAt:        $data['paused_at'],
        );
    }

    public function toJson(): string
    {
        return json_encode([
            'task_id'           => $this->taskId,
            'agent_id'          => $this->agentId,
            'pending_tool_call' => [
                'provider_call_id' => $this->pendingToolCall->providerCallId,
                'tool_name'        => $this->pendingToolCall->toolName,
                'arguments'        => $this->pendingToolCall->arguments,
            ],
            'message_snapshot'  => $this->messageSnapshot,
            'run_count'         => $this->runCount,
            'max_steps'         => $this->maxSteps,
            'paused_at'         => $this->pausedAt,
        ], JSON_THROW_ON_ERROR);
    }
}
```

---

## 8. Security Contracts

### 7.1 `EncryptedValue`

```php
<?php

namespace Spora\Core\ValueObjects;

/**
 * Wraps a base64-encoded Libsodium secretbox blob: base64_encode(nonce . ciphertext).
 * Nonce = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES (24 bytes), randomly generated per encryption.
 *
 * Structural safety: cannot be cast to string, preventing accidental ciphertext leakage
 * into API responses or LLM context. All callers must explicitly call SecurityManager::decrypt().
 */
final class EncryptedValue
{
    public function __construct(
        private readonly string $encoded,
    ) {}

    /** The ONLY way to retrieve the stored string — for DB persistence only. */
    public function toStorageString(): string
    {
        return $this->encoded;
    }

    public function __toString(): never
    {
        throw new \LogicException(
            'EncryptedValue cannot be cast to string. Call SecurityManager::decrypt() first.'
        );
    }
}
```

### 7.2 `SecurityManagerInterface`

```php
<?php

namespace Spora\Core;

use Spora\Core\ValueObjects\EncryptedValue;

interface SecurityManagerInterface
{
    /**
     * Encrypt plaintext using the master key from storage/secret.key.
     *
     * Steps:
     *   1. $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)
     *   2. $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $masterKey)
     *   3. return new EncryptedValue(base64_encode($nonce . $ciphertext))
     *
     * @throws \RuntimeException  If master key is not loaded.
     */
    public function encrypt(string $plaintext): EncryptedValue;

    /**
     * Decrypt an EncryptedValue.
     *
     * Steps:
     *   1. $decoded = base64_decode($value->toStorageString())
     *   2. $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)
     *   3. $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)
     *   4. $plain = sodium_crypto_secretbox_open($ciphertext, $nonce, $masterKey)
     *   5. if $plain === false → throw DecryptionFailedException
     *
     * @throws \Spora\Core\Exceptions\DecryptionFailedException  MAC mismatch or corrupted data.
     * @throws \RuntimeException  If master key is not loaded.
     */
    public function decrypt(EncryptedValue $value): string;

    /**
     * Structural check: does this string look like an EncryptedValue storage blob?
     * Does NOT decrypt. Checks decoded byte length >=
     * SODIUM_CRYPTO_SECRETBOX_NONCEBYTES (24) + SODIUM_CRYPTO_SECRETBOX_MACBYTES (16) + 1.
     * Used to detect legacy plaintext values during migration.
     */
    public function looksEncrypted(string $raw): bool;
}
```

### 7.3 `SecurityManager` — Concrete Class Outline

```php
<?php

namespace Spora\Core;

/**
 * Singleton. Registered via PHP-DI factory.
 * Loads master key once at construction. Fails immediately if key file is missing,
 * unreadable, or corrupt — no lazy loading, no silent fallback.
 *
 * File: app/Core/SecurityManager.php
 */
final class SecurityManager implements SecurityManagerInterface
{
    private readonly string $masterKey;

    /**
     * @param string $keyPath  Absolute path to storage/secret.key.
     *                         Provided by the DI factory from config['base_path'].
     * @throws \RuntimeException  If file missing, unreadable, or not exactly
     *                            SODIUM_CRYPTO_SECRETBOX_KEYBYTES (32) bytes.
     */
    public function __construct(string $keyPath)
    {
        if (!file_exists($keyPath) || !is_readable($keyPath)) {
            throw new \RuntimeException(
                "Secret key file not found or not readable at: {$keyPath}. Run install.php."
            );
        }
        $key = file_get_contents($keyPath);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException(
                "Secret key at {$keyPath} is corrupt: expected " .
                SODIUM_CRYPTO_SECRETBOX_KEYBYTES . " bytes."
            );
        }
        $this->masterKey = $key;
    }

    public function encrypt(string $plaintext): EncryptedValue { /* see interface */ }
    public function decrypt(EncryptedValue $value): string { /* see interface */ }
    public function looksEncrypted(string $raw): bool { /* see interface */ }
}
```

**DI container binding:**
```php
\Spora\Core\SecurityManagerInterface::class => static function (\Psr\Container\ContainerInterface $c): SecurityManager {
    $config = $c->get('config');
    return new \Spora\Core\SecurityManager(
        rtrim($config['base_path'], '/') . '/storage/secret.key'
    );
},
```

**Runtime failure:** `SecurityManager` throws at DI container build time. The `Kernel` catches this at the outermost layer and returns `HTTP 500` with error code `KEY_FILE_MISSING`. The file path must not appear in the HTTP response — only in the server error log.

### 7.4 `DecryptionFailedException`

```php
<?php

namespace Spora\Core\Exceptions;

/**
 * Thrown when sodium_crypto_secretbox_open() returns false.
 * Indicates wrong key, tampered ciphertext, or corrupted storage.
 * ToolConfigService catches this per-field, returns null for that field, and logs.
 */
final class DecryptionFailedException extends \RuntimeException {}
```

### 7.5 `ToolConfigService`

The **only** class permitted to read or write `tool_configurations.settings` and `agent_tool_overrides.settings`.

```php
<?php

namespace Spora\Core;

use Spora\Models\Agent;
use Spora\Models\ToolConfiguration;
use Spora\Models\AgentToolOverride;
use Psr\Log\LoggerInterface;

/**
 * File: app/Core/ToolConfigService.php
 *
 * Responsibilities:
 *   - Determine which fields are password-type via #[ToolSetting] reflection.
 *   - Determine which fields are global-only (scope: "global") vs overridable (scope: "agent").
 *   - Encrypt password fields on write; store non-password fields as plain strings.
 *   - Decrypt password fields on read; return a plain PHP array to callers.
 *   - Resolve effective settings: global config merged with agent overrides.
 *   - Enforce the "***" masking rule for API responses.
 *   - Enforce the "***" no-overwrite rule on PUT.
 *   - Handle decryption failures gracefully: null for that field + log.
 *
 * Resolution algorithm in getEffectiveSettings():
 *   1. Load tool_configurations row for $toolClass → decode + decrypt → $global
 *   2. Load agent_tool_overrides row for ($agent, $toolClass) → decode + decrypt → $override
 *   3. Return array_merge($global, $override)
 *   Note: global-scope fields in $override are ignored (filtered by scope reflection).
 */
final class ToolConfigService
{
    public function __construct(
        private readonly SecurityManagerInterface $security,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Resolve the effective settings for a tool as used by the Orchestrator.
     * Merges global configuration with any agent-level overrides.
     * Password fields are returned as decrypted plaintext.
     * Fields that fail decryption are returned as null (logged as errors).
     *
     * @param  Agent  $agent
     * @param  string $toolClass  FQCN, e.g. "Spora\Tools\Builtin\SearchWebTool"
     * @return array<string, mixed>
     */
    public function getEffectiveSettings(Agent $agent, string $toolClass): array;

    /**
     * Save global tool configuration to tool_configurations.
     * Encrypts all password-type settings. Non-password settings stored as plain strings.
     * "***" no-overwrite rule: if a password field value equals "***", the existing
     * encrypted blob for that key is preserved unchanged.
     * Creates the row if it does not exist; updates if it does.
     *
     * @param  string               $toolClass  FQCN
     * @param  array<string, mixed> $settings   New settings from the caller
     */
    public function saveGlobalSettings(string $toolClass, array $settings): void;

    /**
     * Save a per-agent override for a specific tool.
     * Only settings with scope: "agent" may be stored here.
     * Global-scoped settings in $settings are silently ignored.
     * Same encryption and "***" no-overwrite rules as saveGlobalSettings().
     * Creates or replaces the agent_tool_overrides row.
     *
     * @param  Agent                $agent
     * @param  string               $toolClass
     * @param  array<string, mixed> $settings   Only the keys to override
     */
    public function saveAgentOverride(Agent $agent, string $toolClass, array $settings): void;

    /**
     * Remove a per-agent override, falling back to global configuration.
     * Deletes the agent_tool_overrides row for ($agent, $toolClass).
     * No-op if no override exists.
     */
    public function clearAgentOverride(Agent $agent, string $toolClass): void;

    /**
     * Return global settings for a tool with password fields masked as "***".
     * Used by GET /api/v1/tools/{toolClass}/settings.
     *
     * @param  string $toolClass
     * @return array<string, mixed>
     */
    public function getGlobalSettingsMasked(string $toolClass): array;

    /**
     * Return agent override settings with password fields masked as "***".
     * Used by GET /api/v1/agent/tools/{toolClass}/override.
     *
     * @param  Agent  $agent
     * @param  string $toolClass
     * @return array<string, mixed>  Empty array if no override exists.
     */
    public function getAgentOverrideMasked(Agent $agent, string $toolClass): array;

    /**
     * Re-encrypt all password fields for a tool with a new key.
     * Reads with current SecurityManager, re-encrypts with $newSecurity.
     * Updates both tool_configurations and all agent_tool_overrides rows for this tool.
     * NOT required in Phase 1 — defined here to ensure schema stability for future rotation.
     */
    public function rotate(string $toolClass, SecurityManagerInterface $newSecurity): void;

    /**
     * Check if a setting key on a tool class is type "password".
     * Uses Reflection on #[ToolSetting] attributes. Cached per request.
     */
    private function isPasswordField(string $toolClass, string $settingKey): bool;

    /**
     * Check if a setting key on a tool class has scope "global" (non-overridable).
     * Uses Reflection on #[ToolSetting] attributes. Cached per request.
     */
    private function isGlobalScope(string $toolClass, string $settingKey): bool;
}
```

### 7.6 Install Bootstrap — `install.php` Security Flow

```
1. Verify storage/ directory exists → create with chmod 0750 if not.

2. Verify storage/.htaccess contains "Deny from all":
   → Create if missing.
   → FATAL error if file exists but does not deny access.

3. Check storage/secret.key:
   IF exists:
     → Validate: must be exactly SODIUM_CRYPTO_SECRETBOX_KEYBYTES (32) bytes.
     → sodium_memzero() the loaded bytes immediately after validation.
     → Log "Key exists and is valid — skipping generation."
     → NEVER regenerate an existing key (invalidates all encrypted settings).
   IF missing:
     → $key = sodium_crypto_secretbox_keygen()
     → file_put_contents($keyPath, $key, LOCK_EX)
     → chmod($keyPath, 0600)  // owner read/write only
     → sodium_memzero($key)   // wipe from PHP memory immediately after write
     → Log "Key generated."

4. Ensure "storage/secret.key" is in .gitignore → append if missing.

5. Print summary:
   - Key status (exists/generated, 32 bytes)
   - Web protection status (.htaccess: Deny from all)
   - .gitignore status

6. Print warning:
   "IMPORTANT: Back up storage/secret.key to a location SEPARATE from this server.
    If this file is lost, all encrypted tool credentials are permanently unrecoverable."

Runtime enforcement (SecurityManager constructor, every request):
   IF key file missing or unreadable → throw \RuntimeException → HTTP 500 KEY_FILE_MISSING
   There is no fallback. Fail loudly, never silently.
```

---

## 9. Namespace Summary

| Namespace | Purpose |
|---|---|
| `Spora\Tools\Attributes` | `Tool`, `ToolParameter`, `ToolSetting` PHP 8 Attributes |
| `Spora\Tools` | `InputToolInterface`, `OutputToolInterface` |
| `Spora\Tools\ValueObjects` | `ToolResult` |
| `Spora\Tools\Builtin` | Bundled tools: `SearchWebTool`, `SendEmailTool`, etc. |
| `Spora\Agents` | `OrchestratorInterface`, concrete `Orchestrator` |
| `Spora\Agents\ValueObjects` | `AgentState` |
| `Spora\Plugins` | `PluginInterface` |
| `Spora\Drivers` | `LLMDriverInterface`, `OpenAICompatibleDriver`, `AnthropicDriver`, `GeminiDriver`, `MistralDriver` |
| `Spora\Drivers\ValueObjects` | `LLMRequest`, `LLMResponse`, `ToolCall` |
| `Spora\Drivers\Exceptions` | `LLMProviderException`, `LLMRateLimitException` |
| `Spora\Models` | Eloquent: `User`, `Agent`, `Task`, `ToolCall`, `TaskHistory`, `ToolConfiguration`, `AgentTool`, `AgentToolOverride` |
| `Spora\Http` | REST API controllers |
| `Spora\Core` | `Kernel`, `Router`, `Database`, `SecurityManager`, `ToolConfigService` |
| `Spora\Core\ValueObjects` | `EncryptedValue` |
| `Spora\Core\Exceptions` | `DecryptionFailedException` |
| `Spora\Auth` | Auth wrapper around `delight-im/auth` |
