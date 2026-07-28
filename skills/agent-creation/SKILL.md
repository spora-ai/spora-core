---
name: agent-creation
description: "When the user asks to create, set up, scaffold, or configure a new Spora agent, sub-agent, or specialised assistant; OR when a sub-task needs a toolset different from the calling agent's. Use for tasks like 'create me a weather agent', 'I need a research sub-agent', or 'scaffold a translator'. Do NOT use for editing the current agent's notes or routine fields — those go through write_notes / write_agent_configuration directly. Recommended tools: agent (operations read_agent_configuration, get_available_tools, read_notes, write_notes, create_agent, write_agent_configuration)."
license: Apache-2.0
metadata:
  author: spora-ai
  version: "1.0"
  allowedByDefault: false
  requiresTools: "agent:read_agent_configuration,agent:get_available_tools,agent:read_notes,agent:write_notes,agent:create_agent,agent:write_agent_configuration"
---

# Agent creation

When the user asks for a new agent, follow this protocol end-to-end. Do NOT try to invent the Agent Template payload from memory — read this skill first, then assemble the payload from `get_available_tools`.

## When to use this skill

Trigger on any of:

- "Create me an agent that …", "Set up a weather agent …", "Scaffold a translator …"
- "I need a sub-agent for X" or "Spin up an assistant that only uses Y"
- "Configure a new assistant like this one but with different tools"
- Any time the operator's intent is "make me a new agent" or "add a new assistant".

Do NOT use this skill for editing the *current* agent's notes (use `write_notes`) or for changing the current agent's name/system prompt (use `write_agent_configuration` directly — it's the right tool for in-place edits).

## Mandatory pre-flight

Before building the payload:

1. **Read the current configuration** via `agent(action: "read_agent_configuration")` so you know what an existing agent looks like in this codebase (column names, prompt shape, toolset style).
2. **List available tools** via `agent(action: "get_available_tools")`. The version-1 payload tells you:
   - `tool_class` — the FQCN to put in the template.
   - `call_name` — the LLM-facing identifier. **`tool_class` is what `create_agent` needs**, not `call_name`.
   - `operations` — STRING list (`["current", "forecast"]`). Convert to objects (`[{name: "current"}, {name: "forecast"}]`) before placing inside `payload.tools[].operations`.
   - `needs_configuration: false` — only enable a tool whose config is complete; `create_agent` will produce a warning otherwise.
3. **Read notes** via `agent(action: "read_notes")` if you need to remember prior decisions for this agent.

## Minimal-toolset protocol

Prefer fewer tools. Each one is an attack surface and a cost. Ask the user only when the answer materially changes the design:

- If the user said exactly which tools to enable, do that.
- If the request is open-ended ("a weather agent"), decide on the smallest reasonable toolset (e.g. `weather:weather_api` + `time`).
- If two intents are ambiguous, ask **one** short clarifying question — never invent.

Prefer `core` tools over plugin tools when both would suffice. Plugins add install/runtime cost and operator approval.

## Ask-the-user protocol (limit: one question)

Only ask when the answer changes the design materially — name (only if you have multiple plausible names), description, system-prompt persona, or whether the agent needs explicit approval on each tool call. Otherwise decide and proceed.

## Schema reference

This is the literal shape of the `payload` argument to `create_agent`. It mirrors `agent-template.schema.json`. Validate against the schema; do not improvise.

### Top-level keys

| Key | Required | Description |
| --- | --- | --- |
| `id` | yes | Namespaced id. Regex: `^([a-z0-9][a-z0-9_-]{0,63}/)?[a-z0-9][a-z0-9_-]{0,63}$`. Bare slug or `<source>/<slug>`. |
| `name` | yes | Human-readable template name. **Top level** — not inside `agent{}`. |
| `version` | yes | Semver. Regex: `^[0-9]+\.[0-9]+\.[0-9]+([+-].+)?$`. **Three-part** (`1.0.0`, not `1` or `1.0`). |
| `agent` | yes | Object — the agent record fields. **No `name`** here. |
| `tools` | yes | Array of `{tool_class, enabled, operations}`. |
| `required_plugins` | no | Array of plugin slugs (FQCN prefixes). |

### `agent{}` allowed keys only

Strict — `additionalProperties: false`. Allowed keys:

- `description` (string, ≤2000 chars)
- `system_prompt` (string — be generous; the user can edit via write_agent_configuration later)
- `notes` (markdown, ≤200000 chars — operator-facing)
- `max_steps` (integer 1..100, default 10)
- `allow_followup` (boolean, default true) — the **database column name**. Templates imported via this skill are stored under `allow_followup` in the agents table. The schema accepts the same key.
- `retry_after_minutes` (integer, default 0)
- `max_retries` (integer, default 0)

**`name` is NOT allowed inside `agent{}`.** Common mistake — the LLM reads `read_agent_configuration` and copies the shape verbatim.

### `tools[]` allowed keys only

Each tool is `{tool_class, enabled, operations}`:

- `tool_class` — FQCN string (e.g. `Spora\Tools\TimeTool`). Get this from `get_available_tools`. **Not** `call_name`.
- `enabled` — boolean.
- `operations` — array of `{name, enabled?, auto_approve?}`. **`get_available_tools` returns strings; you must wrap them in objects.**

### `required_plugins[]`

Plugin slugs (e.g. `weather`) — lowercase, slug pattern `^[a-z0-9][a-z0-9_-]*$`. NOT FQCNs. Plugins are NOT auto-installed; missing plugins produce `TOOL_PLUGIN_MISSING` warnings, not errors.

## Common mistakes

| Symptom | Cause | Fix |
| --- | --- | --- |
| `Unknown field 'agent.name'` | `name` placed inside `agent{}` | Move `name` to top level |
| `Tool entry is missing 'tool_class'` | Sent `call_name` only | Use `tool_class` (FQCN from `get_available_tools`) |
| `Operation entry must be an object` | Sent `operations: ["current"]` | Wrap as `[{name: "current"}]` |
| `Field 'version' must be a non-empty string` | Sent version as int | Send `"1.0.0"` (string, semver 3-part) |
| `Field 'version' does not match pattern /^[0-9]+\.[0-9]+\.[0-9]+...$/` | Sent `"1.0"` | Send `"1.0.0"` |
| `Tool entry is missing boolean 'enabled'` | Omitted `enabled` | Add `"enabled": true` (or `false`) |

After three identical validation errors, **stop and ask the operator** — re-reading this skill won't help if the schema is genuinely unknown to you.

## `write_agent_configuration` workflow (in-place edits to the **current** agent)

Use this for editing the calling agent, not for creating a new one.

Allowed keys inside the `agent` object:

- `name`, `description`, `system_prompt`
- `max_steps`, `allow_followup`, `retry_after_minutes`, `max_retries`
- `is_pinned`, `is_archived`, `is_favorite`

**Silent drops:**

- `notes` — stripped by the tool before the DB write. Use `write_notes` (append/prepend) or `write_notes_overwrite` (destructive, requires approval) instead.
- `llm_driver_config_id` — operator-only; stripped silently.
- Any other key (e.g. `category`, `enable_tools`, `max_tokens`, `tools`) — silently dropped at the database layer.

To verify a write took effect, call `read_agent_configuration` afterwards. If a field you sent is not in the returned payload, it was dropped — don't assume success.

## Approval

`create_agent` requires operator approval per call. Briefly state what the new agent will do before submitting, so the operator can sign off without re-reading the payload.

## Worked example

```json
{
  "$schema": "https://spora.dev/agent-template.schema.json",
  "id": "weather-agent",
  "name": "Weather Agent",
  "version": "1.0.0",
  "agent": {
    "description": "Answers weather questions: current conditions, forecasts, location search, and astronomy (sunrise/sunset, moon phase) worldwide.",
    "system_prompt": "You are the 'Weather Agent'. Use the Weather API tool to answer questions about weather, forecasts, and astronomy (sunrise/sunset, moon phase). Reply in the user's language. If a location is ambiguous, ask briefly or use the location-search operation. Always state the timezone when the user asks for a time.",
    "max_steps": 10,
    "allow_followup": true
  },
  "tools": [
    {
      "tool_class": "Spora\\Plugins\\Weather\\Tools\\WeatherApiTool",
      "enabled": true,
      "operations": [
        { "name": "current",  "enabled": true },
        { "name": "forecast", "enabled": true },
        { "name": "search",   "enabled": true },
        { "name": "astronomy","enabled": true }
      ]
    },
    {
      "tool_class": "Spora\\Tools\\TimeTool",
      "enabled": true,
      "operations": [
        { "name": "now",    "enabled": true, "auto_approve": true },
        { "name": "format", "enabled": true, "auto_approve": true }
      ]
    }
  ],
  "required_plugins": ["Spora\\Plugins\\Weather"]
}
```

This file is also stored at `skills/agent-creation/example.json` for round-trip tests.