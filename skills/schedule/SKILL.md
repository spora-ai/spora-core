---
name: schedule
description: "Create, list, update, delete and trigger scheduled runs (cron or one-shot) and prompt templates attached to this agent. Trigger on: 'schedule this', 'remind me tomorrow at 8', 'set up a daily check', 'every Monday at 9am', 'save this prompt as a template', 'what's on my schedule', 'trigger the X job now'. Critical: when a schedule fires later, the agent is spawned with the `raw_prompt` (or the template's rendered prompt) AS THE ONLY INPUT — there is no operator, no chat history, and no live state. The prompt must be self-contained end-to-end: goal, data sources to consult, output format, and what 'done' means."
license: Apache-2.0
compatibility: "Designed for Spora agents with the `schedule` tool enabled."
metadata:
  author: spora-ai
  version: "1.0"
---

# Schedule

Twelve operations on a single `schedule` tool. Six are for scheduled runs, six for prompt templates — the template operations live on the same tool because a schedule almost always references a template, and splitting the surface would force the LLM to switch tools mid-flow.

## Operations split

Schedule operations:

- `list_schedules` — enumerate runs for the calling agent (or `agent_id`).
- `read_schedule` — read one run by `schedule_id`.
- `create_schedule` — create a new run with a `raw_prompt` or `template_id`, a cadence (`cron_expression` or `run_at`), and an optional `timezone`.
- `update_schedule` — patch any subset of a run's fields. Setting one cadence field implicitly clears the other.
- `delete_schedule` — permanently delete a run.
- `trigger_schedule` — fire a run immediately, regardless of its cadence.

Prompt template operations:

- `list_prompt_templates` — enumerate templates for the calling agent (or `agent_id`).
- `read_prompt_template` — read one template by `template_id`.
- `create_prompt_template` — save a template for reuse.
- `update_prompt_template` — patch any subset of a template's fields.
- `delete_prompt_template` — permanently delete a template.

## The prompt is the entire input — readability-first protocol

When the schedule fires — a cron tick lands or a `run_at` instant arrives — the agent runs with ONLY the prompt. There is no operator, no chat history, no live state, no follow-up channel. State this in your reasoning every time you write or update a schedule.

The prompt must:

- State the **goal** and what success looks like.
- Name the **tools / data sources** to consult. Always assume a cold start; do not assume "as we discussed earlier" is reachable. If the schedule needs `media` to fetch a stored asset, say so by name. If it needs `sub_agent` to delegate, say so.
- Specify the **output format**. "Reply to the operator with a one-line summary", "POST to webhook X", "save a derivative and embed it", "write a JSON row to a known path" — pick one and commit.
- Specify what NOT to do — avoid loops, avoid re-prompting the user (there is no user), avoid writing to chat if the output channel is something else.

If you cannot fill in all four, the prompt is incomplete and the schedule will fire into an agent that does not know what success looks like.

## `raw_prompt` vs `template_id`

`create_schedule` accepts EITHER `template_id` (bind to a saved template) OR `raw_prompt` (the literal string). They are mutually exclusive — sending both is rejected. Use a template when you'll reuse the same prompt across schedules or when the prompt needs versioning; use `raw_prompt` when it's a one-off or exploratory.

Templates are first-class: `list_prompt_templates` / `read_prompt_template` / `create_prompt_template` / `update_prompt_template` all work independently of any schedule. A template becomes useful the moment a `create_schedule` binds to it by `template_id`.

## Recurring vs one-shot

`cron_expression` (5- or 6-field cron string) for recurring schedules, `run_at` (ISO 8601 instant) for one-shots. Mutually exclusive — sending both is rejected. `timezone` is IANA, defaults to "UTC".

Switching recurrence modes via `update_schedule`: setting ONE cadence field on a schedule that has the OTHER cadence set implicitly clears the other. `{run_at: <iso>}` switches a recurring schedule to one-shot; `{cron_expression: <cron>}` switches a one-shot to recurring. Never populate both in the same patch.

## `trigger_schedule`

Fires a schedule immediately, independent of cron / `run_at`. Useful for testing a draft schedule before letting it run unattended. Each call is operator-approved (writes a fresh run row). Returns the new `task_id` and the (now-deactivated, for one-shots) schedule resource.

## Cross-agent reads/writes

Any op accepts an optional `agent_id` (numeric pk) for cross-agent access. Omitted `agent_id` resolves to the calling agent. Cross-user ids return a uniform "not found" — existence is hidden between users.

For `list_*` and reads: visibility widens to principal-membership (any user who can see the agent can see its schedules / templates).

For writes, deletes, and triggers: the caller must control the agent's principal (owner or admin). The tool re-validates; never silently falls back to the calling agent when an explicit `agent_id` is supplied.

## Examples

Recurring cron with a raw prompt — daily morning briefing:

```json
{
  "action": "create_schedule",
  "schedule_payload": {
    "name": "Daily morning briefing",
    "raw_prompt": "Compose a 3-bullet morning briefing for the operator. Read the operator's calendar for today via the calendar plugin's list_events operation (assume Europe/Berlin), pull unread email counts via the email plugin, and check the weather in Berlin via the weather plugin's current operation. Output exactly three short bullets, no preamble. If any tool is unavailable, say so on that bullet and continue with the others. Do NOT ask for clarification — there is no operator to ask.",
    "cron_expression": "0 7 * * *",
    "timezone": "Europe/Berlin",
    "is_active": true
  }
}
```

One-shot via a saved template — tomorrow at 8:

```json
{ "action": "list_prompt_templates" }
```

```json
{
  "action": "create_schedule",
  "schedule_payload": {
    "name": "Tomorrow's standup reminder",
    "template_id": 42,
    "run_at": "2026-09-27T08:00:00+02:00",
    "is_active": true
  }
}
```

Trigger a draft schedule immediately to verify the prompt works before letting it run unattended:

```json
{ "action": "trigger_schedule", "schedule_id": 17 }
```
