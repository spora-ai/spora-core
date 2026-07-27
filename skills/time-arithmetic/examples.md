# Worked examples

Each example shows the exact tool calls and the intermediate results. The agent should follow the same shape: query time → reduce to seconds → apply to epoch → format.

## Example 1 — "What time will it be in 3 hours?"

```
time(action: "now")
→ { datetime: "2025-07-25T14:32:00+00:00", timezone: "UTC", epoch: 1753453920, weekday: "Friday" }

calculator(expression: "3 * 3600")
→ 10800

calculator(expression: "1753453920 + 10800")
→ 1753464720

time(action: "format", epoch: 1753464720, timezone: "UTC", format: "human")
→ { formatted: "2025-07-25 17:32:00 UTC", weekday: "Friday" }
```

Reply: *"In 3 hours it will be 17:32 UTC."*

## Example 2 — "2 days, 3 hours, and 15 minutes ago"

Reduce the duration to seconds in a single expression so the agent does not chain operations:

```
calculator(expression: "2 * 86400 + 3 * 3600 + 15 * 60")
→ 183300

calculator(expression: "1753453920 - 183300")
→ 1753270620

time(action: "format", epoch: 1753270620, timezone: "UTC", format: "human")
→ { formatted: "2025-07-23 11:17:00 UTC", weekday: "Wednesday" }
```

Reply: *"2 days, 3 hours, and 15 minutes ago it was 2025-07-23 11:17 UTC."*

## Example 3 — "How many minutes until midnight (UTC)?"

The agent computes seconds-until-next-midnight from the current epoch, then converts to minutes:

```
time(action: "now")
→ { datetime: "2025-07-25T14:32:00+00:00", timezone: "UTC", epoch: 1753453920, weekday: "Friday" }

calculator(expression: "86400 - (1753453920 % 86400)")
→ 28800

calculator(expression: "28800 / 60")
→ 480
```

Reply: *"There are 480 minutes (8 hours) until midnight UTC."*

## Example 4 — "Half an hour from now, in seconds"

This is a unit-conversion request, not a time-arithmetic one — but the same protocol applies.

```
calculator(expression: "30 * 60")
→ 1800
```

Reply: *"30 minutes = 1800 seconds."*

## Example 5 — "What time is it in Tokyo right now?"

This is a timezone-display question, not a duration one. The arithmetic skill is still the right entry point because it teaches the `time` discipline.

```
time(action: "now")
→ { datetime: "2025-07-25T14:32:00+00:00", timezone: "UTC", epoch: 1753453920, weekday: "Friday" }

time(action: "format", epoch: 1753453920, timezone: "Asia/Tokyo", format: "human")
→ { formatted: "2025-07-25 23:32:00 Asia/Tokyo", weekday: "Friday" }
```

Note: UTC+9 makes "now" in Tokyo 23:32 same-day in summer (Tokyo does not observe DST), so the result lands later the same day rather than early the next day.

Reply: *"It is 23:32 in Tokyo right now."*

## Example 6 — "1 week and 2 days from now"

Demonstrates the weeks constant (604800):

```
time(action: "now")
→ { datetime: "2025-07-25T14:32:00+00:00", timezone: "UTC", epoch: 1753453920, weekday: "Friday" }

calculator(expression: "1 * 604800 + 2 * 86400")
→ 777600

calculator(expression: "1753453920 + 777600")
→ 1754231520

time(action: "format", epoch: 1754231520, timezone: "UTC", format: "human")
→ { formatted: "2025-08-03 14:32:00 UTC", weekday: "Sunday" }
```

Reply: *"1 week and 2 days from now it will be 2025-08-03 14:32 UTC."*

## Example 7 — "What day of the week was this epoch?"

```
time(action: "format", epoch: 1, timezone: "UTC", format: "human")
→ { formatted: "1970-01-01 00:00:00 UTC", weekday: "Thursday" }
```

Reply: *"Epoch 1 (the first second past the Unix epoch) was a Thursday."*