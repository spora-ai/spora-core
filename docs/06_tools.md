# Spora Tool System

This document covers tool naming conventions, the `#[Tool]` attribute, and how tools are sent to the LLM.

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
    description: 'Search the web using Tavily AI.'
)]
#[ToolSetting(
    key: 'core.tavily.api_key',
    label: 'Tavily API Key',
    type: 'password',
    description: 'API key for api.tavily.com',
    scope: 'agent'
)]
#[ToolParameter(
    name: 'query',
    type: 'string',
    description: 'The search query.',
    required: true
)]
final class TavilySearchTool implements InputToolInterface
{
    public function execute(array $arguments, int $agentId): ToolResult
    {
        // Settings are resolved from the tool's OWN class:
        $settings = $this->configService->getEffectiveSettings(static::class, $agentId);
        $apiKey   = $settings['core.tavily.api_key'] ?? '';
        // ...
    }
}
```

### Why?

1. **Discoverability:** A developer reading a tool class can immediately see *what settings it needs* and *what parameters the LLM sends it*.
2. **No empty shell classes:** Previously, settings lived on empty `*Configuration` classes that existed only to hold attributes. This was wasteful.
3. **Self-documenting:** The `ToolController` scans `#[ToolSetting]` attributes via reflection. Since they now live on the tool class itself, the API endpoint `GET /api/v1/tools` automatically returns both the tool schema and its settings schema in a single response.

### Exception: `LLMConfiguration`

`LLMConfiguration` is the **only** standalone configuration class. It holds API keys (`core.openai.api_key`, `core.anthropic.api_key`) consumed by the `DriverFactory` — not by any agent tool. It remains in `app/Drivers/LLMConfiguration.php`.

---

## Global vs Agent-Scoped Settings

Each `#[ToolSetting]` has a `scope` parameter:

| Scope    | Stored In                 | Behavior |
|----------|---------------------------|----------|
| `global` | `tool_configurations`     | Set once, applies to all agents. Cannot be overridden per-agent. |
| `agent`  | `agent_tool_overrides`    | Can be overridden per-agent. Falls back to global if not overridden. |

Most API keys are `scope: 'agent'` so that different agents can use different provider accounts.

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
