# Agent Loop: Async Architecture

## Overview

The Orchestrator loop is synchronous by design — no external queue daemon is required. `SPORA_SYNC_MODE` controls whether the HTTP request blocks until the agent finishes (`Sync`) or returns immediately with the task queued (`Worker`). The `--daemon` / `--once` flags on `worker:run` determine how the queue is drained.

---

## Worker Modes

Set via env var `SPORA_SYNC_MODE` (default: `true`). Corresponds to the `WorkerMode` enum at `app/Agents/ValueObjects/WorkerMode.php`.

| Mode | `tasks.status` on `start()` | Who calls `tick()` |
|---|---|---|
| `SPORA_SYNC_MODE=true` | `RUNNING` | `start()` calls `tick()` inline. HTTP response blocks until agent completes. |
| `SPORA_SYNC_MODE=false` | `QUEUED` | Default daemon (`worker:run`) or cron (`--once --include-queue`) drains the queue. |

In both modes, multi-step tasks (multiple LLM turns before reaching a terminal state) run synchronously within a single `tick()` chain — the loop calls itself recursively until `COMPLETED`, `FAILED`, or `PENDING_APPROVAL`.

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

```mermaid
stateDiagram-v2
    [*] --> QUEUED : Async: start()/continue()
    [*] --> RUNNING : Sync: start()/continue()

    QUEUED --> RUNNING : Worker claims task

    RUNNING --> COMPLETED : LLM returns text (no tools)

    RUNNING --> PENDING_APPROVAL : Tool calls need approval

    PENDING_APPROVAL --> RUNNING : approve() / reject()

    RUNNING --> FAILED : max_steps or exception
```

*(Sync mode starts directly at `RUNNING`; cron/worker modes use `QUEUED` as the entry point.)*

---

## Worker CLI

**Entry point:** `bin/spora` (via `WorkerRunCommand`)

```bash
# Default: persistent daemon — continuously polls for scheduled runs and QUEUED tasks
php bin/spora worker:run

# One-shot scheduled runs only, then exit
php bin/spora worker:run --once

# One-shot: scheduled runs + QUEUED tasks, then exit
php bin/spora worker:run --once --include-queue

# Orphan reaper: mark stale RUNNING tasks as FAILED, then exit
php bin/spora worker:run --reap-only

# Options
--limit=N         Max QUEUED tasks per poll cycle (0 = unlimited, default: 0)
--sleep=N         Microseconds to sleep when queue is empty (default: 500000)
--stale-minutes=N Minutes before a RUNNING task is considered orphaned (0 = disabled)
--workers=N       Max concurrent child processes (0 = unlimited)
--once            Process due scheduled runs then exit (one-shot)
--include-queue   With --once: also drain the QUEUED task queue
--reap-only       Reap orphaned RUNNING tasks once, then exit
--daemon          Explicit daemon mode (default when no flag is given)
```

### Deployment modes

| Command | Scheduled runs | QUEUED tasks | Exit | Typical use |
|---|---|---|---|---|
| `worker:run` | ✓ | ✓ | Never (until SIGTERM) | VPS/Docker always-on (default daemon) |
| `worker:run --reap-only` | — | — | After one iteration | Maintenance: orphan reaping only |
| `worker:run --once` | ✓ | — | After processing | Cron for scheduled runs |
| `worker:run --once --include-queue` | ✓ | ✓ | After processing | Full cron replacement |

**Cron setup:**
```
# Full queue drain every minute
* * * * * /usr/bin/php /path/to/spora/bin/spora worker:run --once --include-queue >> /storage/worker.log 2>&1
```

The daemon (`--daemon`) uses the same `storage/spora-worker.lock` as the one-shot modes, preventing concurrent workers. After each scheduled run, `next_run_at` is computed using wall-clock `now` (in the schedule's timezone) as the cron reference — not the actual last run time — so arbitrarily-delayed cron invocations are handled correctly without drift.

---

## Mercure SSE (Optional — Docker / FrankenPHP)

When `SPORA_MERCURE_URL` and `SPORA_MERCURE_JWT_KEY` are set, the `Orchestrator` publishes task state changes to a Mercure hub after each `tick()` step (intermediate tool results and `PENDING_APPROVAL` pauses) and after `approve()` / `reject()`. The frontend can subscribe to `task/{id}` topics for real-time updates instead of polling.

When the env vars are not set, `MercurePublisher::publish()` is a no-op — polling remains the default for all deployments.

**Env vars (FrankenPHP native Mercure — no separate service needed):**
```
SPORA_MERCURE_URL=http://localhost/.well-known/mercure
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
