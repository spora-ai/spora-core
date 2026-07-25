# Worked examples

Each example shows the exact tool calls and the intermediate results. The agent should follow the same shape: query time → reduce to seconds → apply to epoch → format.

## Example 1 — "What time will it be in 3 hours?"

```
current_time.now()              → { "datetime": "2026-07-25 14:32:00 UTC", "epoch": 1753453920 }
calculator.calculate("3 * 3600") → 10800
calculator.calculate("1753453920 + 10800") → 1753464720
```

Format the resulting epoch back to a UTC timestamp: `2026-07-25 17:32:00 UTC`.

Reply: *"In 3 hours it will be 17:32 UTC."*

## Example 2 — "2 days, 3 hours, and 15 minutes ago"

Reduce the duration to seconds in a single expression so the agent does not chain operations:

```
calculator.calculate("2 * 86400 + 3 * 3600 + 15 * 60") → 183300
calculator.calculate("1753453920 - 183300")            → 1753270620
```

Format: `2026-07-23 11:17:00 UTC`.

Reply: *"2 days, 3 hours, and 15 minutes ago it was 2026-07-23 11:17 UTC."*

## Example 3 — "How many minutes until midnight (UTC)?"

The agent computes seconds-until-next-midnight from the current epoch, then converts to minutes:

```
current_time.now() → epoch 1753453920
calculator.calculate("86400 - (1753453920 % 86400)") → 28800
calculator.calculate("28800 / 60")                    → 480
```

Reply: *"There are 480 minutes (8 hours) until midnight UTC."*

## Example 4 — "Half an hour from now, in seconds"

This is a unit-conversion request, not a time-arithmetic one — but the same protocol applies.

```
calculator.calculate("30 * 60") → 1800
```

Reply: *"30 minutes = 1800 seconds."*

## Example 5 — DST boundary ("What time is it in NYC right now?")

This is *not* a duration question — it is a timezone-conversion question. The arithmetic skill is the wrong tool. Acknowledge the limit and suggest the user invoke a timezone-aware tool instead (or compute the offset manually if the agent has the current UTC offset for that zone).
