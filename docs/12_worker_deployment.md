# Worker Deployment Guide

This guide covers deployment and operations for the Spora agent worker in `cron` and `worker` modes. For architecture details, see [11_agent_loop_async.md](11_agent_loop_async.md).

---

## Modes at a Glance

| Mode | Startup | Scheduled runs | When to use |
|---|---|---|---|
| `SPORA_SYNC_MODE=true` | N/A | — | Local dev only. HTTP request blocks until agent completes. |
| `SPORA_SYNC_MODE=false` + cron | `php bin/spora worker:run --once --include-queue` every minute | Via `--once` | Shared hosting, low-traffic |
| `SPORA_SYNC_MODE=false` + daemon | `php bin/spora worker:run --daemon` as background process | Every poll cycle | VPS/Docker, persistent, always-on |

---

## `SPORA_SYNC_MODE` Environment Variable

Controls how new tasks are created by `Orchestrator::start()`:

| Value | `tasks.status` on start | Who calls `tick()` |
|---|---|---|
| `true` (default) | `RUNNING` | Called inline in `start()` — HTTP blocks |
| `false` | `QUEUED` | Worker daemon or cron |

When `false`, the worker (daemon or cron) is responsible for calling `tick()` via the QUEUED queue drain. The daemon always processes both QUEUED tasks and due `scheduled_runs_next` entries. The cron mode processes one or both depending on flags.

---

## Cron Mode (Shared Hosting with SPORA_SYNC_MODE=false)

```
* * * * * /usr/bin/php /path/to/spora/bin/spora worker:run --once --include-queue >> /storage/worker.log 2>&1
```

Each invocation:
1. Claims and processes all due `scheduled_runs_next` entries (atomic `UPDATE ... SET status = 'CLAIMED' WHERE status = 'PENDING' AND due_at <= now`)
2. Claims the oldest `QUEUED` task (`lockForUpdate`)
3. Sets it to `RUNNING`
4. Processes it to completion (or `PENDING_APPROVAL`)
5. Exits

**Limitation:** If a task takes longer than one minute, the next cron fire will start a second worker while the first is still running. Both run concurrently — `lockForUpdate` prevents double-claiming the same task, so no data corruption occurs. However, both processes consume memory and CPU, and the LLM provider receives parallel requests. For tasks that regularly exceed 1 minute, use **daemon mode** instead.

---

## Daemon Mode (VPS / Docker)

```bash
php bin/spora worker:run --daemon
```

The daemon polls both the QUEUED task queue and the `scheduled_runs_next` table every iteration. It runs until `SIGTERM` or `SIGINT`.

Options:

| Flag | Default | Description |
|---|---|---|
| `--daemon` / `-d` | — | Run as persistent daemon (exit on SIGTERM/SIGINT) |
| `--limit` / `-l` | `0` (unlimited) | Max QUEUED tasks to process per iteration (0 = unlimited) |
| `--sleep` | `500000` (μs = 0.5s) | How long to sleep when both queues are empty |
| `--stale-minutes` | `60` (config) | Minutes after which a `RUNNING` task is treated as orphaned (0 = disabled) |
| `--workers` / `-w` | `0` (unlimited) | Max concurrent child processes (0 = unlimited, single-threaded) |

### Daemon + Docker Compose

```yaml
worker:
  build: .
  command: php bin/worker.php worker:run --daemon
  restart: unless-stopped
  environment:
    SPORA_WORKER_MODE: worker
    SPORA_SECRET_KEY: ${SPORA_SECRET_KEY}
    SPORA_DATABASE_URL: ${SPORA_DATABASE_URL}
```

`restart: unless-stopped` restarts the container after host reboots or Docker daemon restarts, but **not** after the process exits due to a bug — use a process supervisor (see below).

### Daemon + Systemd

```ini
[Unit]
Description=Spora Agent Worker
After=network.target

[Service]
ExecStart=/usr/bin/php /var/www/spora/bin/worker.php worker:run --daemon
Restart=on-failure
RestartSec=10
User=www-data
WorkingDirectory=/var/www/spora

[Install]
WantedBy=multi-user.target
```

`Restart=on-failure` restarts the daemon after any non-zero exit (crash, OOM kill), with a 10-second backoff. Combine with `guard@.service` or a process manager for precise memory/CPU limits.

### Process Supervisor (supervisord)

```ini
[program:spora-worker]
command=php /var/www/spora/bin/worker.php worker:run --daemon
autostart=true
autorestart=true
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/spora-worker.log
```

`autorestart=true` handles both crashes and unexpected exits. Set `numprocs` > 1 only if your database supports concurrent write transactions safely (PostgreSQL; **not** SQLite).

---

## Stale Task Reaping

Tasks can be orphaned in `RUNNING` when a worker process is killed ungracefully (OOM, `SIGKILL`, server crash) between claiming a task and completing it.

The reaper sweeps all `RUNNING` tasks older than `--stale-minutes` and marks them `FAILED`. It runs:
- **Daemon mode:** At startup, then every 5 minutes regardless of queue state
- **Cron mode:** At startup (before the drain loop)

> **Note:** The reaper runs inside the queue drain loop in daemon mode — it is triggered every 5 minutes even when the queue is non-empty. This ensures orphaned tasks are cleaned up even during sustained high load.

The `--stale-minutes` value should exceed your worst-case LLM round-trip time. Set it generously (at least 5 minutes) to avoid false positives on slow providers.

---

## Concurrent Workers

Only **one worker** can process a given task simultaneously — the `lockForUpdate()` row lock in the claim transaction is the mechanism.

For multiple workers on the same machine:
- **Daemon mode:** Run multiple daemon processes with a process supervisor (`numprocs=2`). Each process is independent; the row lock handles contention.
- **Cron mode:** Running multiple identical cron entries is safe but wasteful — the `lockForUpdate` means only one process processes each task, the others exit immediately with no work to do. Consider a lock file or a single daemon instead.
- **Never run both daemon and cron workers against the same database** — the daemon reaper and cron drain race on the same queue. Pick one.

---

## Single-Instance Enforcement

In daemon mode, a `flock()` lock file at `storage/spora-worker.lock` ensures only one daemon process can run at a time. If a second daemon starts, it exits immediately with an error rather than competing for tasks.

The lock is automatically released if the process crashes (the OS closes the file descriptor).

---

## PENDING_APPROVAL Tasks

Tasks in `PENDING_APPROVAL` status wait for human approval. There is **no automatic timeout** — the task remains paused indefinitely until the user approves or rejects the pending tool calls.

This is intentional: a human should make the decision, and the task can stay paused for as long as needed. If the task must be abandoned, delete it or use the database directly to set its status to `FAILED`.

---

## Health Monitoring

Watch these signals of a healthy deployment:

```sql
-- Tasks stuck in QUEUED (queue not draining)
SELECT COUNT(*) FROM tasks WHERE status = 'QUEUED' AND created_at < NOW() - INTERVAL '5 minutes';

-- Tasks stuck in RUNNING (worker may be dead or stuck on LLM)
SELECT COUNT(*) FROM tasks WHERE status = 'RUNNING' AND updated_at < NOW() - INTERVAL '10 minutes';
```

Alert when either count is non-zero for more than 2 consecutive minutes.

---

## Environment Variables Reference

> **All variables below** can also be set in `config.php` (gitignored, shared-hosting friendly) using the same key names, or via `SPORA_*` env vars which take highest priority. Example: `SPORA_WORKER_STALE_MINUTES=90` env var overrides `worker_stale_minutes: 60` in `config.php`.

| Variable | Default | Config key | Description |
|---|---|---|---|
| `SPORA_SYNC_MODE` | `true` | `sync_mode` | `true` = inline/dev (HTTP blocks), `false` = queued (worker drains) |
| `SPORA_WORKER_STALE_MINUTES` | `60` | `worker_stale_minutes` | Minutes before a `RUNNING` task is considered orphaned (0 = disabled) |
| `SPORA_LLM_TIMEOUT` | `300` | `llm_timeout` | Seconds for LLM API calls (reasoning models may need 300+) |
| `SPORA_TOOL_HTTP_TIMEOUT` | `30` | `tool_http_timeout` | Seconds for tool HTTP requests (web search, calendars, etc.) |
| `SPORA_SECRET_KEY` | — | — | Base64 master encryption key (required for production) |
| `SPORA_DATABASE_URL` | — | `db_*` keys | Database DSN |
| `SPORA_LOG_LEVEL` | `warning` | `log_level` | `debug` \| `info` \| `warning` \| `error` |
| `SPORA_LOG_PATH` | `storage/spora.log` | `log_path` | Path to the log file |
| `SPORA_MERCURE_URL` | — | `mercure_url` | Mercure hub URL for SSE (omit to disable) |
| `SPORA_MERCURE_JWT_KEY` | — | `mercure_jwt_key` | HS256 shared secret for Mercure publisher |
