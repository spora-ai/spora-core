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

`#[ToolOperation]` and `#[ToolParameter]` declared on a parent class are inherited by subclasses. Use this for shared parameter sets:

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

All setting keys follow a **dot-separated hierarchical format**:

### Core Tools

```
core.{provider}.{field}
```

| Segment    | Description                                           | Examples                    |
|------------|-------------------------------------------------------|-----------------------------|
| `core`     | Fixed literal. Denotes a built-in Spora setting.      | —                           |
| `provider` | The external service or protocol the setting targets. | `tavily`, `imap`, `caldav`  |
| `field`    | The specific configuration field.                     | `api_key`, `host`, `port`   |

**Examples:**
- `core.tavily.api_key`
- `core.imap.host`
- `core.smtp.allowed_recipients`
- `core.openai.api_key`

### Plugin Tools

```
plugin.{plugin_name}.{provider}.{field}
```

| Segment       | Description                                              | Examples                        |
|---------------|----------------------------------------------------------|---------------------------------|
| `plugin`      | Fixed literal. Denotes a plugin-contributed setting.     | —                               |
| `plugin_name` | The unique slug of the plugin (from `plugin.json`).      | `acme-weather`, `my-crm`       |
| `provider`    | The external service or protocol the setting targets.    | `openweathermap`, `weatherapi`  |
| `field`       | The specific configuration field.                        | `api_key`, `base_url`          |

**Examples:**
- `plugin.acme-weather.openweathermap.api_key`
- `plugin.my-crm.salesforce.client_secret`

The extra `plugin_name` segment ensures that two different plugins cannot accidentally collide on the same key, even if they wrap the same external provider.

---

## Architecture: Settings Live on the Tool

Settings are declared as `#[ToolSetting]` PHP attributes **directly on the tool class** that consumes them. There are no separate "Configuration" shell classes.

### Example

```php
#[Tool(
    name: 'tavily_search',
    description: 'Search the web using Tavily AI.',
)]
#[ToolSetting(
    key: 'core.tavily.api_key',
    label: 'Tavily API Key',
    type: 'password',
    description: 'API key for api.tavily.com',
    required: true,
)]
#[ToolOperation(name: 'search', description: 'Search', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolParameter(name: 'query', type: 'string', description: 'The search query.', required: true)]
final class TavilySearchTool extends AbstractTool
{
    public function __construct(
        private readonly ToolConfigService $configService,
        private readonly HttpClientInterface $httpClient,
    ) {}

    public function execute(array $arguments, int $agentId, ?int $userId = null): ToolResult
    {
        // Settings are resolved from the tool's OWN class:
        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);
        $apiKey   = $settings['core.tavily.api_key'] ?? '';
        // ...
    }

    public function describeAction(array $arguments): string { /* ... */ }
}
```

### Why?

1. **Discoverability:** A developer reading a tool class can immediately see *what settings it needs* and *what parameters the LLM sends it*.
2. **No empty shell classes:** Previously, settings lived on empty `*Configuration` classes that existed only to hold attributes. This was wasteful.
3. **Self-documenting:** The `ToolController` scans `#[ToolSetting]` attributes via reflection. Since they now live on the tool class itself, the API endpoint `GET /api/v1/tools` automatically returns both the tool schema and its settings schema in a single response.

### Exception: `LLMConfiguration`

`LLMConfiguration` is the **only** standalone configuration class. It holds API keys (`core.openai.api_key`, `core.anthropic.api_key`) consumed by the `DriverFactory` — not by any agent tool. It remains in `app/Drivers/LLMConfiguration.php`.

---

## Setting cascade: global → user → agent

`ToolConfigService::getEffectiveSettings(toolClass, agentId, userId)` resolves each setting in order:

1. Global value (`tool_configurations` table, scoped to the setting key).
2. User-level override (`user_tool_configurations`, when `userId` is provided).
3. Agent-level override (`agent_tool_configurations`, when an entry exists for `agentId + toolClass`).

The first non-null value wins. Tools never read `tool_configurations` directly — always go through `ToolConfigService`.

---

## Shared Keys Across Tools

Because keys are **globally unique**, two tools that reference the same key (e.g. `core.imap.host`) will resolve to the same stored value. This means:

- A user configures `core.imap.host` once in the `ReadEmailTool` settings panel.
- A hypothetical future `SearchEmailTool` that also declares `core.imap.host` will automatically pick up the same value.

The `ToolConfigService::getEffectiveSettings()` method resolves settings by scanning the `#[ToolSetting]` attributes on the requested class, then looking up each key in the global and agent-override stores.

---

## LLM Exposure (`expose_to_llm`)

By default, tool settings are **server-side only** — they influence how a tool behaves at execution time but are never sent to the LLM.

The `expose_to_llm` parameter on `#[ToolSetting]` controls whether a setting's resolved value is included in the tool definition the LLM receives. This lets the LLM make informed decisions based on its effective configuration.

```php
#[ToolSetting(
    key: 'core.smtp.allowed_recipients',
    label: 'Allowed Recipients',
    type: 'text',
    description: 'Comma-separated list of email addresses the agent is allowed to send to.',
    expose_to_llm: true,  // included in LLM tool definition
)]
#[ToolSetting(
    key: 'core.smtp.host',
    label: 'SMTP Host',
    type: 'text',
    expose_to_llm: false,  // NOT sent to LLM (infrastructure)
)]
```

### Default behavior

`expose_to_llm` defaults to `false` because most settings are credentials or infrastructure (hosts, ports, timeouts). Only mark `expose_to_llm: true` for settings that **directly affect what the LLM can do** — e.g. allowed recipient lists, sender addresses, toggle-able capabilities.

### How it reaches the LLM

`ToolConfigService::getLlmToolSettings()` returns the effective (cascaded) values for all `expose_to_llm` settings on a tool. The Orchestrator appends these to the tool's description before sending it to the LLM:

```
[Effective Configuration]
- Allowed Recipients: alice@example.com, bob@example.com
- From Address: agent@spora.local
```

Unconfigured settings are shown as `(not configured)` so the LLM knows a capability may be unavailable.

---

## Quick Reference: All Core Keys

| Key                            | Type     | Tool Class           | Purpose | LLM Exposed |
|--------------------------------|----------|----------------------|---------|-------------|
| `core.openai.api_key`         | password | `LLMConfiguration`   | OpenAI / compatible API key | — |
| `core.anthropic.api_key`      | password | `LLMConfiguration`   | Anthropic Claude API key | — |
| `core.tavily.api_key`         | password | `TavilySearchTool`   | Tavily web search key | — |
| `core.serper.api_key`         | password | `SerperSearchTool`   | Serper.dev Google search key | — |
| `core.worldnewsapi.api_key`   | password | `WorldNewsApiTool`   | WorldNewsAPI key | — |
| `core.imap.host`              | text     | `EmailTool`          | IMAP server hostname | — |
| `core.imap.port`              | text     | `EmailTool`          | IMAP port (default 993) | — |
| `core.imap.encryption`        | select   | `EmailTool`          | `ssl` or `tls` or `notls` | — |
| `core.email.username`         | text     | `EmailTool`          | Email login for both IMAP and SMTP | — |
| `core.email.password`         | password | `EmailTool`          | Email password / app token for both | — |
| `core.smtp.host`              | text     | `EmailTool`          | SMTP server hostname | — |
| `core.smtp.port`              | text     | `EmailTool`          | SMTP port (default 587) | — |
| `core.smtp.encryption`        | select   | `EmailTool`          | `tls` or `ssl` or `notls` | — |
| `core.smtp.from`              | text     | `EmailTool`          | Sender email address | ✓ |
| `core.smtp.allowed_recipients`| text     | `EmailTool`          | Comma-separated whitelist (or `*`) | ✓ |
| `core.caldav.url`             | text     | `CalDavCalendarTool` | CalDAV server URL | — |
| `core.caldav.username`        | text     | `CalDavCalendarTool` | CalDAV login | — |
| `core.caldav.password`        | password | `CalDavCalendarTool` | CalDAV password / app token | — |

"LLM Exposed ✓" means `expose_to_llm: true` — the setting's effective value is included in the tool definition sent to the LLM.
