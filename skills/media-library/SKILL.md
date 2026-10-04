---
name: media-library
description: "Search, retrieve, embed, share, and produce media assets (images, audio, video, documents) from the media library. Trigger on any of: 'show this asset', 'render to PNG', 'list derivatives', 'fetch the source code', 'mint a share link', 'convert the derivative', 'what files do we have', 'media archive search', or any request that mentions an `asset_id` from a prior media tool call. The skill explains which operation fits the intent, how returned `asset_id`s chain into follow-up calls, and the size/mime constraints that decide between inline-bytes and public-URL retrieval."
license: MIT
compatibility: "Designed for Spora agents with the `media` tool enabled."
metadata:
  author: spora-ai
  version: "1.0"
---

# Media library

Eight operations on a single `media` tool: `search`, `get_media`, `get_embed_code`, `get_public_url`, `get_source`, `list_derivatives`, `create_derivative`, and `create_media`. The body of this skill is what the per-op `description:` lines on the tool no longer have room to say — pick the right op, chain `asset_id`s correctly, and respect the size/mime gates.

## Operation matrix

| Op | `enabledByDefault` | `requiresApprovalByDefault` | Caller outcome |
| --- | --- | --- | --- |
| `search` | true | false | Paginated list of `media_assets` rows |
| `get_media` | true | false | Metadata + markdown embed for one asset |
| `get_public_url` | true | **true** | Mint or fetch shareable URL |
| `get_embed_code` | true | false | Clean embed snippet, no header |
| `get_source` | true | false | Read text bytes or extracted markdown |
| `list_derivatives` | true | false | Derivative rows for a parent asset |
| `create_derivative` | true | false | Fresh derivative, idempotent on (parent, format, producer_plugin, producer_operation) |
| `create_media` | true | false | New source asset from authored text, **not idempotent** |

All eight are on by default, so a `media` call needs no prior opt-in. `get_public_url` is the only op that asks the operator to approve each call — it is the one that mints a link which keeps working outside the session. Every other op reads or writes a row the calling agent already owns, under the same `scope` rules as `get_media`.

If a call to `get_public_url` comes back as an approval request rather than a URL, that is the gate working. Do not retry it in a loop, and do not try to route around it with `get_media` — the embed it returns is a session-authenticated `/api/v1/assets/<uuid>` path, not a shareable link.

An operator can still disable an op or clear its approval per-agent in the dashboard; that narrows what you are offered without a code change.

## Choosing the right op

Walk the intent through this decision tree in prose, not as a flowchart.

Want to **show** a previously-created asset in chat? Use `get_media`. The response is a markdown embed (image / audio / video / link) preceded by a one-line header (`Media asset <id>: <​filename>`) and followed by the extracted text preview. Echo the embed block verbatim so the chat UI renders inline; drop the header line and the extracted-text block from your final reply.

Want a **clean** markdown snippet only — no asset header, no extracted text? Use `get_embed_code`. Same embed, but the response is the bare markdown string. Use this when the parent is composing a structured reply that should not carry the asset's prose context.

### Document assets render as a download card, not a link

The `document` bucket (`text/*` and `application/*` — Markdown, PDF, CSV, JSON, XML, YAML, HTML, plus anything a plugin's converter registers) no longer embeds as a bare markdown link. It embeds as a single-line download card:

```html
<div class="inline-flex max-w-120 my-[0.6rem] rounded-lg border border-foreground/10 bg-muted"><a class="flex min-w-0 items-center gap-2.5 rounded-lg px-3 py-2 text-inherit no-underline transition-colors hover:bg-primary/10 focus-visible:outline-2 focus-visible:outline-offset-[-1px] focus-visible:outline-ring spora-file-card__glyph" href="/api/v1/assets/<uuid>.pdf"><span class="min-w-0 flex-auto truncate font-medium">report.pdf</span><span class="shrink-0 text-xs text-muted-foreground">12.4 KB</span></a></div>
```

Those class names are Tailwind utilities, and they are the whole styling mechanism — spora-frontend registers this exact list with `@source inline(...)` because the string is built in PHP that Tailwind's scanner never reads. A class that is not on that list renders as an unstyled card with no error anywhere. So treat the markup above as fixed: do not rename a class, drop one as "noise", or add one. If the card ever needs a new utility, it has to be added to both files in the same change.

This is one `MediaType::Document` render, not a per-op or per-producer format — `get_media`, `get_embed_code`, `create_media`, and `create_derivative` all produce the identical block for the same asset. **Echo the whole block verbatim.** Do not rewrite the label, rename the file, or hand-roll a markdown link in its place. The filename and the `href` are the entire contract; the `div`/`span` elements around them are presentational and there is no icon element to reproduce and no `aria-hidden` attribute for you to add. If you need prose *about* the document, write it above or below the card — it carries nothing beyond the filename and the file size. The size is omitted when the archive does not know it, and there is no MIME: the extension on the filename already says what the file is.

The `href` is the session-authenticated `/api/v1/assets/<uuid>` route, not the public share URL, so a click forces a download. To hand the user an externally shareable link, use `get_public_url` as before.

Want to **browse the archive**? Use `search`. Filters: `mime_type` (bucketed by mime prefix — see below), `plugin_slug` (exact match), `limit` (default 24, capped at 100), `offset`. **`task_id` is currently reserved-but-unused on `search`; don't rely on it as a filter.** **Derivatives are filtered out of `search`** — `search` returns only "primary" assets, so the listing is not drowned by PNG/PDF/SVG renders of the same source. To fetch a derivative of a known parent, call `get_media(asset_id: <parent_id>)` and read `derivatives[]` off the result; do NOT call `search` to re-locate the derivative.

### `mime_type` is a coarse bucket, not a LIKE

`search` does NOT do a LIKE on `media_assets.mime_type`. The argument is mapped through `MediaType::fromMime($mime)` to one of the five coarse buckets (`image`, `audio`, `video`, `document`, `unknown`) and applied via `WHERE media_type = '<bucket>'`. So:

- `mime_type: "image/png"` → returns every row whose `media_type` bucket is `image`. It does NOT narrow to PNG specifically.
- `mime_type: "text/html"` → returns the `document` bucket, which covers all `text/*` and most `application/*` mimes.
- An unrecognised mime → returns the `unknown` bucket.

Pick the bucket that matches what you're after; don't expect exact-mime filtering.

The same argument means something different on `create_media`, and the difference is the whole reason the op is safe. There, `mime_type` is a **declared hint, never a claim**: the byte ingest path always re-sniffs the payload and ignores the declared value. A hint that is not on the operator's allowlist is rejected before anything is stored (the failure message lists what *is* allowed), and a hint that *is* allowed but whose bytes sniff to a non-allowlisted type is stored, found on re-gate, deleted, and rejected. Either way the call fails. **So `data.mime_type` on the response — not the value you sent — is the authoritative type.** Read it back; never report the type you requested as though it had been confirmed.

Want a **shareable external link**? Use `get_public_url`. Mints a public access token on first call (persists a token on the asset row), then returns the stable URL. The one op that asks the operator to approve every call, so expect a prompt before the URL comes back. The URL uses the operator-configured `app_url`, not the per-request host — so it's stable across requests and not vulnerable to Host-header spoofing.

Want to **read bytes** to iterate on the source (re-typeset a `.typ`, re-ingest an extracted document, etc.)? Use `get_source`. Mime shapes decide what comes back:

- **Text-shaped** mimes (`text/*`, `application/json`, `application/xml`, `application/yaml`, `application/x-yaml`, `application/svg+xml`, `application/csv`, `application/x-typst`) return the bytes inline, capped at 5 MiB. Above the cap the call fails with a hint to use `get_media` for the public URL.
- **Binary** mimes do NOT return raw bytes — `get_source` returns the asset's extracted `markdown_content` (truncated to 8 KB) when the operator populated it during ingestion. That's the shape an LLM can iterate on. When no `markdown_content` exists the call fails with a hint to `get_media` / `list_derivatives`.
- **External** assets (`storage_mode=external`) fail with a hint to use `get_media` for the source URL — there's no Spora-side payload to read.

Want the **renders** of a known source? Use `list_derivatives`. Pass `format` to narrow to one derivative kind (e.g. only PNG). Each row carries `media_id`, `format`, `asset_url`, `label`, `producer_plugin`, `producer_operation`, `created_at` — the same shape the operator dashboard's VersionsStrip renders, so the LLM and operator see identical rows.

Want to **render a source** into a fresh derivative? Use `create_derivative`. Pick a `format` that matches a registered producer for the parent's MIME/extension (e.g. "png" for a `.typ` source, "thumbnail-256" for an uploaded image). **Idempotent on `(parent_id, format, producer_plugin, producer_operation)`** — re-rendering returns the existing derivative id without producing a new row, so a blind retry is the safe pattern even though a render can take seconds. Auto-approved: the derivative is a new row derived from a parent you already own, and it appears in the operator's media library like any other.

Want to **put authored text into the archive** so it can be rendered, shared, or read back later? Use `create_media`. Arguments:

- `content` (required) — the text, stored verbatim. Capped at 1 MiB; a larger call fails with the actual byte count and the limit, so split long documents rather than truncating them yourself.
- `filename` (required) — sanitised on the way in (`basename()`, control characters stripped, 255-character cap). The extension implied by `mime_type` is appended when you leave it off. Always name the file: the name is what the download card shows.
- `mime_type` (optional, default `text/markdown`) — a hint; see the re-sniff note above. Accepts whatever the operator's allowlist covers for text: `text/plain`, `text/markdown`, `text/csv`, `text/html`, `application/json`, `application/xml`, `text/xml`, `application/yaml`, `text/yaml`.
- `prompt` (optional) — provenance, persisted on `media_assets.prompt` and read back by `get_media`. Use it for the brief the content was written from, not for the content itself.

The response is the usual asset header plus a download card, and `data` carries `asset_id`, `asset_url`, `filename`, `mime_type`, and `byte_size`. There is no `files` array and no `op` discriminator: the card comes from the response content, so `data` stays a flat metadata bag you can read in one hop. Use the returned `asset_id` as the parent for a follow-up `create_derivative` — that is the whole intended chain (author Markdown → `create_derivative` → DOCX/PDF render).

`data.asset_url` is the same `/api/v1/assets/<uuid>` route as the card's `href`, and that route is gated on the requester being the asset's owner or an admin. Treat it as a reference for your own follow-up calls, never as a link to hand over — another tool, another person, or a plain `fetch` of it resolves to nothing. The only URL on these operations that survives that is the one from `get_public_url`: a different path, `/api/v1/public/media/<id>?token=…`, resolved by token match with no session at all. So when the user asks for a link, mint one with `get_public_url` and expect the approval prompt — that is the operation the gate is on, and the token is why it is worth gating.

## Returned assets — never lose the id

Every `media` op that produces or surfaces an asset returns its id on the wire. The wire key is NOT always `asset_id` — it varies per op:

| Op | Wire key |
| --- | --- |
| `search` per row | `items[].id` |
| `get_media` | `data.id` (also the markdown header `Media asset <id>: <​filename>`) |
| `get_public_url` | `data.asset_id` |
| `get_embed_code` | `data.asset_id` |
| `get_source` | `data.asset_id` |
| `list_derivatives` | `data.parent_id` (parent) + `derivatives[].media_id` (children) |
| `create_derivative` | `data.derivative_id` |
| `create_media` | `data.asset_id` |

Always pass the id from the same op that returned it; do not assume the key is `asset_id` everywhere. Downstream calls — versions, source bytes, public URL, derivative renders, parent lookup — take that same id. The LLM must keep the id verbatim in its memory and pass it back; do NOT re-issue a `search` to refind an asset id that was just handed back. `search` returns 24 rows at a time and you have no guarantee the asset you just saw is on the next page.

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

## `create_media` is NOT idempotent

`create_derivative` above can be retried blindly. `create_media` cannot. The byte ingest path has no natural key — dedup only exists on `(tool_call_id, source_url)`, and a tool call that carries authored text has neither — so **every call inserts a new row**. A retry after an ambiguous failure (a timeout, a dropped connection, a worker restart mid-write) leaves you with two near-identical assets and no way to tell which one the operator will see.

So the retry pattern is inverted: **do not call `create_media` again to check whether it worked.** Instead, before retrying, `search` for the filename you used and inspect the newest `media_assets` row. If a matching asset exists, keep its `asset_id` and carry on. If it does not, it is genuinely safe to call `create_media` again. When you already hold an `asset_id` — from this call or any earlier one in the task — reuse it for every downstream `get_media` / `create_derivative` / `get_source` and treat it as the one true id for the document.

To iterate on the content, call `get_source` on the existing asset, edit, then `create_media` under a *new* filename. Two versions side by side is the intended outcome; two rows with the same name is a mistake.

## Approval

Only `get_public_url` asks the operator to approve each call, because it is the one operation that hands out a durable public link to stored bytes. Every other op — `search`, `get_media`, `get_embed_code`, `get_source`, `list_derivatives`, `create_derivative`, `create_media` — is enabled and auto-approved, so there is no prompt to wait on and nothing to justify up front. `create_derivative` and `create_media` are writes, but they only create rows derived from, or owned by, the calling agent, and both show up in the operator's media library.

An operator can narrow any op's approval for a given agent in the dashboard. If a call does come back as an approval request, that is the gate working: make the call once, state in one line what asset and why ("render report.pdf to PNG for inline preview"), and do not retry it in a loop.

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

```json
{ "action": "create_media", "filename": "quarterly-report.md", "mime_type": "text/markdown", "content": "# Q3\n\n…", "prompt": "Q3 revenue summary for the board deck" }
```

```json
{ "action": "create_derivative", "asset_id": "<asset_id from create_media>", "format": "pdf" }
```
