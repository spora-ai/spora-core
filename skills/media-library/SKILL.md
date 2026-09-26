---
name: media-library
description: "Search, retrieve, embed, share, and produce media assets (images, audio, video, documents) from the media library. Trigger on any of: 'show this asset', 'render to PNG', 'list derivatives', 'fetch the source code', 'mint a share link', 'convert the derivative', 'what files do we have', 'media archive search', or any request that mentions an `asset_id` from a prior media tool call. The skill explains which operation fits the intent, how returned `asset_id`s chain into follow-up calls, and the size/mime constraints that decide between inline-bytes and public-URL retrieval."
license: Apache-2.0
compatibility: "Designed for Spora agents with the `media` tool enabled."
metadata:
  author: spora-ai
  version: "1.0"
---

# Media library

Seven operations on a single `media` tool: `search`, `get_media`, `get_embed_code`, `get_public_url`, `get_source`, `list_derivatives`, and `create_derivative`. The body of this skill is what the per-op `description:` lines on the tool no longer have room to say — pick the right op, chain `asset_id`s correctly, and respect the size/mime gates.

## Operation matrix

| Op | `enabledByDefault` | `requiresApprovalByDefault` | Caller outcome |
| --- | --- | --- | --- |
| `search` | true | false | Paginated list of `media_assets` rows |
| `get_media` | true | false | Metadata + markdown embed for one asset |
| `get_embed_code` | true | false | Clean embed snippet, no header |
| `list_derivatives` | true | false | Derivative rows for a parent asset |
| `create_derivative` | true | true | Fresh derivative, idempotent on (parent, format, producer) |
| `get_public_url` | false | true | Mint or fetch shareable URL |
| `get_source` | false | true | Read text bytes or extracted markdown |

The LLM must not enable an op the operator has not opted into. `get_public_url` and `get_source` are off by default because they surface source bytes or external share links — operators enable per-agent in the dashboard.

## Choosing the right op

Walk the intent through this decision tree in prose, not as a flowchart.

Want to **show** a previously-created asset in chat? Use `get_media`. The response is a markdown embed (image / audio / video / link) preceded by a one-line header (`Media asset <id>: `) and followed by the extracted text preview. Echo the embed block verbatim so the chat UI renders inline; drop the header line and the extracted-text block from your final reply.

Want a **clean** markdown snippet only — no asset header, no extracted text? Use `get_embed_code`. Same embed, but the response is the bare markdown string. Use this when the parent is composing a structured reply that should not carry the asset's prose context.

Want to **browse the archive**? Use `search`. Filters: `mime_type` (case-insensitive LIKE on `media_assets.mime_type`), `plugin_slug` (exact match), `limit` (default 24, capped at 100), `offset`. **Derivatives are filtered out of `search`** — `search` returns only "primary" assets, so the listing is not drowned by PNG/PDF/SVG renders of the same source. To fetch a derivative of a known parent, call `get_media(asset_id: <parent_id>)` and read `derivatives[]` off the result; do NOT call `search` to re-locate the derivative.

Want a **shareable external link**? Use `get_public_url`. Mints a public access token on first call (persists a token on the asset row), then returns the stable URL. Off by default, always operator-approved. The URL uses the operator-configured `app_url`, not the per-request host — so it's stable across requests and not vulnerable to Host-header spoofing.

Want to **read bytes** to iterate on the source (re-typeset a `.typ`, re-ingest an extracted document, etc.)? Use `get_source`. Mime shapes decide what comes back:

- **Text-shaped** mimes (`text/*`, `application/json`, `application/xml`, `application/yaml`, `application/x-yaml`, `application/svg+xml`, `application/csv`, `application/x-typst`) return the bytes inline, capped at 5 MiB. Above the cap the call fails with a hint to use `get_media` for the public URL.
- **Binary** mimes do NOT return raw bytes — `get_source` returns the asset's extracted `markdown_content` (truncated to 8 KB) when the operator populated it during ingestion. That's the shape an LLM can iterate on. When no `markdown_content` exists the call fails with a hint to `get_media` / `list_derivatives`.
- **External** assets (`storage_mode=external`) fail with a hint to use `get_media` for the source URL — there's no Spora-side payload to read.

Want the **renders** of a known source? Use `list_derivatives`. Pass `format` to narrow to one derivative kind (e.g. only PNG). Each row carries `media_id`, `format`, `asset_url`, `label`, `producer_plugin`, `producer_operation`, `created_at` — the same shape the operator dashboard's VersionsStrip renders, so the LLM and operator see identical rows.

Want to **render a source** into a fresh derivative? Use `create_derivative`. Pick a `format` that matches a registered producer for the parent's MIME/extension (e.g. "png" for a `.typ` source, "thumbnail-256" for an uploaded image). **Idempotent on `(parent_id, format, producer_plugin, producer_operation)`** — re-rendering returns the existing derivative id without producing a new row. Per-call approval because producers may take seconds.

## Returned assets — never lose the id

Every `media` op that produces or surfaces an asset returns its `asset_id` (UUID). Downstream calls — versions, source bytes, public URL, derivative renders, parent lookup — take that same id. The LLM must keep the id verbatim in its memory and pass it back; do NOT re-issue a `search` to refind an asset id that was just handed back. `search` returns 24 rows at a time and you have no guarantee the asset you just saw is on the next page.

Two specific chains worth memorising:

- `get_media` returns `parent_id` when the asset is itself a derivative. Use that to walk up: `get_media(asset_id: parent_id)` to see the source and its full derivative graph.
- `get_media` returns `derivatives[]` for parent assets — a list of derivative rows with their own `media_id`s. Use those directly for `list_derivatives` / `get_media` / `get_source` follow-ups.

## Scope

The `scope` setting (default `agent`) is operator-configured, NOT an LLM parameter. The LLM does NOT select a scope — the wire contract has no `scope` field on any op.

- `scope=agent` (default) hides assets owned by other agents; only assets created by the calling agent are visible.
- `scope=principal` widens to anything owned by the calling agent's principal — direct uploads by the principal's owner user, plus every asset of every other agent of the same principal.
- Admins (`AuthService::isAdmin()`) bypass scope and see all rows.

## `create_derivative` idempotency

Re-rendering with the same `(parent_id, format, producer_plugin, producer_operation)` returns the existing derivative id without producing a new one. So calling `create_derivative` twice in a row is safe and cheap — the second call is a DB lookup, not a render. This is the safe retry pattern: if you aren't sure whether the previous `create_derivative` succeeded, call it again with the same arguments and check the returned id.

## Approval preview

`get_public_url`, `get_source`, and `create_derivative` all require operator approval per call. Expect an approval prompt before the call returns. Be ready to state what asset and why — a one-line "render report.pdf to PNG for inline preview" is enough.

## Examples

```json
{ "action": "search", "mime_type": "image/", "limit": 12, "offset": 0 }
```

```json
{ "action": "get_media", "asset_id": "0b8a…f31c" }
```

```json
{ "action": "list_derivatives", "asset_id": "0b8a…f31c", "format": "png" }
```

```json
{ "action": "create_derivative", "asset_id": "0b8a…f31c", "format": "thumbnail-256" }
```
