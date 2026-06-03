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
* * * * * /usr/bin/php /path/to/spora/bin/spora worker:run --once --include-queue >> /path/to/spora/storage/worker.log 2>&1
```

Each invocation:
1. Claims one due `scheduled_runs_next` entry (atomic `UPDATE ... SET status = 'CLAIMED' WHERE status = 'PENDING' AND due_at <= now` with `LIMIT 1`) and processes it
2. Claims the oldest `QUEUED` task (`lockForUpdate`)
3. Sets it to `RUNNING`
4. Processes it to completion (or `PENDING_APPROVAL`)
5. Exits

Backlog (further due scheduled runs and queued tasks) is picked up by the next cron fire, one entry per minute.

**Limitation:** If a task takes longer than one minute, the next cron fire will start a second worker while the first is still running. Both run concurrently — `lockForUpdate` prevents double-claiming the same task, so no data corruption occurs. However, both processes consume memory and CPU, and the LLM provider receives parallel requests. For tasks that regularly exceed 1 minute, use **daemon mode** instead.

---

## Daemon Mode (VPS / Docker)

```bash
php bin/spora worker:run --daemon
```

The daemon polls both the QUEUED task queue and the `scheduled_runs_next` table every iteration. It runs until `SIGTERM` or `SIGINT`.

Options (defined at `app/Console/Commands/WorkerRunCommand.php:61-68`):

| Flag | Default | Description |
|---|---|---|
| `--daemon` / `-d` | on (if neither `--once` nor `--reap-only`) | Run as persistent daemon (exit on SIGTERM/SIGINT) |
| `--once` | off | Process due `scheduled_runs_next` (and QUEUED tasks with `--include-queue`), then exit. Cron mode. |
| `--include-queue` | off | With `--once`: also drain the QUEUED task queue in the same run |
| `--reap-only` | off | Reap orphaned RUNNING tasks once, then exit. No queue processing. |
| `--limit` / `-l` | `0` (unlimited) | Max QUEUED tasks to process per run (0 = unlimited) |
| `--sleep` / `-s` | `500000` (μs = 0.5s) | Microseconds to sleep when the queue is empty |
| `--stale-minutes` | `60` (config) | Minutes after which a `RUNNING` task is treated as orphaned (0 = disabled; omit to use config) |
| `--workers` / `-w` | `0` (unlimited, config) | Max concurrent child processes (0 = unlimited, single-threaded) |

`--daemon`, `--once`, and `--reap-only` are mutually exclusive (`app/Console/Commands/WorkerRunCommand.php:81-92`). If none is given, the command defaults to daemon mode.

### Daemon + Docker Compose

```yaml
worker:
  build: .
  command: php bin/spora worker:run --daemon
  restart: unless-stopped
  environment:
    SPORA_SYNC_MODE: "false"
    SPORA_SECRET_KEY: ${SPORA_SECRET_KEY}
    SPORA_DB_HOST: ${SPORA_DB_HOST}
    SPORA_DB_NAME: ${SPORA_DB_NAME}
    SPORA_DB_USER: ${SPORA_DB_USER}
    SPORA_DB_PASSWORD: ${SPORA_DB_PASSWORD}
```

`restart: unless-stopped` restarts the container after host reboots or Docker daemon restarts, but **not** after the process exits due to a bug — use a process supervisor (see below).

### Daemon + Systemd

```ini
[Unit]
Description=Spora Agent Worker
After=network.target

[Service]
ExecStart=/usr/bin/php /var/www/spora/bin/spora worker:run --daemon
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
command=php /var/www/spora/bin/spora worker:run --daemon
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
- **Daemon mode:** Run multiple daemon processes with a process supervisor (`numprocs=2`). Each process is independent; the row lock handles contention. (Note: the daemon's single-instance `flock` enforces only one daemon per host — the row lock is what allows multiple daemons across hosts to share the queue safely.)
- **Cron mode:** Running multiple identical cron entries is safe but wasteful — the `flock` lock file means only one process runs at a time, the others exit immediately. Use a single cron entry instead.
- **Never run both daemon and cron workers against the same database** — the daemon reaper and cron drain race on the same queue, and the second one will fail to acquire the `flock`. Pick one.

---

## Single-Instance Enforcement

A `flock()` lock file at `storage/spora-worker.lock` prevents two worker processes (daemon or `--once`) from running against the same database at the same time (`app/Console/Commands/WorkerRunCommand.php:113-117, 429-445`). If a second worker starts, it exits immediately with an error rather than competing for tasks. `--reap-only` skips the lock so multiple reapers can co-exist.

The lock is automatically released if the process crashes (the OS closes the file descriptor).

---

## PENDING_APPROVAL Tasks

Tasks in `PENDING_APPROVAL` status wait for human approval. There is **no automatic timeout** — the task remains paused indefinitely until the user approves or rejects the pending tool calls.

This is intentional: a human should make the decision, and the task can stay paused for as long as needed. If the task must be abandoned, delete it or use the database directly to set its status to `FAILED`.

---

## Health Monitoring

Watch these signals of a healthy deployment (SQLite syntax — see footnote for MySQL/MariaDB):

```sql
-- Tasks stuck in QUEUED (queue not draining)
SELECT COUNT(*) FROM tasks WHERE status = 'QUEUED' AND created_at < datetime('now', '-5 minutes');

-- Tasks stuck in RUNNING (worker may be dead or stuck on LLM)
SELECT COUNT(*) FROM tasks WHERE status = 'RUNNING' AND updated_at < datetime('now', '-10 minutes');
```

Alert when either count is non-zero for more than 2 consecutive minutes.

> **MySQL / MariaDB:** replace `datetime('now', '-N minutes')` with `NOW() - INTERVAL N MINUTE` (e.g. `NOW() - INTERVAL 5 MINUTE`).

---

## Environment Variables Reference

See [17_env_vars.md](17_env_vars.md) for the consolidated reference. Most variables can also be set in `config.php` (gitignored, shared-hosting friendly) using the same `snake_case` config keys, or via `SPORA_*` env vars which take highest priority. Example: `SPORA_WORKER_STALE_MINUTES=90` env var overrides `worker_stale_minutes: 60` in `config.php`.
