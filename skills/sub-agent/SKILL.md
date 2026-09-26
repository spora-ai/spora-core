---
name: sub-agent
description: "Delegate work to another agent (`sub_agent` op, default) or close the source chat and start a fresh task on the target without returning (`handover` op). Trigger on: 'delegate this', 'spawn a sub-agent', 'hand off to N', 'let N take it from here', 'send it to N', 'use the N agent for this', 'I need N to do this'. The critical rules: (a) `sub_agent` is the default — only `handover` when the source agent does NOT need to hear back; (b) the `prompt` parameter is the ONLY input the target receives — source history, attachments, and inferred context are NOT carried over, so the prompt must be self-contained; (c) when the target returns assets or tool results, the parent sees them as `role:'tool'` history rows on the next tick — read them, do not paraphrase."
license: Apache-2.0
compatibility: "Designed for Spora agents with the `sub_agent` tool enabled."
metadata:
  author: spora-ai
  version: "1.0"
---

# Sub-agent

Two operations on a single `sub_agent` tool. The per-op `description:` on the tool describes each op in one line; this skill describes the rules both ops share.

## Choosing the operation

| Op | When to use |
| --- | --- |
| `sub_agent` | Parent expects an answer and must keep the chat open. Default. >95% of delegations. |
| `handover` | Source chat is meant to END. Source's `final_response` becomes "Handed off to …" and the operator sees no return value. |

If you're unsure which to pick, pick `sub_agent`. `handover` is the destructive, chat-closing path — only reach for it when the source task is truly done and the operator has signalled "send it to N to finish from here".

## Prompt completeness — no discussion allowed

This is the most important section. State it explicitly: **the target agent has NO access to the source's prior messages, attachments, or runtime context.** The `prompt` parameter is the ENTIRETY of what the target sees. It cannot ask follow-up questions; it must execute.

Include in the prompt:

- **Goal** — one-sentence statement of the deliverable.
- **Background** — every fact the target needs to act. Names, dates, ids, prior decisions, file paths.
- **Decisions already taken** — so the target doesn't reverse them.
- **Pending items** — open threads the target is expected to resolve.
- **Verbatim quotes** — exact text to preserve. The target cannot reach back to read the source's history.
- **Output constraints** — what shape the target's answer must take (length, format, fields, JSON shape) and what the parent will do with it.

A prompt that says "summarise the conversation above and draft a response" is a bug: there is no "above" for the target. A prompt that says "ask the user for clarification if needed" is also a bug: there is no user to ask; the agent runs cold.

## Target allowlist

The parent picks from the configured `allowed_target_agents` setting, resolved to `"Name (#id)"` labels (e.g. `"Legal Agent (#11)"`). The picker surfaces only same-principal agents. The tool re-validates the principal match at runtime (`isTargetAllowed`) and the service layer enforces a final `callerControlsPrincipal` check. The LLM picks from the labels it sees — never invent a target, never bypass the picker.

## Returned assets

When the sub-agent returns tool results — including media assets, attachments, or structured payloads — the parent sees them as `role:'tool'` history rows on its NEXT tick. The parent should:

- Read the rows as authoritative. Don't paraphrase, don't summarise, don't drop ids.
- Chain any `asset_id`s into follow-up `media` tool calls.
- If the sub-agent finished and the parent doesn't know what to do next, ask the user before re-spawning. Re-spawning without a plan loops forever.

## Examples

`sub_agent` returning a render that the parent then embeds:

```json
{
  "action": "sub_agent",
  "target_agent_id": "Typst Renderer (#11)",
  "prompt": "Render the attached .typ source to PNG at 144 ppi. Return the resulting asset_id on a single line, no commentary."
}
```

Followed by the parent, on its next tick, embedding the result:

```json
{ "action": "get_media", "asset_id": "<the id the sub-agent returned>" }
```

`handover` to close a triage chat and start a fresh task on a specialist:

```json
{
  "action": "handover",
  "target_agent_id": "Billing Agent (#7)",
  "prompt": "User was triaged in this chat about invoice #4188. The billing question has been confirmed as a refund request. Goal: process the $42 refund via the existing refund flow and confirm back to the user in plain English. The user wants this resolved today."
}
```
