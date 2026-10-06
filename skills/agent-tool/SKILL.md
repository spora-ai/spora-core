---
name: agent-tool
description: "How to operate the `agent` tool once you have it. Covers: what each of the nine operations does and which are enabled without the operator opting in; adding a skill to your own allowlist via configure_tools settings; the `skills` block on get_available_tools and why `allowed` and `visible[].active` answer different questions; the notes trio (read_notes / write_notes / write_notes_overwrite) and why wholesale replacement is a separate operation. Trigger on: 'what can you configure', 'enable the X tool for yourself', 'add the Y skill to yourself', 'change your own notes', 'which tools do I have', 'what skills can I read'. For BUILDING a new agent (create_agent → configure_tools → read_agent), use the agent-creation skill instead — this one is about operating the tool, not about the new-agent protocol."
license: MIT
compatibility: "Designed for Spora agents with the `agent` tool enabled."
metadata:
  author: spora-ai
  version: "1.0"
allowed-tools: agent
---

# The `agent` tool

Nine operations on one tool. This skill is the reference for **operating** it: what each
operation may touch, which ones the operator has to switch on, and the traps that fail
silently if you assume the obvious.

**This is not the new-agent protocol.** To create, scaffold or configure a *different*
agent, use the **agent-creation** skill — it owns the `create_agent` → `configure_tools`
→ `read_agent` sequence, the slim manifest shape, and the minimal-toolset rule. Read
that one first if the task is "make me an agent". Read this one if the task is "you have
the `agent` tool — now what".

## The nine operations

| Operation | Enabled by default | Approval | What it may touch |
|---|---|---|---|
| `list_agents` | yes | no | Agents the calling principal can see |
| `read_notes` | yes | no | The calling agent's own notes |
| `write_notes` | yes | no | The calling agent's own notes (append/prepend) |
| `read_agent` | **no** | no | Any agent the principal may read |
| `get_available_tools` | **no** | no | The tool inventory. Read-only. |
| `update_agent` | **no** | **yes** | Editable fields on a target agent |
| `create_agent` | **no** | **yes** | A new agent |
| `configure_tools` | **no** | **yes** | Tool enablement, per-op approval, and tool settings |
| `write_notes_overwrite` | **no** | **yes** | The calling agent's notes, wholesale |

**Every configuration operation is off by default and approval-gated** — `update_agent`,
`create_agent`, `configure_tools`, `write_notes_overwrite`. Two of the three operations on
by default are reads (`list_agents`, `read_notes`); the third is `write_notes`, which can
only append to your own notes.

**This bites the self-widening path specifically.** `get_available_tools` and
`configure_tools` are *both* off by default, so if the operator granted you the `agent`
tool without enabling them, the sequence below fails at step one with an
"operation not available" refusal. That is the operator's setting, not a bug — say which
operations you need rather than looking for another route in.

`read_agent` is off by default too, so the read-back you would normally use to verify a
change may be unavailable.

## Turning tools on and off

`enabled` is **tri-state**, and the middle state is the one that bites:

| You send | What happens |
|---|---|
| `"enabled": true` | The tool is enabled on the target agent |
| `"enabled": false` | The tool is **removed** from the target agent |
| key omitted | **Nothing changes.** An entry carrying only `settings` or `operations` never grants the tool by accident |

So `{"tool_class": "X", "settings": {...}}` configures X without turning it on. That is
what you want almost every time — configure a tool the operator has already enabled,
rather than enabling it as a side effect of a settings write.

**`enabled` accepts `true` / `false`, the strings `"true"` / `"false"`, or `0` / `1`.** Some
providers flatten scalars into strings. A quoted value is read as **the value it names**,
not as truthy — so `"false"` still disables. That distinction is the whole ballgame: a
loose read would turn every revocation into a grant. What is refused is a value that
names no flag at all: `"yes"`, `2`, `null`.

**`tools: []` does nothing.** It is not a revoke-all. To strip a toolset, read the
agent's current tools and send each one back with `"enabled": false`.

Per-operation entries are checked the same way, and the operation name must be one the
tool actually declares:

```json
{ "action": "configure_tools",
  "agent_id": 6,
  "tools": [{
    "tool_class": "Spora\\Tools\\TimeTool",
    "enabled": true,
    "operations": [
      { "name": "now", "enabled": false },
      { "name": "format", "auto_approve": true }
    ]
  }]
}
```

A name that does not exist is refused, and the refusal lists the ones that do. A typo
would otherwise write an override row for an operation that never fires — invisible in
the manifest, so it would read to you as "nothing landed" while you believe you revoked
something. `auto_approve: true` means the operation stops asking the operator.

Every refusal is atomic: one bad entry anywhere in `tools` writes nothing, not even the
entries that were fine.

## Notes: three operations, because replacement is not editing

| Operation | Behaviour |
|---|---|
| `read_notes` | Returns the calling agent's markdown notes. |
| `write_notes` | `mode: "append"` (default) adds a segment; `mode: "prepend"` puts it first. Segments join with a blank line. |
| `write_notes_overwrite` | Replaces the whole document. Empty `content` is **refused**, not treated as a no-op. |

`write_notes` takes `content`, and `mode` which it ignores everywhere else. Wholesale
replacement is a **separate operation** on purpose: `update_agent` strips `notes` from its
patch outright, so the only way to destroy operator-curated notes is the one operation
that is disabled by default and demands per-call approval. An LLM that can append can
never silently wipe.

Prefer `write_notes` in `append` mode. Reach for `write_notes_overwrite` only when the
operator has explicitly asked for notes to be replaced, and say so in your one-line
approval summary.

**Empty content behaves differently on the two paths, deliberately.** `write_notes` with
`content: ""` is a no-op — that keeps repeated calls from stacking separators. But
`write_notes_overwrite` with `content: ""` is **refused**, because "replace with nothing"
cannot be told apart from "leave them alone", and reporting the second as a successful
"Notes unchanged" would tell you your clear worked when nothing happened. If the operator
wants the notes actually gone, that is a settings-panel action — say so rather than
retrying. A single space *is* content and is accepted.

## Quoted scalars are read, not cast

Every scalar this tool accepts — `enabled`, `auto_approve`, `allow_followup`,
`is_pinned`, `is_archived`, `max_steps`, `retry_after_minutes`, `max_retries`, and any
`type: 'toggle'` tool setting — may arrive quoted, and sometimes does. Some providers
flatten scalars into strings.

A quoted value is read as **the value it names**, never as truthiness:

| You send | It is read as |
|---|---|
| `"true"`, `"false"`, `true`, `false`, `1`, `0` | the boolean it names |
| `"25"` for `max_steps` | the number 25 |
| `"yes"`, `"on"`, `2`, `null` | refused — names no flag |
| `"12.5"` for `max_steps` | refused — not a whole number |

Quoting never buys you a way around a bound. `"25"` is accepted and `"999"` is
refused, because the value is coerced **first and range-checked second**. The same
holds on `update_agent`: the whole patch is checked, and one bad field means nothing
is written.

If you ever see a stored flag disagree with what you sent, this is why it matters —
the old behaviour cast the string, so `"false"` archived an agent instead of
unarchiving it and reported success while doing it.

## Finding an agent again

`list_agents` returns every agent you can see, newest first, as
`{agent_id, name, description, is_archived}`.

**Archived agents are listed, flagged — not hidden.** `is_archived: true` on the row and
`(archived)` in the rendered list. That is deliberate: `update_agent` can set
`is_archived` back to `false`, so hiding them would strand an archived agent with no way
back through this tool. Check the flag before treating a row as a candidate for
delegation or reconfiguration, and use `read_agent` when you need the rest.

## Giving yourself a skill

The `skill` tool reads, and it cannot grant itself anything. **You** widen your own
allowlist, through the `agent` tool:

```json
{
  "action": "configure_tools",
  "tools": [
    {
      "tool_class": "Spora\\Tools\\SkillTool",
      "enabled": true,
      "settings": { "allowed_skills": ["time-arithmetic", "email"] }
    }
  ]
}
```

Omit `agent_id` to target **yourself**. Supply it to configure a different agent.

**This replaces the whole list. It does not append.** Whatever you send becomes the
complete `allowed_skills` for that agent. To add one skill, read the current list first
and send it back with the addition — otherwise you silently drop everything that was
already granted. This is deliberate: a write that appends pins whatever the inherited
group-level entries happened to be at the moment of the call.

**You can only read your own list.** `allowed` in the `skills` block is the calling
agent's. `read_agent` returns a slim manifest — `tool_class`, `icon`, `enabled`, and
per-operation state — and carries **no setting values at all**, for you or anyone else.
There is no LLM-facing way to see another agent's current `allowed_skills`.

So for `agent_id` other than your own, a replace is a blind write that will wipe whatever
is there. In that case, do not guess: tell the operator you need the list, or have them
make the change in the settings panel. A blind replace of another agent's allowlist is the
one genuinely destructive thing this tool can do to somebody else's configuration.

The names must come from `get_available_tools`. See below.

## The `skills` block

`get_available_tools` returns a `version: 2` payload with a `skills` block:

```jsonc
"skills": {
  "allowed": ["time-arithmetic"],                  // your effective list, right now
  "visible": [                                     // every skill this principal can see
    { "name": "time-arithmetic", "description": "…", "active": true  },
    { "name": "email",            "description": "…", "active": false }
  ]
}
```

`allowed` and `visible[].active` are **not** the same question, and the difference matters:

- `visible[]` is bounded by the principal. It is what you are allowed to *ask about*.
- `active` marks which of those are in your list.
- `allowed` is your **effective** list after the whole settings cascade, so it can name
  entries that are not in `visible[]` — an inherited group-level grant, or a skill whose
  principal scoping has since changed.

**Write only names that appear in `visible[]`.** A name outside it is refused outright,
naming the offender and the whole call fails. A refused call writes nothing at all, not
even the entries that were fine — that is intentional, so a partially-applied grant can
never read as a complete one.

`allowed` describes the **calling** agent, because `get_available_tools` takes no
`agent_id` — passing one does nothing there. Names you take from it are always names the
executing principal can see, so they will not be refused. But if you are about to configure
a *different* agent, note that you have no way to read that agent's current list.

## Traps

| Trap | What actually happens |
|---|---|
| `"enabled": "yes"` / `2` / `null` | Refused. Only `true`, `false`, `"true"`, `"false"`, `0` and `1` are read as a flag. |
| `"enabled": ""` | Read as **no change**, not as `false`. Tri-state can express "unspecified", so a malformed empty value must not revoke a tool. |
| `{tool_class: X}` with no `enabled` | Enablement is left alone. It does not enable X. |
| `tools: []` expecting a revoke-all | Nothing changes. Send each tool back with `enabled: false`. |
| An operation name the tool does not declare | Refused, with the valid names listed. A typo would otherwise write a dead override row that never shows up in the manifest. |
| `tool_class` guessed from the tool's display name | Refused. Get the FQCN from `get_available_tools`. The text payload carries `tool_class` and `display_name`; `tool_name` exists only in the result's `data` field, which you do not read. |
| `allowed_skills` sent as a comma-joined string | Refused. It is a JSON array. |
| `allowed_target_agents` sent as `["3","4"]` | Refused. That multi-select is stored as `int[]` — send `[3, 4]`. `allowed_skills` is the opposite: `string[]`. |
| Any `type: 'password'` setting | Refused, always. A credential is the one thing a tool call may not write: the value would land in the call's own recorded arguments, so you could read back the key you just set. Credentials are operator-only. |
| A `type: 'select'` value outside the options the tool declares | Refused, with the legal option keys listed. An unlisted value is not rendered by the settings form's dropdown at all, so the operator's next save overwrites it with the default — "landed, then reverted". Read the legal values off the refusal. |
| Replacing another agent's `allowed_skills` | Allowed, and blind — you cannot read their current list. Ask the operator instead. |
| `notes` inside an `update_agent` patch | Stripped silently. Use `write_notes`. |
| `llm_driver_config_id` in an `update_agent` patch | Refused, and the whole patch with it. It decides which model and credentials the agent runs on — operator territory, and you cannot read the valid ids. |
| `write_notes_overwrite` with `content: ""` | Refused, not a no-op. Clearing notes is operator-only. |
| Assuming a skill is readable because you can see it | `visible` is not `allowed`. Reading needs the name in your allowlist. |
| Enabling the `agent` tool and expecting `configure_tools` | Six of nine operations are off by default, including both halves of the self-widening path. Ask the operator to enable them. |
| Adding a skill then reading it in the same turn | The write is a separate approved call. Read it on the next turn. |

## Approval summaries

Every write operation asks the operator to approve per call. The approval prompt shows
your payload, so one accurate line saves a round trip. State which agent, which tools,
and which keys — for example *"Enabling `email` and granting myself the `email` and
`time-arithmetic` skills"*. Do not describe a self-widening grant as a routine change.
