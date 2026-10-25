---
name: sub-agent
description: "Delegate work to another agent: `sub_agent` (default — spawns a child task and wait for its answer), `sub_agent_continue` (follow up on a sub-agent task you already started, and wait for the new answer), or `handover` (the target works in the background; you get no answer back). Trigger on: 'delegate this', 'spawn a sub-agent', 'hand off to N', 'let N take it from here', 'send it to N', 'use the N agent for this'. The critical rules: (a) `sub_agent` is the default — only `handover` when the source agent does NOT need to hear back; (b) for `sub_agent` and `handover` the `prompt` is the ONLY input the target receives — source history and inferred context are NOT carried over, so the prompt must be self-contained; a `sub_agent_continue` prompt is the opposite, the target already has that conversation; (c) the parent reads the child's `final_response` as text on the next tick — if the child put an `asset_id` into that text, the parent chains it into a follow-up tool call."
license: MIT
compatibility: "Designed for Spora agents with the `sub_agent` tool enabled."
metadata:
  author: spora-ai
  version: "1.0"
allowed-tools: sub_agent media
---

# Sub-agent

Three operations on a single `sub_agent` tool. The per-op `description:` on the tool describes each op in one line; this skill describes the rules the ops share, and the one they invert.

## Choosing the operation

| Op | When to use |
| --- | --- |
| `sub_agent` | Parent expects an answer and must keep the chat open. Default. >95% of delegations. |
| `sub_agent_continue` | Parent already has a sub-agent task and wants a change to it — "make the heading X", "add a paragraph". The child keeps its earlier conversation. |
| `handover` | Parent does NOT need an answer. The target works in the background, its answer never comes back, and the source chat stays open and ends on the source agent's own words. |

If you're unsure which to pick, pick `sub_agent`. `handover` is the fire-and-forget path — reach for it only when the source task is truly done and the operator has signalled "send it to N to finish from here".

## Prompt completeness — no discussion allowed

This is the most important section for `sub_agent` and `handover`. State it explicitly: **the target agent has NO access to the source's prior messages, attachments, or runtime context.** The `prompt` parameter is the ENTIRETY of what the target sees. It cannot ask follow-up questions; it must execute.

Include in the prompt:

- **Goal** — one-sentence statement of the deliverable.
- **Background** — every fact the target needs to act. Names, dates, ids, prior decisions, file paths.
- **Decisions already taken** — so the target doesn't reverse them.
- **Pending items** — open threads the target is expected to resolve.
- **Verbatim quotes** — exact text to preserve. The target cannot reach back to read the source's history.
- **Output constraints** — what shape the target's answer must take (length, format, fields, JSON shape) and what the parent will do with it.

A prompt that says "summarise the conversation above and draft a response" is a bug: there is no "above" for the target. A prompt that says "ask the user for clarification if needed" is also a bug: there is no user to ask; the agent runs cold.

## Continuing a sub-agent task

`sub_agent_continue` takes the task id from the earlier `sub_agent` result — the number in "Sub-agent task #777" (the same number as the `/tasks/777` link).

- **A continuation prompt is NOT self-contained.** This is the inverse of the `sub_agent` rule: the child still holds its earlier conversation, so "change the heading to X" or "add a paragraph about Y" is a valid follow-up. Only restate what the child cannot see — a fact from *this* chat, a new constraint, a changed requirement.
- **Only tasks this conversation started can be continued.** A task id from another conversation is refused.
- **A continuation waits for the answer**, exactly like `sub_agent`: the parent suspends until the child answers, and the answer reaches the parent on the next tick as the child's `final_response`. The tool result itself only says the task continues — it carries no answer.

## Handover is fire-and-forget

After a `handover`, the target agent works in the background. The source chat stays open, and the delegation is fire-and-forget — the target's answer never comes back to this conversation. Tell the user who is handling it and end your turn.

## Target allowlist

The parent picks from the configured `allowed_target_agents` setting, resolved to `"Name (#id)"` labels (e.g. `"Legal Agent (#11)"`). The picker surfaces only same-principal agents. The tool re-validates the principal match at runtime (`isTargetAllowed`) and the service layer enforces a final `callerControlsPrincipal` check. The LLM picks from the labels it sees — never invent a target, never bypass the picker.

## Returned assets — only what the child wrote

The parent reads the child's `final_response` as text inside a `role:'tool'` history row on the next tick. If the child put an `asset_id` (or any other id) into that text, the parent chains it into a follow-up tool call — `media get_media` for an asset, etc. The parent does NOT see the child's structured tool-result payloads directly — only what the child chose to write in its final response.

Practical rules for the parent:

- Read the child's `final_response` as authoritative. Don't paraphrase, don't summarise, don't drop ids.
- If the child put an asset id in the text, chain it into a `media` follow-up.
- If the child finished and the parent doesn't know what to do next, ask the user before re-spawning. Re-spawning without a plan loops forever.

## Examples

`sub_agent` returning a render that the parent then embeds:

```json
{
  "op": "sub_agent",
  "target_agent_id": "Typst Renderer (#11)",
  "prompt": "Render the attached .typ source to PNG at 144 ppi. Return the resulting asset_id on a single line, no commentary."
}
```

Followed by the parent, on its next tick, embedding the result:

```json
{ "op": "get_media", "asset_id": "<the id the sub-agent returned>" }
```

`sub_agent_continue` asking the same child for a change — the prompt only carries what the child cannot see:

```json
{
  "op": "sub_agent_continue",
  "task_id": "777",
  "prompt": "Use 144 ppi instead, and drop the caption."
}
```

`handover` to hand a triage chat to a specialist and end the turn without waiting:

```json
{
  "op": "handover",
  "target_agent_id": "Billing Agent (#7)",
  "prompt": "User was triaged in this chat about invoice #4188. The billing question has been confirmed as a refund request. Goal: process the $42 refund via the existing refund flow and confirm back to the user in plain English. The user wants this resolved today."
}
```
