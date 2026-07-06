# Spora Tool System

This document covers how to author a tool, the attribute system, naming conventions, and how tools reach the LLM.

---

## Authoring a Tool

A tool is a `final` PHP class that **extends `AbstractTool`** and declares its identity, operations, parameters, and settings as PHP attributes. The base class composes `HasOperations` (operation dispatch) and `HasParameterSchema` (auto-generated JSON Schema), so a minimal tool is just `execute()` + `describeAction()`.

```php
use Spora\Tools\AbstractTool;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\ValueObjects\ToolResult;

#[Tool(
    name: 'web_search',
    description: 'Search the web.',
    displayName: 'Web Search',
    category: 'research',
)]
#[ToolOperation(name: 'search', description: 'Run a search', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolParameter(name: 'query', type: 'string', description: 'The search query.', required: true)]
final class MyWebSearchTool extends AbstractTool
{
    public function execute(array $arguments, int $agentId, ?int $userId = null): ToolResult
    {
        $query = trim((string) ($arguments['query'] ?? ''));
        // ...
        return new ToolResult(true, "Results for {$query}");
    }

    public function describeAction(array $arguments): string
    {
        return "Search the web for: '{$arguments['query']}'";
    }
}
```

That's it — no hand-written `getParametersSchema()`. The `ToolParameterSchemaBuilder` reads the `#[ToolOperation]` and `#[ToolParameter]` attributes via reflection and produces the JSON Schema sent to the LLM.

### The auto-synthesized `action` discriminator

When a tool declares **two or more** `#[ToolOperation]` attributes, the builder prepends a property to the schema (named after the first operation's `discriminatorKey`, default `'action'`) whose `enum` lists every declared operation name. **Do not also write `#[ToolParameter(name: 'action', ...)]`** — the builder owns that property.

```php
#[ToolOperation(name: 'list_events', description: 'Fetch upcoming events')]
#[ToolOperation(name: 'create_event', description: 'Create an event')]
// Auto-generated:
//   properties.action = {type: string, enum: ['list_events', 'create_event'], description: '...'}
//   required = ['action']
```

Single-op tools skip discriminator synthesis — the LLM has no choice to make, and `HasOperations::getOperationName()` falls back to the one declared operation when the argument is absent.

To use a different discriminator key (e.g. `'operation'` for parity with an external API), declare it on **every** `#[ToolOperation]`:

```php
#[ToolOperation(name: 'search', ..., discriminatorKey: 'operation')]
#[ToolOperation(name: 'top_news', ..., discriminatorKey: 'operation')]
```

### Parameter declaration order is significant

The order in which `#[ToolParameter]` attributes appear on the class determines:

1. The property order in the JSON Schema sent to the LLM.
2. The render order of fields in the approval UI (the `parameter_schema` field on `tool_calls` API responses carries this order to the frontend).

Put the most important parameters first.

### Inheritance

`#[ToolParameter]` declared on a parent class is inherited by subclasses for **schema generation** — `ToolParameterSchemaBuilder` walks the class hierarchy when collecting `#[ToolParameter]` attributes. Use this for shared parameter sets:

```php
#[ToolParameter(name: 'name',    type: 'string',  description: '...', required: false)]
#[ToolParameter(name: 'content', type: 'string',  description: '...', required: false)]
abstract class AbstractMemoryTool extends AbstractTool { /* shared CRUD */ }

#[Tool(name: 'memory', description: 'Agent-scoped memory.')]
#[ToolOperation(name: 'list', ...)] #[ToolOperation(name: 'get', ...)]
#[ToolOperation(name: 'save', ...)] #[ToolOperation(name: 'delete', ...)]
final class AgentMemoryTool extends AbstractMemoryTool { protected function getScope(): string { return 'agent'; } }
```

The concrete `AgentMemoryTool` schema includes `action`, `name`, and `content` automatically.

> **Note on `#[ToolOperation]`:** `HasOperations` reads operation attributes only from the concrete class — it does **not** walk parent classes. Always declare `#[ToolOperation]` on the concrete tool class, otherwise dispatch will fail to resolve operations advertised by an inherited schema.

### `#[ToolParameter]` reference

| Field | Type | Notes |
|---|---|---|
| `name` | `string` | Argument key the LLM sends. |
| `type` | `string` | One of `string`, `number`, `integer`, `boolean`, `array`, `object`. |
| `description` | `string` | Sent to the LLM. |
| `required` | `bool` (default `true`) | Adds the name to `required[]` in the schema. |
| `default` | `mixed` (default `null`) | Emitted as JSON Schema `default`. When set, the parameter is omitted from `required[]` regardless of the `required` flag. |
| `enum` | `list<string>` | Value allowlist (string types). |
| `minimum` / `maximum` | `int\|float\|null` | Numeric bounds. |
| `format` | `?string` | JSON Schema format hint (e.g. `'date'`, `'email'`). |
| `items` | `?array` | Sub-schema for `array` types, e.g. `['type' => 'string']`. |

### Plugin tools

Plugin tools can either `extends AbstractTool` like core tools, or — if they need to extend a third-party base class — opt in via:

```php
use Spora\Tools\Traits\HasOperations;
use Spora\Tools\Traits\HasParameterSchema;

final class MyPluginTool extends ThirdPartyBase implements ToolInterface
{
    use HasOperations;
    use HasParameterSchema;
    // ...
}
```

The schema builder works on any FQCN via reflection — no path coupling.

---

## Tool naming

Every tool carries a **unique LLM-facing name** declared via the `#[Tool(name:)]` attribute:

```php
#[Tool(
    name: 'web_search',   // snake_case, /^[a-z][a-z0-9_]*$/
    description: 'Search the web.'
)]
```

Names must match `/^[a-z][a-z0-9_]*$/` (lowercase alphanumeric + underscore, starting with a letter). An `InvalidArgumentException` is thrown at class instantiation time if the name is invalid.

### Core vs Plugin namespacing

- **Core tools** (built-in): sent to the LLM with their plain name, e.g. `web_search`.
- **Plugin tools**: prefixed with the plugin slug and a colon, e.g. `my-plugin:web_search`.

This ensures global uniqueness — two plugins can never produce a tool name collision. The prefix is derived automatically from `plugin.json` and requires no changes to the plugin's `#[Tool]` attribute.

> **Note:** core tools intentionally do **not** use a `core:` prefix. Adding it would change every tool name currently known to the LLM, breaking existing agents. Only plugin tools get the slug prefix.

---

## Tool Settings Key Convention

Settings keys are the **bare field name** (e.g. `api_key`, `http_timeout`,
`host`), scoped to the declaring tool class via its `#[ToolSetting]` attribute.
The key is what the UI displays and what tool code reads via
`ToolConfigService::getEffectiveSettings(toolClass, ...)`.

There is no shared key namespace — keys are looked up per tool class through
reflection on the `#[ToolSetting]` attributes on that class. Two different
tool classes may declare the same key name (e.g. `api_key`) without colliding;
each resolves to its own setting on its own tool.

**Examples:**
- `http_timeout` (declared on `ReadUrlTool`)
- `api_key` (declared on whatever tool needs it — `TavilySearchTool`,
  `WeatherApiTool`, etc.)

### Plugin Tools

When a tool is contributed by a plugin, the same rule applies: the key is the
bare field name. The plugin declares its tool class in a PSR-4-namespaced
location and the `#[ToolSetting]` attributes on that class drive the same
per-tool resolution. No `plugin.*` namespace prefix is used.

---

## Architecture: Settings Live on the Tool

Settings are declared as `#[ToolSetting]` PHP attributes **directly on the tool class** that consumes them. There are no separate "Configuration" shell classes.

### Example

```php
#[Tool(
    name: 'my_search',
    description: 'Search a remote API.',
)]
#[ToolSetting(
    key: 'api_key',
    label: 'API Key',
    type: 'password',
    description: 'API key for the remote search service.',
    required: true,
)]
#[ToolOperation(name: 'search', description: 'Search', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolParameter(name: 'query', type: 'string', description: 'The search query.', required: true)]
final class MySearchTool extends AbstractTool
{
    public function __construct(
        private readonly ToolConfigService $configService,
        private readonly HttpClientInterface $httpClient,
    ) {}

    public function execute(array $arguments, int $agentId, ?int $userId = null): ToolResult
    {
        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);
        $apiKey   = $settings['api_key'] ?? '';
        // ...
    }

    public function describeAction(array $arguments): string { /* ... */ }
}
```

### Why?

1. **Discoverability:** A developer reading a tool class can immediately see *what settings it needs* and *what parameters the LLM sends it*.
2. **No empty shell classes:** Previously, settings lived on empty `*Configuration` classes that existed only to hold attributes. This was wasteful.
3. **Self-documenting:** The `ToolController` scans `#[ToolSetting]` attributes via reflection. Since they now live on the tool class itself, the API endpoint `GET /api/v1/tools` automatically returns both the tool schema and its settings schema in a single response.

### Exception: LLM driver settings

LLM driver settings (OpenAI/Anthropic API keys, model, base URL, etc.) are stored as a JSON blob on the `llm_driver_configurations` table, scoped to a per-driver `LLMDriverConfiguration` record, and surfaced via `LLMConfigService::decodeSettings()`. The driver class itself declares its settings via `#[ToolSetting]` on the class (see `app/Drivers/OpenAICompatibleDriver.php` and `app/Drivers/AnthropicCompatibleDriver.php`), and `DriverFactory` reads them when constructing a driver for an agent.

---

## Setting cascade: schema defaults → global → user → agent

`ToolConfigService::getEffectiveSettings(toolClass, agentId, userId)` resolves each setting in order:

1. **Schema defaults** — the `default:` value declared on the `#[ToolSetting(...)]` attribute, used when no row exists in any of the three tables below.
2. **Global** value (`tool_configurations` table, scoped to the tool class).
3. **User-level** override (`tool_user_settings`, when `userId` is provided).
4. **Agent-level** override (`agent_tool_overrides`, when an entry exists for `agentId + toolClass`).

Later layers win; schema defaults fill in any keys that no layer has set. Tools never read `tool_configurations` directly — always go through `ToolConfigService`.

---

## Per-Tool Key Scoping

Settings keys are scoped to the declaring tool class. Two tools that happen to
declare a setting with the same key name (e.g. both declaring `api_key`) do
**not** share values — each tool resolves its own settings independently via
`ToolConfigService::getEffectiveSettings(static::class, ...)`.

This means:

- A user configures `api_key` once on the `TavilySearchTool` settings panel —
  that value is only used when `TavilySearchTool` runs.
- A different tool that also declares `api_key` (e.g. `WeatherApiTool`) reads
  its own separately-stored `api_key` value.

The `ToolConfigService::getEffectiveSettings()` method resolves settings by
scanning the `#[ToolSetting]` attributes on the requested class, then looking
up each key in the global and agent-override stores for that class.

---

## LLM Exposure (`exposeToLlm`)

By default, tool settings are **server-side only** — they influence how a tool behaves at execution time but are never sent to the LLM.

The `exposeToLlm` parameter on `#[ToolSetting]` controls whether a setting's resolved value is included in the tool definition the LLM receives. This lets the LLM make informed decisions based on its effective configuration.

```php
#[ToolSetting(
    key: 'allowed_domains',
    label: 'Allowed Domains',
    type: 'text',
    description: 'Comma-separated list of domains the agent is allowed to query.',
    exposeToLlm: true,  // included in LLM tool definition
)]
#[ToolSetting(
    key: 'api_key',
    label: 'API Key',
    type: 'password',
    exposeToLlm: false,  // NOT sent to LLM (credential)
)]
```

### Default behavior

`exposeToLlm` defaults to `false` because most settings are credentials or infrastructure (hosts, ports, timeouts). Only mark `exposeToLlm: true` for settings that **directly affect what the LLM can do** — e.g. allowed recipient lists, sender addresses, toggle-able capabilities.

### How it reaches the LLM

`ToolConfigService::getLlmToolSettings()` returns the effective (cascaded) values for all `exposeToLlm: true` settings on a tool. The Orchestrator appends these to the tool's description before sending it to the LLM:

```
[Effective Configuration]
- Allowed Domains: example.com, internal.example.org
- From Address: agent@spora.local
```

Unconfigured settings are shown as `(not configured)` so the LLM knows a capability may be unavailable.

---

## Quick Reference: All Tool Settings Keys

| Key              | Type     | Tool Class    | Purpose | LLM Exposed |
|------------------|----------|---------------|---------|-------------|
| `http_timeout`   | text     | `ReadUrlTool` | Read URL HTTP timeout (seconds) | — |

"LLM Exposed ✓" means `exposeToLlm: true` — the setting's effective value is included in the tool definition sent to the LLM.

The remaining tools (`Calculator`, `CurrentTime`, `AgentMemory`, `GlobalMemory`, `Handover`, `ReadUrl`, `UserInfo`) declare no `#[ToolSetting]` attributes — they take their inputs entirely from the LLM's arguments and have no infrastructure configuration to expose.
