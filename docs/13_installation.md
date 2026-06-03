# Installing Spora

---

## Requirements

| Requirement | Minimum |
|---|---|
| PHP | 8.4+ |
| Node.js | 20+ (22+ recommended; CI uses 24) |
| Database | SQLite (dev) / MariaDB 11+ or MySQL 8+ (prod) |
| PHP extensions | `curl`, `dom`, `fileinfo`, `iconv`, `json`, `mbstring`, `openssl`, `pdo`, `pdo_mysql`, `pdo_sqlite`, `simplexml`, `sodium`, `zip` (declared in `composer.json`'s `require` block) |

---

## Docker

```bash
# Create env file
cp docker/.env.local.example docker/.env.local

# Set SPORA_DB_PASSWORD and SPORA_MERCURE_JWT_KEY in .env.local
# Generate a 32-byte key (base64): php -r "echo base64_encode(random_bytes(32));"
# Generate a Mercure key (hex):   php -r "echo bin2hex(random_bytes(32));"

# Start services
docker compose -f docker/docker-compose.yml up --build
```

Access at **http://localhost:8081**

---

## Local Development

```bash
composer install
cd frontend && npm install
cp .env.example .env
composer setup
composer dev
```

Access at **http://localhost:5173** — uses SQLite by default.

> **`composer setup` is non-destructive on an existing DB.** It runs `spora:install` then `db:seed`; the seeder skips itself if any users or agents already exist (`app/Console/Commands/SetupCommand.php:48-54`). To wipe the database before setup, run `composer reset` first (or `php bin/spora db:reset --force` for a non-interactive wipe). See `db:reset` below for what the wipe does.
>
> **`db:reset` is destructive.** Wipes the database before re-running migrations. Driver-aware (reads `SPORA_DB_DRIVER`):
>
> - **SQLite** (default): deletes `storage/database.sqlite` and `storage/.schema_stamp`. If the SQLite file is non-empty, the command prompts for confirmation; pass `--force` to skip the prompt in scripts and CI.
> - **MySQL / MariaDB** (`SPORA_DB_DRIVER=mysql`): runs `DROP DATABASE IF EXISTS` followed by `CREATE DATABASE` on the configured `SPORA_DB_NAME` (charset `utf8mb4`, collation `utf8mb4_unicode_ci`). Because this hits a shared server rather than a local file, the MySQL path **always** requires `--force` (or the literal answer `yes` typed at the prompt). Credentials (`SPORA_DB_HOST` / `NAME` / `USER` / `PASSWORD`) are read from the same `.env` / `config.php` chain Spora uses at runtime.
>
> Run directly: `php bin/spora db:reset [--force]`.

---

## Verifying the Installation

```bash
composer analyse && composer test
cd frontend && npm run lint && npm test
```

---

## Next Steps

- Configure LLM drivers in **Settings** → **LLM Configurations**
- Explore the [plugin system](07_plugins.md) to extend Spora
- Review [deployment options](12_worker_deployment.md) for production
