---
name: time-arithmetic
description: "Add or subtract durations from the current time, or convert an epoch to a human-readable datetime in a chosen IANA timezone. Use when the user asks 'what time will it be in 3 hours', '2 hours ago', 'how many minutes until 5pm', or any other arithmetic on the current time. Covers seconds, minutes, hours, days, weeks, and combinations."
license: Apache-2.0
compatibility: Designed for Spora agents with the `time` tool (now + format operations) and `calculator` tool enabled.
metadata:
  author: spora-ai
  version: "2.0"
---

# Time arithmetic

When the user asks a question that requires arithmetic on the current time, follow this protocol. Do not attempt mental arithmetic on durations — always go through the tools. Mental *string formatting* of a pre-computed epoch is acceptable, but the timezone must always be explicit.

## When to use this skill

Trigger on any of:

- "What time will it be in N (seconds|minutes|hours|days|weeks)?"
- "What time was it N (unit) ago?"
- "How many (seconds|minutes|hours|days) until \<future time\>?", "How long until \<event\>?"
- "What time is it in \<city / IANA zone\> right now?" — use the protocol's step 4 to project the current epoch into the target zone.
- "Set a reminder for N minutes from now" (use this skill for the *calculation*; the reminder itself is out of scope).
- Any sentence that combines a duration with the word "ago", "from now", "until", or "before".

## Tools this skill assumes are enabled

- `time` — operations `now` (returns `{datetime, timezone, epoch}`) and `format` (converts an arbitrary epoch to a datetime in a chosen IANA timezone).
- `calculator` — operation `calculate` (evaluates a math expression and returns the numeric result).

If either is not enabled, the protocol degrades. Without `time(action: "format", ...)`, step 4 becomes mental string formatting of the pre-computed epoch (allowed, but the timezone label MUST still be explicit). Without `calculator(action: "calculate", ...)`, the protocol collapses entirely — report the missing tool to the operator rather than doing mental arithmetic on durations.

> **Tool name and parameter shapes.** Call the `time` tool as `time(action: "now")` / `time(action: "format", ...)` — there is no separate `current_time` tool, and `skill_read` is also not a tool; the canonical read shape is `skill(action: "read", name: "<slug>", filename: "<file>")`. See the `skill` tool reference for the full operation list.

## Constants

| Unit         | Seconds |
| ------------ | ------- |
| minute       | 60      |
| hour         | 3600    |
| day          | 86400   |
| week         | 604800  |

The skill's steps always reduce the duration to seconds before applying it to the epoch.

## Steps

1. **Get the current time** with `time(action: "now")`. Capture both the formatted string and the Unix epoch from the result. Pass only `action: "now"` — the `time` tool's `epoch`/`timezone`/`format` parameters are bound to the `format` operation and would be rejected if supplied to `now`; if you ever need them back, the `format` op below already encodes them.
2. **Normalise the duration** to a single unit (seconds is recommended) using `calculator(action: "calculate", expression: <expr>)`. Use a single expression that combines all units, e.g. `2 * 604800 + 3 * 86400 + 5 * 3600 + 15 * 60`.
3. **Apply** the seconds to the epoch with `calculator(action: "calculate", expression: "<epoch> + <seconds>")` (future) or `calculator(action: "calculate", expression: "<epoch> - <seconds>")` (past). Never do this in your head.
4. **Format** the result back to a human-readable time with `time(action: "format", epoch: <result_epoch>, timezone: "<user's zone or UTC>", format: "human")`. The `epoch`, `timezone`, and `format` arguments are required by *this* operation — the `time` tool's `format` op rejects calls missing `epoch`. The `human` format returns `"YYYY-MM-DD HH:MM:SS <zone>"` which is easy to read aloud. Use `format: "iso8601"` when the user wants machine-readable output.
5. **Report** in the user's original unit. If they asked in hours, reply in hours; if in a different timezone, name the timezone explicitly.

## Edge cases

- **Negative durations** — "30 minutes ago" is a valid request. Subtract instead of adding.
- **Combinations** — sum the unit-seconds first, then apply once. Do not chain multiple `+/-` operations; doing so compounds floating-point drift.
- **DST and named timezones** — `time(action: "now")` returns a single instant in the server's default timezone. If the user says "5pm tomorrow in Tokyo", you still operate on the UTC epoch in step 3; step 4's `timezone: "Asia/Tokyo"` adds the Tokyo offset back via DateTimeZone, so DST transitions in that zone are handled correctly.
- **Past vs. future ambiguity** — "in 30 minutes" is future. "30 minutes" alone is ambiguous; ask if the surrounding context does not disambiguate.
- **Crossing month / year / leap-second boundaries** — `calculator(action: "calculate", ...)` handles epoch arithmetic exactly; you do not need to special-case February 29 or leap seconds. Trust the result.

## Examples

For worked examples, read `examples.md` via `skill(action: "read", name: "time-arithmetic", filename: "examples.md")` (it is in the `files` listing of this skill).