# Agent Loop: Async Architecture

## Overview

The Orchestrator loop is synchronous by design — no external queue daemon is required. `SPORA_WORKER_MODE` controls how task execution is initiated, covering three deployment targets without changing any application logic.

---

## Worker Modes

Set via env var `SPORA_WORKER_MODE` (default: `sync`). Corresponds to the `WorkerMode` enum at `app/Agents/ValueObjects/WorkerMode.php`.

| Mode | `tasks.status` on `start()` | Who calls `tick()` |
|---|---|---|
| `sync` | `RUNNING` | `start()` calls `tick()` inline. HTTP response blocks until agent completes. |
| `cron` | `QUEUED` | Cron fires `php bin/worker.php worker:run` every minute. |
| `worker` | `QUEUED` | `php bin/worker.php worker:run --daemon` runs as a persistent background process. |

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
**Commands:**
```bash
# Process queued tasks (sync or async mode)
php bin/spora worker:run

# Process due scheduled runs (add to crontab)
php bin/spora worker:run --scheduled

# Daemon mode — run until SIGTERM/SIGINT
php bin/spora worker:run --daemon

# Options
--limit=N    Max tasks to process (0 = unlimited, default: 0)
--sleep=N    Microseconds to sleep when queue is empty (default: 500000)
--scheduled  Check due scheduled_runs and dispatch them
```

**Cron setup (shared hosting):**
```
# Worker queue — every minute
* * * * * /usr/bin/php /path/to/spora/bin/spora worker:run >> /storage/worker.log 2>&1

# Scheduled runs — every 10 minutes (or whatever interval the host allows)
*/10 * * * * /usr/bin/php /path/to/spora/bin/spora worker:run --scheduled >> /storage/scheduled.log 2>&1
```

The `--scheduled` flag uses the same `storage/spora-worker.lock` as the regular worker, so they cannot overlap. After each scheduled run, `next_run_at` is recomputed using `dragonmantank/cron-expression` so arbitrarily-delayed cron invocations (common on shared hosts) are handled correctly.

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
| `SPORA_WORKER_MODE` | `sync` | `sync` \| `cron` \| `worker` |
| `SPORA_MERCURE_URL` | — | Mercure hub URL. Omit to disable SSE. |
| `SPORA_MERCURE_JWT_KEY` | — | Shared secret for HS256 publisher tokens. |
