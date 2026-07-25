---
name: time-arithmetic
description: "Add or subtract durations from the current time. Use when the user asks 'what time will it be in 3 hours', '2 hours ago', 'how many minutes until 5pm', or any other arithmetic on the current time. Covers seconds, minutes, hours, days, weeks, and combinations."
license: Apache-2.0
compatibility: Designed for Spora agents with CurrentTimeTool and CalculatorTool enabled.
metadata:
  author: spora-ai
  version: "1.0"
---

# Time arithmetic

When the user asks a question that requires arithmetic on the current time, follow this protocol. Do not attempt mental math on durations or timezones — always go through the tools.

## When to use this skill

Trigger on any of:

- "What time will it be in N (seconds|minutes|hours|days|weeks)?"
- "What time was it N (unit) ago?"
- "How many (seconds|minutes|hours|days) until \<future time\>?", "How long until \<event\>?"
- "Set a reminder for N minutes from now" (use this skill for the *calculation*; the reminder itself is out of scope).
- Any sentence that combines a duration with the word "ago", "from now", "until", or "before".

## Steps

1. **Get the current time** with `current_time.now()`. Capture both the formatted string and the Unix epoch.
2. **Normalise the duration** to a single unit (seconds is recommended) using `calculator.calculate(\<expression\>)`. Use a single expression that combines all units, e.g. `2 * 86400 + 3 * 3600 + 15 * 60`.
3. **Apply** the seconds to the epoch with `calculator.calculate(\<epoch\> + \<seconds\>)` (future) or `\<epoch\> - \<seconds\>` (past). Never do this in your head.
4. **Format** the result back to a human-readable time. Reuse the same format that `current_time.now()` returned so the timezone and locale match.
5. **Report** in the user's original unit. If they asked in hours, reply in hours; if in a different timezone, name the timezone explicitly.

## Edge cases

- **Negative durations** — "30 minutes ago" is a valid request. Subtract instead of adding.
- **Combinations** — sum the unit-seconds first, then apply once. Do not chain multiple `+/-` operations.
- **DST and named timezones** — `current_time.now()` returns a single instant. If the user says "5pm tomorrow in Tokyo", you still operate on the UTC epoch; only the format step adds the Tokyo offset back.
- **Past vs. future ambiguity** — "in 30 minutes" is future. "30 minutes" alone is ambiguous; ask if the surrounding context does not disambiguate.
- **Crossing month / year / leap-second boundaries** — the tools handle epoch arithmetic correctly; you do not need to special-case February 29 or leap seconds. Trust the result.

## Examples

For worked examples, read `examples.md` via `skill_read` (it is in the `files` listing of this skill).
