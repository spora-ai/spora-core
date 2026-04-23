# Agent Loop: Async Architecture

## Overview

The Orchestrator loop is synchronous by design — no external queue daemon is required. `SPORA_WORKER_MODE` controls how task execution is initiated, covering three deployment targets without changing any application logic.

---

## Worker Modes

Set via env var `SPORA_WORKER_MODE` (default: `sync`). Corresponds to the `WorkerMode` enum at `app/Agents/ValueObjects/WorkerMode.php`.

| Mode | `tasks.status` on `start()` | Who calls `tick()` |
|---|---|---|
| `SPORA_SYNC_MODE=true` | `RUNNING` | `start()` calls `tick()` inline. HTTP response blocks until agent completes. |
| `SPORA_SYNC_MODE=false` | `QUEUED` | Worker daemon (`--daemon`) or cron (`--once --include-queue`) drains the queue. |

In all three modes, multi-step tasks (multiple LLM turns before reaching a terminal state) run synchronously within a single `tick()` chain — the loop calls itself recursively until `COMPLETED`, `FAILED`, or `PENDING_APPROVAL`.

---

## Tick Structure

`Orchestrator::tick()` runs in three phases to avoid holding a DB lock during the LLM round-trip:

**Phase 1 — Claim (short transaction with `lockForUpdate`)**
- Lock the task row
- Validate `status === 'RUNNING'`
- Increment `step_count`
- Read agent, enabled tools, conversation history, system prompt
- Build the `LLMRequest`
- Commit → lock released

**Phase 2 — LLM call (outside any transaction)**
- Blocking HTTP call to the configured LLM provider
- No DB connection held during I/O

**Phase 3 — Write results**
- If tool calls: `appendHistory`, execute tools, call `tick()` again (or pause for approval)
- If text response: `appendHistory`, set `COMPLETED`

`resume()` and `reject()` each use a short `lockForUpdate()` transaction to execute tool results and write history, then call `tick()` **after** the transaction commits — for the same reason (LLM round-trip must not hold a DB lock).

---

## Task Status Lifecycle

```
QUEUED ──────────────────────────────────────────────────────→ RUNNING
(cron/worker modes only; sync mode starts directly at RUNNING)      │
                                                                     │
                               ┌─────────────────────────────────────┤
                               │                                     ▼
                          PENDING_APPROVAL ←──────────── RUNNING ──→ COMPLETED
                               │                            ▲        │
                               └──── (approve/reject) ──────┘        │
                                                                      ▼
                                                                   FAILED
                                                          (max_steps, exception)
```

---

## Worker CLI

**Entry point:** `bin/spora` (via `WorkerRunCommand`)

```bash
# Default (cron mode): drain QUEUED tasks once, then exit
php bin/spora worker:run

# One-shot scheduled runs: process due scheduled_runs, then exit (ideal for cron)
php bin/spora worker:run --once

# One-shot mixed: process both scheduled runs and QUEUED tasks, then exit
php bin/spora worker:run --once --include-queue

# Daemon: persistent process that handles both QUEUED and scheduled runs every poll cycle
php bin/spora worker:run --daemon

# Options
--limit=N         Max QUEUED tasks to process per run (0 = unlimited, default: 0)
--sleep=N         Microseconds to sleep when both queues are empty (default: 500000)
--stale-minutes=N Minutes before a RUNNING task is considered orphaned (0 = disabled)
--workers=N       Max concurrent child processes in daemon mode (0 = unlimited)
--once            Process due scheduled runs then exit (one-shot)
--include-queue   With --once: also drain the QUEUED task queue
```

### Deployment modes

| Command | Scheduled runs | QUEUED tasks | Exit | Typical use |
|---|---|---|---|---|
| `worker:run` | — | ✓ | After one task | Default cron (sync mode) |
| `worker:run --once` | ✓ | — | After processing | Cron for scheduled runs |
| `worker:run --once --include-queue` | ✓ | ✓ | After processing | Full cron replacement |
| `worker:run --daemon` | ✓ | ✓ | Never (until SIGTERM) | VPS/Docker always-on |

**Cron setup (shared hosting with SPORA_SYNC_MODE=true):**
```
# Full queue drain every minute
* * * * * /usr/bin/php /path/to/spora/bin/spora worker:run --once --include-queue >> /storage/worker.log 2>&1
```

The daemon (`--daemon`) uses the same `storage/spora-worker.lock` as the one-shot modes, preventing concurrent workers. After each scheduled run, `next_run_at` is recomputed using the actual last run time (not wall-clock), so arbitrarily-delayed cron invocations are handled correctly without drift.

---

## Mercure SSE (Optional — Docker / FrankenPHP)

When `SPORA_MERCURE_URL` and `SPORA_MERCURE_JWT_KEY` are set, the `TaskController` publishes task state changes to a Mercure hub after `store()`, `approve()`, and `reject()`. The frontend can subscribe to `task/{id}` topics for real-time updates instead of polling.

When the env vars are not set, `MercurePublisher::publish()` is a no-op — polling remains the default for all deployments.

**Env vars:**
```
SPORA_MERCURE_URL=https://example.com/.well-known/mercure
SPORA_MERCURE_JWT_KEY=your-shared-secret
```

FrankenPHP bundles a Mercure hub natively — no separate service needed in that configuration.

---

## Environment Variables

| Variable | Default | Description |
|---|---|---|
| `SPORA_SYNC_MODE` | `true` | `true` = inline/dev (HTTP blocks), `false` = queued (worker drains) |
| `SPORA_MERCURE_URL` | — | Mercure hub URL. Omit to disable SSE. |
| `SPORA_MERCURE_JWT_KEY` | — | Shared secret for HS256 publisher tokens. |
