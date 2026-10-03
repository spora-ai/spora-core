---
name: agent-tool
description: "How to operate the `agent` tool once you have it. Covers: what each of the nine operations does and which are enabled without the operator opting in; adding a skill to your own allowlist via configure_tools settings; the `skills` block on get_available_tools and why `allowed` and `visible[].active` answer different questions; the notes trio (read_notes / write_notes / write_notes_overwrite) and why wholesale replacement is a separate operation. Trigger on: 'what can you configure', 'enable the X tool for yourself', 'add the Y skill to yourself', 'change your own notes', 'which tools do I have', 'what skills can I read'. For BUILDING a new agent (create_agent → configure_tools → read_agent), use the agent-creation skill instead — this one is about operating the tool, not about the new-agent protocol."
license: MIT
compatibility: "Designed for Spora agents with the `agent` tool enabled."
metadata:
  author: spora-ai
  version: "1.0"
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
| `read_agent` | no | no | Any agent the principal may read |
| `get_available_tools` | no | no | The tool inventory. Read-only. |
| `update_agent` | no | **yes** | Editable fields on a target agent |
| `create_agent` | no | **yes** | A new agent |
| `configure_tools` | no | **yes** | Tool enablement, per-op approval, and tool settings |
| `write_notes_overwrite` | no | **yes** | The calling agent's notes, wholesale |

**Everything that writes requires operator approval, and everything that writes is off by
default.** Only the four read-shaped operations plus `write_notes` are on when the tool is
first granted. If a write operation refuses as unavailable, that is the operator's
setting, not a bug — do not try to route around it by reaching for a different operation.

## Notes: three operations, because replacement is not editing

| Operation | Behaviour |
|---|---|
| `read_notes` | Returns the calling agent's markdown notes. |
| `write_notes` | `mode: "append"` (default) adds a segment; `mode: "prepend"` puts it first. Segments join with a blank line. |
| `write_notes_overwrite` | Replaces the whole document. |

`write_notes` takes `content`, and `mode` which it ignores everywhere else. Wholesale
replacement is a **separate operation** on purpose: `update_agent` strips `notes` from its
patch outright, so the only way to destroy operator-curated notes is the one operation
that is disabled by default and demands per-call approval. An LLM that can append can
never silently wipe.

Prefer `write_notes` in `append` mode. Reach for `write_notes_overwrite` only when the
operator has explicitly asked for notes to be replaced, and say so in your one-line
approval summary.

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
`agent_id`. If you are about to configure a *different* agent, its current list is not
what you see here. `read_agent(agent_id: N)` is the way to look before you write.

## Traps

| Trap | What actually happens |
|---|---|
| `tool_class` guessed from the tool's display name | Refused. Get the FQCN from `get_available_tools`. There is no `call_name` and no `tool_name` in the v2 payload. |
| `allowed_skills` sent as a comma-joined string | Refused. It is a JSON array. |
| `allowed_target_agents` sent as `["3","4"]` | Refused. That multi-select is stored as `int[]` — send `[3, 4]`. `allowed_skills` is the opposite: `string[]`. |
| Any `type: 'password'` setting | Refused, always. A credential is the one thing a tool call may not write: the value would land in the call's own recorded arguments, so you could read back the key you just set. Credentials are operator-only. |
| `notes` inside an `update_agent` patch | Stripped silently. Use `write_notes`. |
| Assuming a skill is readable because you can see it | `visible` is not `allowed`. Reading needs the name in your allowlist. |
| Adding a skill then reading it in the same turn | The write is a separate approved call. Read it on the next turn. |

## Approval summaries

Every write operation asks the operator to approve per call. The approval prompt shows
your payload, so one accurate line saves a round trip. State which agent, which tools,
and which keys — for example *"Enabling `email` and granting myself the `email` and
`time-arithmetic` skills"*. Do not describe a self-widening grant as a routine change.
