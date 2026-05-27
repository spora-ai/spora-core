# Installing Spora

---

## Requirements

| Requirement | Minimum |
|---|---|
| PHP | 8.4+ |
| Node.js | 18+ |
| Database | SQLite (dev) / MySQL 8+ (prod) |

---

## Docker

```bash
# Create env file
cp docker/.env.local.example docker/.env.local

# Set SPORA_DB_PASSWORD and SPORA_MERCURE_JWT_KEY in .env.local
# Generate a Mercure key: php -r "echo bin2hex(random_bytes(32));"

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
