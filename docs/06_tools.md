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

## Quick Reference: All Core Keys

| Key                            | Type     | Tool Class           | Purpose |
|--------------------------------|----------|----------------------|---------|
| `core.openai.api_key`         | password | `LLMConfiguration`   | OpenAI / compatible API key |
| `core.anthropic.api_key`      | password | `LLMConfiguration`   | Anthropic Claude API key |
| `core.tavily.api_key`         | password | `TavilySearchTool`   | Tavily web search key |
| `core.serper.api_key`         | password | `SerperSearchTool`   | Serper.dev Google search key |
| `core.worldnewsapi.api_key`   | password | `WorldNewsApiTool`   | WorldNewsAPI key |
| `core.imap.host`              | text     | `ReadEmailTool`      | IMAP server hostname |
| `core.imap.port`              | text     | `ReadEmailTool`      | IMAP port (default 993) |
| `core.imap.encryption`        | text     | `ReadEmailTool`      | `ssl` or `tls` |
| `core.imap.username`          | text     | `ReadEmailTool`      | IMAP login |
| `core.imap.password`          | password | `ReadEmailTool`      | IMAP password / app token |
| `core.smtp.dsn`               | password | `SendEmailTool`      | Symfony Mailer DSN |
| `core.smtp.from`              | text     | `SendEmailTool`      | Sender email address |
| `core.smtp.allowed_recipients`| text     | `SendEmailTool`      | Comma-separated whitelist (or `*`) |
| `core.caldav.url`             | text     | `CalDavCalendarTool` | CalDAV server URL |
| `core.caldav.username`        | text     | `CalDavCalendarTool` | CalDAV login |
| `core.caldav.password`        | password | `CalDavCalendarTool` | CalDAV password / app token |
