# Spora

**WordPress of AI Agents** — portable, zero-config agent orchestration in PHP 8.2+.

---

## Quick Start

### Local Development

```bash
# Install dependencies
composer install
cd frontend && npm install

# Start dev servers (PHP built-in + Vite)
composer dev
```

Access at **http://localhost:5173** (Vite) — uses SQLite by default, no database server needed.

### Docker Deployment

```bash
# 1. Create env file from template
cp docker/.env.local.example .env.local

# 2. Edit .env.local with your settings
#    - Set SPORA_DB_PASSWORD
#    - Set SPORA_MERCURE_JWT_KEY (generate with: php -r "echo bin2hex(random_bytes(32));")

# 3. Start services
docker compose -f docker/docker-compose.yml up
```

Access at **http://localhost:8081**

For production (ports 80/443):
```bash
docker compose -f docker/docker-compose.yml -f docker/docker-compose.prod.yml up
```

---

## Services (Docker)

| Service | Port (Local) | Port (Prod) | Description |
|---------|-------------|-------------|-------------|
| spora | 8080, 8443 | 80, 443 | FrankenPHP + worker daemon |
| mariadb | 3306 (internal) | 3306 (internal) | MySQL database |

FrankenPHP provides the web server, PHP runtime, and — via its built-in Mercure hub — real-time SSE. **No separate Mercure service is needed.**

---

## Requirements

- PHP 8.2+
- Composer
- Node.js 20+
- npm

For Docker: [Docker Desktop](https://docs.docker.com/desktop/)

---

## Environment

For local overrides, copy `.env.local.example` to `.env.local`:

```bash
cp .env.local.example .env.local
```

Key local dev settings (already defaults in `.env`):
- `SPORA_DB_DRIVER=sqlite` (default — zero config)
- `SPORA_SYNC_MODE=true` (HTTP blocks until agent completes)
- `SPORA_MERCURE_URL=` (empty — SSE disabled, polling works fine)

---

## Common Tasks

### Run migrations / seed database
```bash
# Local
php bin/spora db:seed

# Docker
docker compose -f docker/docker-compose.yml exec spora php bin/spora db:seed
```

### View logs
```bash
# Local
tail -f storage/spora.log

# Docker
docker compose -f docker/docker-compose.yml logs -f spora

# Or inside container
docker compose -f docker/docker-compose.yml exec spora tail -f storage/spora.log
```

### Rebuild after code changes
```bash
docker compose -f docker/docker-compose.yml up --build
```

### Stop services
```bash
docker compose -f docker/docker-compose.yml down
```

### Reset database (Docker)
```bash
docker compose -f docker/docker-compose.yml exec spora rm -f storage/database.sqlite
docker compose -f docker/docker-compose.yml exec spora php bin/spora db:seed
```

Or by removing the volume:
```bash
docker compose -f docker/docker-compose.yml down -v
docker compose -f docker/docker-compose.yml up -d
docker compose -f docker/docker-compose.yml exec spora php bin/spora db:seed
```

---

## Troubleshooting

**Mercure SSE not working**
- Verify `SPORA_MERCURE_URL=http://localhost/.well-known/mercure`
- Check `SPORA_MERCURE_JWT_KEY` matches in both `docker/frankenphp.conf` and `.env.local`
- Run: `curl http://localhost:8081/.well-known/mercure` — should return hub info (or port 80 in production)

**Database connection failed (Docker)**
- Verify `SPORA_DB_HOST=mariadb` in `.env.local`
- Check mariadb container is healthy: `docker compose -f docker/docker-compose.yml ps`
