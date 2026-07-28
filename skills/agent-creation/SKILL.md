---
name: agent-creation
description: "When the user asks to create, set up, scaffold, or configure a new Spora agent, sub-agent, or specialised assistant; OR when a sub-task needs a toolset different from the calling agent's. Use for tasks like 'create me a weather agent', 'I need a research sub-agent', or 'scaffold a translator'. Do NOT use for editing the current agent's notes or routine fields — those go through write_notes / write_agent_configuration directly. Recommended tools: agent (operations read_agent_configuration, get_available_tools, read_notes, write_notes, create_agent, configure_tools, read_agent, write_agent_configuration)."
license: Apache-2.0
metadata:
  author: spora-ai
  version: "1.2"
  allowedByDefault: false
  requiresTools: "agent:read_agent_configuration,agent:get_available_tools,agent:read_notes,agent:write_notes,agent:create_agent,agent:configure_tools,agent:read_agent,agent:write_agent_configuration"
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
2. **List available tools** via `agent(action: "get_available_tools")`. The version-2 payload tells you, per tool:
   - `tool_class` — the FQCN. **Use this for `configure_tools`**. NOT `call_name` (which is for tool invocation, irrelevant here) and NOT `tool_name` (which is a short slug that overlaps with `tool_class`).
   - `display_name` — human-readable name.
   - `description` — one-line usage hint.
   - `plugin_slug` — `null` for core tools, the slug (e.g. `"weather"`) for plugin tools. **Use this for `required_plugins[]`** in `create_agent`. NOT the FQCN.
   - `enabled` — current state.
   - `ready_to_enable` — whether `configure_tools` will succeed without operator setup.
   - `missing_required` — list of setting keys that block enablement (e.g. `["api_key"]`).
   - `operations[]` — each `{name, description, enabled, requires_approval}`. Convert string operation lists to `[{name: "..."}]` objects before placing inside `configure_tools`'s `tools[].operations`.
3. **Read notes** via `agent(action: "read_notes")` if you need to remember prior decisions for this agent.

## Minimal-toolset protocol

Prefer fewer tools. Each one is an attack surface and a cost. Ask the user only when the answer materially changes the design:

- If the user said exactly which tools to enable, do that.
- If the request is open-ended ("a weather agent"), decide on the smallest reasonable toolset (e.g. weather plugin's API tool + the core Time tool).
- If two intents are ambiguous, ask **one** short clarifying question — never invent.

Prefer `core` tools (plugin_slug: null) over plugin tools when both would suffice. Plugins add install/runtime cost and operator approval.

## Ask-the-user protocol (limit: one question)

Only ask when the answer changes the design materially — name (only if you have multiple plausible names), description, system-prompt persona, or whether the agent needs explicit approval on each tool call. Otherwise decide and proceed.

## Two-phase flow (LLM-facing)

The LLM does NOT create an agent and configure its tools in one call. Use:

1. **`create_agent`** — skeletal record: `id`, `name`, `version`, `agent{}`, `required_plugins[]`. **No `tools[]` block.** Use this when you want to make a new agent and (maybe) configure it later.
2. **`read_agent`** — read back the just-created agent by `agent_id` (the numeric pk returned by `create_agent`) to confirm the skeletal record actually committed.
3. **`configure_tools`** — enable / disable tools and per-operation overrides on the calling agent. Takes a `tools` list of `{tool_class, enabled, operations: [{name, enabled?, auto_approve?}]}`.
4. **`read_agent`** again — verify the toolset is exactly what you wanted.

Do NOT try to send a `tools[]` block inside the `create_agent` payload from the LLM-facing path. That nested shape is reserved for **operator-upload templates** — the same shape as the dashboard file-upload endpoint (`POST /api/v1/agent-templates/import`). The LLM-facing path goes through `configure_tools` only, one toolset decision per call, after the agent row exists.

## Schema reference

This is the literal shape of the `payload` argument to `create_agent`. It mirrors `agent-template.schema.json`. Validate against the schema; do not improvise.

### Top-level keys

| Key | Required | Description |
| --- | --- | --- |
| `id` | yes | Namespaced id. Regex: `^([a-z0-9][a-z0-9_-]{0,63}/)?[a-z0-9][a-z0-9_-]{0,63}$`. Bare slug or `<source>/<slug>`. |
| `name` | yes | Human-readable template name. **Top level** — not inside `agent{}`. |
| `version` | yes | Semver. Regex: `^[0-9]+\.[0-9]+\.[0-9]+([+-].+)?$`. **Three-part** (`1.0.0`, not `1` or `1.0`). |
| `agent` | yes | Object — the agent record fields. **No `name`** here. |
| `tools` | no | Array of `{tool_class, enabled, operations}` — operator-upload only. The LLM-facing path leaves this out and uses `configure_tools` instead. |
| `required_plugins` | no | Array of plugin **slugs** (e.g. `["weather"]`), one per plugin the new agent will depend on. NOT FQCNs. Use the `plugin_slug` value from `get_available_tools`. Plugins are not auto-installed; missing plugins produce `TOOL_PLUGIN_MISSING` warnings, not errors. |

### `agent{}` allowed keys only

Strict — `additionalProperties: false`. Allowed keys:

- `description` (string, ≤2000 chars)
- `system_prompt` (string — be generous; the user can edit via `write_agent_configuration` later)
- `notes` (markdown, ≤200000 chars — operator-facing)
- `max_steps` (integer 1..100, default 10)
- `allow_followup` (boolean, default true) — the database column name.
- `retry_after_minutes` (integer, default 0)
- `max_retries` (integer, default 0)

**`name` is NOT allowed inside `agent{}`.** Common mistake — the LLM reads `read_agent_configuration` and copies the shape verbatim.

### `configure_tools` shape (LLM-facing)

The `tools` argument is a list of `{ tool_class, enabled, operations }`. Each operation entry is `{ name, enabled?, auto_approve? }`.

```json
{
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
  ]
}
```

- `tool_class` — FQCN string (e.g. `Spora\Tools\TimeTool`). Get this from `get_available_tools`. **NOT** `call_name` or `tool_name`.
- `enabled` — boolean. `false` removes the tool from the agent entirely.
- `operations` — array of `{name, enabled?, auto_approve?}`. Each operation maps onto the tool's `#[ToolOperation]` declarations.
- Omit `operations` to inherit the tool's per-operation defaults.

### Operator-upload `tools[]` (file upload endpoint only)

Each tool is `{tool_class, enabled, operations}` — same shape as the LLM-facing `configure_tools` block. The validator accepts both paths; only the LLM-facing path splits into a separate `configure_tools` call.

## Common mistakes

| Symptom | Cause | Fix |
| --- | --- | --- |
| `Unknown field 'agent.name'` | `name` placed inside `agent{}` | Move `name` to top level |
| `Tool entry is missing 'tool_class'` | Sent `call_name` or `tool_name` only | Use `tool_class` (FQCN from `get_available_tools`) |
| `Field 'required_plugins' must be an array of strings` | Used an FQCN like `"Spora\\Plugins\\Weather"` | Use the `plugin_slug` (e.g. `"weather"`) from `get_available_tools` |
| `Operation entry must be an object` | Sent `operations: ["current"]` | Wrap as `[{name: "current"}]` |
| `Field 'version' must be a non-empty string` | Sent version as int | Send `"1.0.0"` (string, semver 3-part) |
| `Field 'version' does not match pattern /^[0-9]+\.[0-9]+\.[0-9]+...$/` | Sent `"1.0"` | Send `"1.0.0"` |
| `configure_tools` returns "must be an array" | Sent a non-array `tools` field or sent it as `{item: [...]}` wrap | Use a plain JSON array |
| `configure_tools` returns "tool entry must be an object" | Sent a non-object tool entry | Wrap in `{ ... }` |
| `configure_tools` returns "operations[i][j] must be `{name, ...}`" | Missing `name` from an op entry | Wrap as `[{name: "now", enabled: true}]` |
| `configure_tools` returns "operation entry must be an object" | Sent `operations: [...]` as a wrapped `{item: [...]}` | Use a plain JSON array |

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

- `create_agent` — operator approval per call. Briefly state what the new agent will do before submitting, so the operator can sign off without re-reading the payload.
- `configure_tools` — operator approval per call. Brief summary of the toolset change.
- `read_agent` — no approval.

## Worked example

The example below shows the **LLM-facing flow** for a "weather agent" request. Three separate calls, in order:

### Step 1 — `create_agent` (skeletal record)

```json
{
  "action": "create_agent",
  "agent": [],
  "content": "",
  "payload": {
    "id": "weather-agent",
    "name": "Weather Agent",
    "version": "1.0.0",
    "agent": {
      "description": "Answers weather questions: current conditions, forecasts, location search, and astronomy (sunrise/sunset, moon phase) worldwide.",
      "system_prompt": "You are the 'Weather Agent'. Use the Weather API tool to answer questions about weather, forecasts, and astronomy (sunrise/sunset, moon phase). Reply in the user's language. If a location is ambiguous, ask briefly or use the location-search operation. Always state the timezone when the user asks for a time.",
      "max_steps": 10,
      "allow_followup": true
    },
    "required_plugins": ["weather"]
  }
}
```

The `create_agent` response includes the new `agent_id` (numeric pk). Capture it for the next step.

### Step 2 — `configure_tools` (apply the toolset)

Using the `agent_id` from step 1 (or the calling agent, since `configure_tools` scopes to the calling agent — pass `agent_id` only if `read_agent` revealed you meant a different agent, which it can't, so the `agent_id` is implicit and you don't need to send it):

```json
{
  "action": "configure_tools",
  "agent": [],
  "content": "",
  "payload": {
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
    ]
  }
}
```

(`configure_tools`'s `payload` argument has a single `tools` field — the `agent_id` is not passed explicitly; the tool always acts on the calling agent.)

### Step 3 — `read_agent` (verify)

```json
{
  "action": "read_agent",
  "agent": [],
  "content": "",
  "agent_id": 42
}
```

Replace `42` with whatever `create_agent` returned in step 1. The response echoes the full agent state — id, name, description, system_prompt, enabled_tools, and per-operation `enabled`/`requires_approval` state. If anything looks off (missing tool, wrong operation enabled, etc.), call `configure_tools` again with the delta — `enabled: false` on a tool entry removes it; `enabled: true` re-adds it.

## Operator-upload shape (file upload endpoint only)

For completeness: an operator uploading an agent template via the dashboard file-upload endpoint (`POST /api/v1/agent-templates/import`) sends the **full** skeleton-plus-toolset in one shot:

```json
{
  "$schema": "https://spora.dev/agent-template.schema.json",
  "id": "weather-agent",
  "name": "Weather Agent",
  "version": "1.0.0",
  "agent": { "max_steps": 10, "allow_followup": true },
  "tools": [
    {
      "tool_class": "Spora\\Plugins\\Weather\\Tools\\WeatherApiTool",
      "enabled": true,
      "operations": [
        { "name": "current",  "enabled": true },
        { "name": "forecast", "enabled": true }
      ]
    }
  ],
  "required_plugins": ["weather"]
}
```

This file is also stored at `skills/agent-creation/example.json` for round-trip tests. The validator accepts both this form and the LLM-facing split (no `tools[]` block in `create_agent`).
