---
name: agent-creation
description: "When the user asks to create, set up, scaffold, or configure a new Spora agent, sub-agent, or specialised assistant; OR when a sub-task needs a toolset different from the calling agent's; OR when the user wants to edit an existing agent's configuration (name, description, system_prompt, scheduling, flags). Use for tasks like 'create me a weather agent', 'I need a research sub-agent', 'scaffold a translator', or 'rename agent 42 to Translation bot'. Do NOT use for editing the current agent's notes — those go through write_notes. Recommended tools: agent (operations create_agent, configure_tools, read_agent, update_agent, list_agents, get_available_tools, read_notes, write_notes, write_notes_overwrite)."
license: Apache-2.0
metadata:
  author: spora-ai
  version: "2.1"
  allowedByDefault: false
  requiresTools: "agent:create_agent,agent:configure_tools,agent:read_agent,agent:update_agent,agent:list_agents,agent:get_available_tools,agent:read_notes,agent:write_notes"
---

# Agent creation

When the user asks for a new agent, follow this protocol end-to-end. Do NOT try to invent the call sequence from memory — read this skill first, then drive the flow through `get_available_tools` and the three agent-tool calls.

## When to use this skill

Trigger on any of:

- "Create me an agent that …", "Set up a weather agent …", "Scaffold a translator …"
- "I need a sub-agent for X" or "Spin up an assistant that only uses Y"
- "Configure a new assistant like this one but with different tools"
- Any time the operator's intent is "make me a new agent" or "add a new assistant".

Do NOT use this skill for editing the *current* agent's notes (use `write_notes`) or for changing the current agent's name/system prompt (use `update_agent` directly — it's the right tool for in-place edits).

## List your existing agents before creating a new one

Before driving the create-agent flow, run `agent(action: "list_agents")` if there's any chance the user already has agents you could reuse or rename. The operator's most common reason to ask "create me a translator agent" is that they lost the agent_id after a turn boundary — `create_agent` returns a fresh pk each time it runs, so the cheap recovery is:

```json
{ "action": "list_agents" }
```

Returns a slim list of `{agent_id, name, description}` rows (`#4 Custom Agent — does X`) owned by the current user, ordered newest-first. Empty list means "go ahead and create". If the agent you want already exists at row N, skip step 1 — call `update_agent(agent_id: N, agent: {…})` or `configure_tools(agent_id: N, tools: […])` directly. `update_agent` accepts an optional `agent_id`; omitting it edits the calling agent.

## Mandatory pre-flight

Before driving the flow:

1. **Read the current agent's manifest** via `agent(action: "read_agent")` (no `agent_id` — reads the calling agent) so you know what an existing agent looks like in this codebase. The result has two JSON blocks in `result_content`: a `Base config` block and a `Tool config` block (see *Canonical agent manifest* below).
2. **List available tools** via `agent(action: "get_available_tools")`. The version-2 payload tells you, per tool:
    - `tool_class` — the FQCN. **Use this for `configure_tools`.** NOT `call_name` and NOT `tool_name` (both removed in v2).
    - `plugin_slug` — `null` for core tools, the slug (e.g. `"weather"`) for plugin tools. Used internally to look up the plugin; **NOT** what goes in `required_plugins[]`.
    - `required_plugins[]` for `create_agent` — a list of Composer `vendor/name` package strings (e.g. `["spora-ai/spora-plugin-weather"]`). Send the package name; the importer resolves it to the installed plugin. NOT the FQCN and NOT the slug.
   - `enabled` — current state on the calling agent (informational; new agents start fresh).
   - `ready_to_enable` — whether configure will succeed without operator setup.
   - `missing_required` — list of setting keys that block enablement (e.g. `["api_key"]`).
   - `operations[]` — each `{name, description, enabled, requires_approval}`. Convert to `[{name: "..."}]` objects before placing inside `configure_tools`'s `tools[].operations`.

## Minimal-toolset protocol

Prefer fewer tools. Each one is an attack surface and a cost. Ask the user only when the answer materially changes the design:

- If the user said exactly which tools to enable, do that.
- If the request is open-ended ("a weather agent"), decide on the smallest reasonable toolset (e.g. weather plugin's API tool + the core Time tool).
- If two intents are ambiguous, ask **one** short clarifying question — never invent.

Prefer core tools (`plugin_slug: null`) over plugin tools when both would suffice. Plugins add install/runtime cost and operator approval.

## Canonical agent manifest (the wire shape)

Every agent read/write operation speaks this shape. `result_content` is the Markdown wrapper; `result_data` is the same structure as structured JSON.

````markdown
## Agent #6 — Weather Agent

1 of 18 tools enabled. 0 missing required config.

### Base config

```json
{
  "agent_id": 6,
  "name": "Weather Agent",
  "description": "Answers weather questions…",
  "system_prompt": "You are the Weather Agent…",
  "notes": null,
  "template_id": null,
  "version": null,
  "max_steps": 10,
  "allow_followup": true,
  "retry_after_minutes": 0,
  "max_retries": 0,
  "is_pinned": false,
  "is_archived": false,
  "is_favorite": false
}
```

### Tool config

```json
{
  "tools": [
    {
      "tool_class": "Spora\\Plugins\\Weather\\Tools\\WeatherApiTool",
      "icon": "sun",
      "enabled": true,
      "operations": [
        { "name": "current",  "enabled": true, "requires_approval": false },
        { "name": "forecast", "enabled": true, "requires_approval": false },
        { "name": "search",   "enabled": true, "requires_approval": false },
        { "name": "astronomy","enabled": true, "requires_approval": false }
      ]
    }
  ],
  "missing_required": [],
  "warnings": []
}
```
````

Key invariants:

- `agent_id` is the numeric primary key. **`template_id` is no longer an identifier** — templates are creation labels, and multiple agents can share one. Always carry forward the numeric `agent_id` from `create_agent` (or `list_agents`).
- Per-tool entries in `tools[]` carry only `tool_class`, `icon`, `enabled`, and `operations[]` — slim by design, since `read_agent` / `update_agent` / `configure_tools` responses run on every LLM turn. Browsing-style enrichment (`display_name`, `description`) stays on `get_available_tools` (operator-facing). Pin the slim shape in your reply so an upstream change can't silently bloat the response.
- `tools[]` lists every registered tool (with `enabled: true|false`) so you can see at a glance what's active and what isn't. Per-tool `operations[]` carries the effective `enabled` / `requires_approval` state after per-agent overrides fold in.
- The Markdown preamble adds a `Disabled: ClassA, ClassB, …` line under the status line when at least one tool is disabled. The line is omitted entirely on the all-enabled case so the all-green path stays clean.
- `overrides[]` carries the per-operation audit trail — `{tool_class, operation, enabled, default_requires_approval}` rows for every op where the operator actively overrode the tool's default. **`enabled` and `default_requires_approval` are nullable**: `null` means the operator kept the tool's default for that field, `true|false` means the operator explicitly set it. This is what proves a `configure_tools` patch with `auto_approve: true` actually persisted — the `tools[i].operations[j].requires_approval` effective value would show `false` either way, but `overrides[]` is the only place that records "the operator's call landed on this op".
- `missing_required: ["<tool_class>:<setting_key>", ...]` lists configuration that blocks enablement. `[]` means no blockers.
- `warnings: []` is empty on success. Operator-upload templates may populate it with `TOOL_PLUGIN_MISSING` notes.

## Two-phase flow (LLM-facing)

The LLM does NOT create an agent and configure its tools in one call. Use:

1. **`create_agent`** — slim skeletal record. Capture the `agent_id` from the response.
2. **`configure_tools`** — apply the toolset. Pass `agent_id: <the new id from step 1>` so the right agent gets configured.
3. **`read_agent(agent_id: <the new id>)`** — verify the toolset is exactly what you wanted.

Call them in order. The result_content of each ends with the canonical manifest (via the two-JSON-block Markdown wrapper) so you can confirm what landed without a follow-up read.

## Sub-agent isolation

AgentTool itself ships with **inconsistent defaults by design**. Verbatim from `app/Tools/AgentTool.php`:

| Operation | `enabledByDefault` | `requiresApprovalByDefault` |
| --- | --- | --- |
| `read_notes`, `write_notes`, `list_agents` | `true` | `false` |
| `read_agent`, `get_available_tools` | `false` | `false` |
| `update_agent`, `create_agent`, `configure_tools`, `write_notes_overwrite` | `false` | `true` |

In plain terms: a freshly-enabled sub-agent **can read its own notes, append notes, and list its sibling agents**, but it cannot create / edit / configure any agent (including itself) without an operator tap. That's the safe default for a research or worker agent.

If you need to fully lock the sub-agent out of `agent` — i.e. exclude the tool entirely from its manifest — disable the `agent` tool at the agent-level in the dashboard, not per-operation. Per-op `enabled: false` keeps the entries visible in the manifest so you can audit the intent (the operator can still see "this op is disabled on purpose"), while a fully-disabled tool entry is dropped from `tools[]` entirely.

The Markdown preamble of every `read_agent` / `configure_tools` response shows the disabled FQCNs in a single `Disabled: ClassA, ClassB, …` line so the operator can scan the perimeter at a glance without parsing the trailing `tools[]` block.

### Step 1 — `create_agent`

```json
{
  "action": "create_agent",
  "payload": {
    "name": "Weather Agent",
    "description": "Answers weather questions: current conditions, forecasts, location search, and astronomy (sunrise/sunset, moon phase) worldwide.",
    "system_prompt": "You are the 'Weather Agent'. Use the Weather API tool to answer questions about weather, forecasts, and astronomy (sunrise/sunset, moon phase). Reply in the user's language. If a location is ambiguous, ask briefly or use the location-search operation. Always state the timezone when the user asks for a time.",
    "max_steps": 10,
    "allow_followup": true,
    "required_plugins": ["spora-ai/spora-plugin-weather"]
  }
}
```

Result content (Markdown wrapper — `result_data` carries the same shape as structured JSON):

```text
Created agent #6 ('Weather Agent'). Configure tools next with
`configure_tools(agent_id: 6, tools: [...])` and verify with
`read_agent(agent_id: 6)`.

## Agent #6 — Weather Agent

0 of 18 tools enabled. 0 missing required config.

### Base config
{ … base-config JSON … }
### Tool config
{ … tool-config JSON … }
```

Capture the `agent_id` from `result_data.agent_id` (or the Markdown preamble) for the next two steps.

### Step 2 — `configure_tools(agent_id: <new id>)`

`configure_tools` accepts an optional `agent_id`. **Always pass it** in the create-then-configure flow so the right agent gets configured; omitting `agent_id` falls back to the calling agent (in-place edit semantics, useful but rarely what you want after `create_agent`):

```json
{
  "action": "configure_tools",
  "agent_id": 6,
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

`configure_tools` returns the canonical manifest of the targeted agent (same shape as `read_agent`). Use `tools[].operations[]` to pick which operations get enabled, and `auto_approve: true` to skip per-call operator approval.

### Step 3 — `read_agent(agent_id: <new id>)`

```json
{
  "action": "read_agent",
  "agent_id": 6
}
```

Returns the canonical manifest for agent #6. The Markdown wrapper in `result_content` lets you scan the pre-flight summary at a glance (`2 of 18 tools enabled. 0 missing required config.`) and inspect the per-tool/per-op state in the trailing JSON blocks. If a tool is missing or an operation is wrong, call `configure_tools(agent_id: 6, tools: [...])` again with the delta — `enabled: false` on a tool entry removes it; `enabled: true` re-adds it.

## Slim `create_agent` payload reference

The LLM-facing `create_agent` accepts only a slim subset of the agent-template schema. The full schema (with `id`, `version`, nested `agent{}`, nested `tools[]`) is reserved for the operator-upload endpoint at `POST /api/v1/agent-templates/import`.

| Key | Required | Type | Description |
| --- | --- | --- | --- |
| `name` | yes | string (1..200 chars) | Top-level agent name. NOT inside any wrapper. |
| `description` | no | string (≤2000 chars) | One-line description shown on the agent card. |
| `system_prompt` | no | string | The agent's persona. Edit later via `update_agent`. |
| `max_steps` | no | int (1..100, default 10) | Max LLM tool-call steps per task. |
| `allow_followup` | no | bool, default true | Whether followup tasks are allowed. |
| `retry_after_minutes` | no | int, default 0 | Cooldown between auto-retries. |
| `max_retries` | no | int, default 0 | Max auto-retries per task. |
| `required_plugins` | no | array of Composer `vendor/name` strings | Each plugin the agent depends on (e.g. `["spora-ai/spora-plugin-weather"]`). NOT slugs and NOT FQCNs. |

`additionalProperties: false` — anything else (including the legacy `id`, `version`, `agent{}`, `tools[]`, `template_id`) is rejected with a literal "send X instead" example.

## `configure_tools` shape (LLM-facing)

`configure_tools(agent_id?, tools: [...])`:

| Field | Required | Description |
| --- | --- | --- |
| `agent_id` | no | Numeric pk returned by `create_agent`. Omit to operate on the calling agent. Cross-user agent ids return "not found". |
| `tools` | yes | Array of `{ tool_class, enabled?, operations?: [{ name, enabled?, auto_approve? }] }`. Empty array is valid (removes everything on the targeted agent — usually not what you want). |

#### Delta syntax

Even for a single-operation change, **send the full tool object** (including `tool_class`) inside `tools[]`. There is no path-tool / op-id dotted-address syntax — every entry is the complete tool record. For example, to flip `TimeTool.now.auto_approve` from true to false:

```json
{
  "action": "configure_tools",
  "agent_id": 6,
  "tools": [
    {
      "tool_class": "Spora\\Tools\\TimeTool",
      "operations": [
        { "name": "now", "auto_approve": false }
      ]
    }
  ]
}
```

If the same entry is sent without `tool_class`, the resolver can't bind the operation to a tool and the call fails validation. Omit a field to keep its current value; send a value to override it.

Each tool entry:

- `tool_class` — FQCN string. **Get this from `get_available_tools`.** NOT `call_name` (v2 removed) and NOT `tool_name` (v2 removed).
- `enabled` — bool, default true. `false` removes the tool from the agent entirely.
- `operations` — array. Omit to inherit the tool's per-operation defaults. Each entry is `{ name, enabled?, auto_approve? }`:
  - `name` (required) — string from `get_available_tools.operations[].name`
  - `enabled` — bool, default true
  - `auto_approve` — bool, default false. When true, the operation runs without per-call operator approval.

## Common mistakes

The slim `create_agent` + `configure_tools(agent_id?)` flow fixes these directly. Each error message carries a literal "send X instead" example.

| Symptom | Cause | Fix |
| --- | --- | --- |
| `do NOT wrap fields in an agent{} block` | Sent legacy `agent: { name, description, ... }` to `update_agent` (this is the right shape for `update_agent`, not `create_agent`) — or sent `agent: {…}` to `create_agent` | For `create_agent`: send the slim flat payload `{ name, description, ... }` at top level. For `update_agent`: keep the `agent: { … }` wrapper — that's the canonical shape. |
| `tools[]` is no longer accepted here | Sent `tools: [...]` inside `create_agent` payload | Create the agent first, then call `configure_tools(agent_id: N, tools: [...])` |
| `required_plugins must be an array of strings` | Sent a bare string (`"weather"`) | Send `"required_plugins": ["spora-ai/spora-plugin-weather"]` — an array of Composer `vendor/name` package strings, NOT the slug |
| `max_steps must be an integer in 1..100` | Sent a string or out-of-range int | Send `max_steps: 10` (integer, not string) |
| `allow_followup must be a boolean` | Sent the string `"true"` | Send `allow_followup: true` (real bool, not string) |
| `\`template_id\` is no longer an identifier` | Sent `template_id: "weather-agent"` to `read_agent`, `configure_tools`, `update_agent`, or `list_agents` | Use the numeric `agent_id` you got from `create_agent` (or `list_agents`) |
| `\`agent_id\` must be a positive integer` | Sent zero, a string, or omitted entirely + couldn't fall back | Use a numeric `agent_id`; if omitted is intended, the agent must exist as the calling agent |
| `configure_tools: operations[0][item]` | Sent inner arrays wrapped as `{item: [...]}` (an OpenAI-tools serialization quirk) | Send the array literally: `[{name: "now", enabled: true}]` — the tool auto-unwraps the `{item: …}` quirk defensively, but plain arrays are preferred |
| `configure_tools: tool entry #N must be an object` | Sent the tool entry as a string or array | Wrap each entry in `{...}` |

After three identical validation errors, **stop and ask the operator** — re-reading this skill won't help if the schema is genuinely unknown to you.

## `update_agent` workflow (in-place edits to **any** agent)

Use this for editing any agent you own, not for creating a new one.

Accepts an `agent_id` (numeric pk) and a partial `agent` object. Omitting `agent_id` patches the calling agent (back-compat path). The result is the canonical manifest (Markdown + `result_data`).

```json
{
  "action": "update_agent",
  "agent_id": 6,
  "agent": {
    "description": "Updated description.",
    "system_prompt": "Updated persona…"
  }
}
```

Allowed keys inside the `agent` object:

- `name`, `description`, `system_prompt`
- `max_steps`, `allow_followup`, `retry_after_minutes`, `max_retries`
- `is_pinned`, `is_archived`, `is_favorite`

**Silent drops:**

- `notes` — stripped by the tool before the DB write. Use `write_notes` (append/prepend) or `write_notes_overwrite` (destructive, requires approval) instead.
- `llm_driver_config_id` — operator-only; stripped silently.
- Any other key — silently dropped at the database layer.

To verify a write took effect, call `read_agent(agent_id: <id>)` (or just `read_agent` for the calling agent) and look at the manifest. If a field you sent is not in the returned payload, it was dropped — don't assume success.

## `read_agent` (read any agent by id; or read self)

- `read_agent` with no `agent_id` reads the calling agent (same semantics as the legacy `read_agent_configuration`, which is a soft-redirect).
- `read_agent(agent_id: N)` reads any agent owned by the user. Cross-user ids return "not found or not owned".
- Result: canonical manifest (Markdown + result_data).

## Approval

- `create_agent` — operator approval per call. Briefly state what the new agent will do before submitting, so the operator can sign off without re-reading the payload.
- `configure_tools` — operator approval per call. Brief summary of the toolset change.
- `update_agent` — operator approval per call. Brief summary of the patch (which `agent_id`, which keys).
- `list_agents` — no approval.
- `read_agent` — no approval.

## Operator-upload shape (file upload endpoint only)

For completeness: an operator uploading an agent template via the dashboard file-upload endpoint (`POST /api/v1/agent-templates/import`) sends the **full** skeleton-plus-toolset in one shot, mirroring `agent-template.schema.json`. The LLM-facing flow deliberately does not see this shape — that's why `create_agent` rejects nested objects with the "do NOT wrap fields in an agent{} block" error. The two surfaces (LLM-facing slim vs. operator-upload full) are explicit by design.

The full schema lives in `agent-template.schema.json` and is exercised by `tests/Unit/AgentTemplates/AgentTemplateImporterTest.php`. Operator-upload templates are also stored at `skills/agent-creation/example.json` for round-trip tests.
