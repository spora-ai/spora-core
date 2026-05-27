# Security in Spora

---

## Credential Encryption

LLM API keys are encrypted at rest using AES-256-GCM. The secret key is stored at `~/.spora/secret.key` or via `SPORA_SECRET_KEY`.

**Protect this key** — anyone with access can decrypt all stored credentials.

---

## API Authentication

Session-based authentication via `delight-im/auth`. All requests use JSON bodies (currently no CSRF needed).

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/v1/auth/login` | Authenticate |
| POST | `/api/v1/auth/logout` | End session |
| GET | `/api/v1/auth/me` | Current user |

---

## Rate Limiting

| Endpoint Type | Limit |
|---|---|
| Authentication | 5 req/min |

---

## Plugin Risks

Plugins run with full application privileges and can access all credentials. Only install from trusted sources.
