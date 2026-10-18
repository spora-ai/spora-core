# Agent API

A per-agent HTTP API for external tools, gated behind the **Agent API**
setting. An agent that answers over this API is reachable from a script,
a workflow runner, or another service, without a Spora session and without
the caller having to reconstruct the admin UI's task model.

The API contract lives in [`docs/04_api.md`](04_api.md#agent-api); this
document is the operator's guide to turning it on and keeping it on.

## Turning it on

Two requirements, and the second is not optional:

1. **Agent API** enabled in [Global Settings](04_api.md#agent-api), or
   `SPORA_AGENT_API=1` in `.env`.
2. **Worker runtime mode = Server.** In client-worker mode the browser
   drives the agent loop, so nothing on the server executes a queued task
   and the API answers `404` on every endpoint.

> **The setting is database-backed.** Once you flip it in Global Settings,
> `.env` stops winning — the stored value is the higher layer. Set it in
> one place, not both.

## What is in scope

A key is scoped to **one agent**. Everything it can reach belongs to that
agent: its chats, their transcripts, and the media those chats produced.
It cannot see a sibling agent's chats, and it cannot read the rest of the
account's media.

An agent may have several keys. Mint one per integration, so revoking one
does not take the others down. Deleting a key is permanent and its secret
is unrecoverable — only a SHA-256 digest is stored, and the plaintext is
shown exactly once at creation.

## The API is not a worker

`POST /api/v1/agent-api/chats` answers `202` and returns immediately. It
queues the chat; a worker runs it. Nothing in the HTTP request path drives
the agent loop, which is why a server-side worker is required.

Callback latency therefore depends on your worker:

| Worker | Latency |
| --- | --- |
| `worker:run --daemon` | sub-second — the loop sweeps on every iteration |
| `worker:run --once` from cron | up to one cron interval |

A worker that is *configured* but not *alive* leaves chats `QUEUED` and
callbacks undelivered. The API does not detect this; it is a deployment
health question, and the usual signals apply.

## Callbacks

A caller may pass `callback_url` when starting a chat. Spora POSTs to it
once the chat settles — where "settles" means it reached a terminal state,
was aborted, or parked on something only the caller can clear (an approval,
or a question the agent asked).

Delivery is retried with exponential backoff and signed, so the receiver
can tell a genuine callback from a forged one. See
[`docs/04_api.md`](04_api.md#agent-api) for the payload, the signature
recipe, and the exact set of statuses that trigger a delivery.

## Where to look next

- [`docs/04_api.md`](04_api.md#agent-api) — every endpoint, with its
  exhaustive error list.
- `GET /api/v1/config` — the `agent_api_enabled` flag the admin SPA reads
  to decide whether to show the agent's API keys tab.
- [`app/Core/AgentApiRouteDefinitions.php`](../app/Core/AgentApiRouteDefinitions.php) —
  where the routes are mounted and where the feature gate lives.
