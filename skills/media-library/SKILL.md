---
name: media-library
description: "Search, retrieve, embed, share, and produce media assets (images, audio, video, documents) from the media library, and organise them in folders. Trigger on any of: 'show this asset', 'render to PNG', 'list derivatives', 'fetch the source code', 'mint a share link', 'convert the derivative', 'what files do we have', 'media archive search', 'file this in a folder', 'create a folder for these documents', or any request that mentions an `asset_id` from a prior media tool call. The skill explains which operation fits the intent, how returned `asset_id`s chain into follow-up calls, the size/mime constraints that decide between inline-bytes and public-URL retrieval, and how the folder tree is shaped."
license: MIT
compatibility: "Designed for Spora agents with the `media` tool enabled."
metadata:
  author: spora-ai
  version: "1.0"
allowed-tools: media
---

# Media library

Fourteen operations on a single `media` tool: the eight that read and produce media (`search`, `get_media`, `get_embed_code`, `get_public_url`, `get_source`, `list_derivatives`, `create_derivative`, `create_media`) and the six that organise it in folders (`list_folders`, `create_folder`, `rename_folder`, `delete_folder`, `file_asset`, `search_assets`). The body of this skill is what the per-op `description:` lines on the tool no longer have room to say — pick the right op, chain `asset_id`s correctly, and respect the size/mime gates.

## Where the answer is

Every read operation answers in the **response text**, one line per row, with the handles on the line:

```
Found 12 media asset(s).
- 0b8a…f31c quarterly-report.md — text/markdown, 2417 bytes
- 4c2e…9a10 holiday.png — image/png, 184320 bytes
Showing 2 of 12 — call `search` again with offset: 2 for the next 2.
```

The tool also returns the same rows as structured data, but that copy is stored for the operator's dashboard and the audit trail — it does **not** reach you. If a row is not in the text, you do not have it. So: read the ids off the lines, and keep them; never re-issue a `search` to recover an id you were just handed.

The count on the first line is the number of **matches**, not the number of rows. The rows below it are the page you asked for. Page with `offset` when the count is larger than the page.

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
| `create_media` | true | **true** | New source asset from authored text, **not idempotent** |
| `list_folders` | true | false | The principal's folders |
| `create_folder` | true | **true** | A new folder in the principal's tree |
| `rename_folder` | true | **true** | A renamed folder; the path follows |
| `delete_folder` | true | **true** | A deleted folder; its assets fall back to the root |
| `file_asset` | true | false | An asset filed into a folder, or back into the root |
| `search_assets` | true | false | The assets inside one folder |

All fourteen are on by default, so a `media` call needs no prior opt-in. Five ops ask the operator to approve each call — `get_public_url`, `create_media`, `create_folder`, `rename_folder` and `delete_folder` — because each of them changes durable state the agent cannot undo. Every other op reads or writes a row the calling agent already owns, under the same `scope` rules as `get_media`.

**That table is the shipped default, not the live truth.** An operator can change any operation's approval for a given agent in the dashboard, and that override wins over the default in both directions. When it matters — when a call comes back as an approval request, or when you want to know whether one will — read the live state with the `agent` tool: `read_agent` lists every operation with its `requires_approval` flag, and `get_available_tools` does the same for every registered tool. Read it; do not assume the table still holds.

If a call to `get_public_url` comes back as an approval request rather than a URL, that is the gate working. Do not retry it in a loop, and do not try to route around it with `get_media` — the embed it returns is a session-authenticated `/api/v1/assets/<uuid>` path, not a shareable link. The same is true of `create_media` and of the three folder mutations: an approval request is the gate, not a failure.

## Choosing the right op

Walk the intent through this decision tree in prose, not as a flowchart.

Want to **show** a previously-created asset in chat? Use `get_media`. The response is a markdown embed (image / audio / video / link) preceded by a one-line header (`Media asset <id>: <filename>`) and followed by the extracted text preview. Echo the embed block verbatim so the chat UI renders it as an element; drop the header line and the extracted-text block from your final reply. A parent asset also gets a **Derivatives of this asset:** section listing each render with its own id and url — that is how you walk parent → child without a second call.

Want a **clean** markdown snippet only — no asset header, no extracted text? Use `get_embed_code`. Same embed, but the response is the bare markdown string. Use this when the parent is composing a structured reply that should not carry the asset's prose context.

### What each media type renders as

`get_media`, `get_embed_code`, `create_media` and `create_derivative` all render the same asset identically. The type decides the shape:

- **Image** — a markdown image: the filename as the alt text, the asset url as the target. `image/svg+xml` is in this bucket, not the document one, so an SVG's markdown is a picture rather than a card.
- **Audio** — an `<audio>` element: `<audio controls preload="metadata" src="/api/v1/assets/0b8a…f31c.mp3"></audio>`
- **Video** — a `<video>` element, with the width and height when the archive knows them: `<video controls playsinline width="1280" height="720" src="/api/v1/assets/0b8a…f31c.mp4"></video>`
- **Document** (`text/*` and `application/*` — Markdown, PDF, CSV, JSON, XML, YAML, HTML where the operator has permitted it, plus anything a plugin's converter registers) — the download card below.
- **Anything else** — a plain markdown link: the label in square brackets, the asset url as the target.

Echo the block **verbatim** for all four shapes. The `src` / `href` is the session-authenticated `/api/v1/assets/<uuid>` route, so a click forces a download; to hand the user an externally shareable link, use `get_public_url`.

### Document assets render as a download card, not a link

The `document` bucket no longer embeds as a bare markdown link. It embeds as a single-line download card:

```html
<div class="inline-flex max-w-120 my-[0.6rem] rounded-lg border border-foreground/10 bg-muted"><a class="flex min-w-0 items-center gap-2.5 rounded-lg px-3 py-2 text-inherit no-underline transition-colors hover:bg-primary/10 focus-visible:outline-2 focus-visible:outline-offset-[-1px] focus-visible:outline-ring spora-file-card__glyph" href="/api/v1/assets/<uuid>.pdf"><span class="min-w-0 flex-auto truncate font-medium">report.pdf</span><span class="shrink-0 text-xs text-muted-foreground">12.4 KB</span></a></div>
```

Those class names are Tailwind utilities, and they are the whole styling mechanism — spora-frontend registers this exact list with `@source inline(...)` because the string is built in PHP that Tailwind's scanner never reads. A class that is not on that list renders as an unstyled card with no error anywhere. So treat the markup above as fixed: do not rename a class, drop one as "noise", or add one. If the card ever needs a new utility, it has to be added to both files in the same change.

This is one `MediaType::Document` render, not a per-op or per-producer format. **Echo the whole block verbatim.** Do not rewrite the label, rename the file, or hand-roll a markdown link in its place. The filename and the `href` are the entire contract; the `div`/`span` elements around them are presentational and there is no icon element to reproduce and no `aria-hidden` attribute for you to add. If you need prose *about* the document, write it above or below the card — it carries nothing beyond the filename and the file size. The size is omitted when the archive does not know it, and there is no MIME: the extension on the filename already says what the file is.

### A script-capable asset can render as a live widget

Two MIME types are **script-capable**: `text/html` and `image/svg+xml`. Both can carry script, so both are the operator's decision — and it is two decisions: they have to permit script-capable assets at all, and they have a second switch for whether an embed may run script. Both switches are off by default and neither is readable from a tool call, so **never promise the operator a live widget** — produce the asset, say what it is, and let the chat render it as the operator has configured it.

A script-capable asset has **two** renderings, and they are not in the same place:

- **In the tool's own bubble**, the chat can show the asset as a **live embed** — an iframe, next to the markdown the tool returned. The chat reads the tool result's structured data to do that, and that structured data never reaches you, so you cannot inspect it or influence it.
- **In your own message**, only the markdown travels. The assistant's message is sanitised before it is rendered, so raw markup in it is stripped or shown as text and never played. What the operator reads there is the markdown, and it differs per type:

| type | bucket | your message shows |
| --- | --- | --- |
| `text/html` | document | the download card |
| `image/svg+xml` | image | an inline image, and a **static** one — a browser does not run an SVG's script inside an `<img>` |

So an HTML widget reaches the operator as a **download button** in your message with the live one in the bubble above it, and an SVG widget reaches them as a static picture in your message and a live one in the bubble. Echo the card or the image exactly as the tool returned it, and describe what you built in words. The frame is the tool's to render, not yours.

The **filename is the intent**, and the archive honours it. `create_media` re-sniffs the bytes and ignores the mime you declare, but a filename that says `.md` wins over a *prose-shaped* verdict — bytes that look like HTML because the prose carries markup. So:

- A **widget** — a dashboard, a chart, an interactive page — is a `.html` file.
- A **report** is a `.md` file, and it stays a document even when its body is markup-dense.

An SVG has one more limit: it is **write-only** to you. `get_source` returns no bytes for a binary mime, and `image/svg+xml` is binary, so reading an SVG back means creating its `md` derivative first — and there may be none, in which case the call fails and says so.

The embed is served from the session, so the frame needs the operator to be signed in. A share link minted with `get_public_url` opens the asset itself, not the embed.

Want to **browse the archive**? Use `search`. Filters: `mime_type` (bucketed by mime prefix — see below), `plugin_slug` (exact match), `limit` (default 24, capped at 100), `offset`. **`task_id` is currently reserved-but-unused on `search`; don't rely on it as a filter.** **`search` does not filter by filename or free text** — it lists the library's assets, newest first, so the rows to look at are the first ones. **Derivatives are filtered out of `search`** — `search` returns only "primary" assets, so the listing is not drowned by PNG/PDF/SVG renders of the same source. To fetch a derivative of a known parent, call `get_media(asset_id: <parent_id>)` and read the derivative lines; do NOT call `search` to re-locate the derivative.

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
- **Binary** mimes do NOT return raw bytes — `get_source` returns the asset's **`md` derivative** (truncated to 64 KB), prefixed with a line naming the file and saying you are seeing the derivative rather than the original bytes. That's the shape an LLM can iterate on. When no readable `md` derivative exists yet the call **fails** and tells you to call `create_derivative(asset_id: <id>, format: "md")` — do that rather than guessing at the content.
- `get_source` never creates a derivative itself, so a failure here is a real gap, not a transient one.
- **External** assets (`storage_mode=external`) fail with a hint to use `get_media` for the source URL — there's no Spora-side payload to read.

**Not every binary document has an `md` derivative.** The archive mints one *on upload*, and only when a registered producer can extract text from that mime — a PDF with a text layer, yes; a scanned PDF with no text layer, no. A file that arrived as a **derivative** never gets one: a PDF rendered from a Typst source is a derivative row, not an upload, so it has no `md` derivative of its own. The fallback is the same either way — call `create_derivative(asset_id: <id>, format: "md")` and read the result.

Want the **renders** of a known source? Use `list_derivatives`. Pass `format` to narrow to one derivative kind (e.g. only PNG, or `md`). Each row carries `media_id`, `format`, `asset_url`, `label`, `producer_plugin`, `producer_operation`, `created_at` — the same shape the operator dashboard's VersionsStrip renders, so the LLM and operator see identical rows. The response lists one line per derivative: `- <media_id> <format> — <mime_type>, <asset_url>`.

`format` is the producer's **slug**, not a mime: `thumbnail-256` is a preset that emits WebP, so the row says `thumbnail-256 — image/webp`. Read the mime, not the slug, when you need the type.

`list_derivatives` is also the only way to *discover* a document's extracted text. `search` filters derivative rows out, so a PDF's markdown is invisible to it; and `get_media`'s derivative section only appears once you already know the parent id. So when you need a document's text and were handed only the PDF: `list_derivatives(asset_id: <pdf_id>, format: "md")` → then `get_source(asset_id: <pdf_id>)` to read it. Don't try to re-find the derivative with `search`.

Want to **render a source** into a fresh derivative? Use `create_derivative`. Arguments:

- `asset_id` (required) — the parent to render.
- `format` (required) — the producer's preset slug. Core ships `md` (PDF → Markdown) and the image presets `thumbnail-256`, `medium-1024`, `format-png`, `format-jpeg`, `format-webp`; a plugin's producer adds its own (e.g. `png` for a `.typ` source). **When the format is not supported, the failure names the formats that are** — for that asset, from the registered producers — so read the message and pick from the list rather than guessing again.
- `options` (optional) — producer-specific knobs, as an object. Omit it for the defaults. What a producer accepts is the producer's own: the Typst producer takes `{"page": 0, "ppi": 144}`, the image derivatives take `{"longEdge": 1024}`. An unknown key is the producer's to reject; there is no global option vocabulary.

**Idempotent on `(parent_id, format, producer_plugin, producer_operation)`** — re-rendering returns the existing derivative id without producing a new row, so a blind retry is the safe pattern even though a render can take seconds. Auto-approved: the derivative is a new row derived from a parent you already own, and it appears in the operator's media library like any other.

Want to **put authored text into the archive** so it can be rendered, shared, or read back later? Use `create_media`. Arguments:

- `content` (required) — the text, stored verbatim. Capped at 1 MiB; a larger call fails with the actual byte count and the limit, so split long documents rather than truncating them yourself.
- `filename` (required) — sanitised on the way in (`basename()`, control characters stripped, 255-character cap). The extension implied by `mime_type` is appended when you leave it off. Always name the file: the name is what the download card shows.
- `mime_type` (optional, default `text/markdown`) — a hint; see the re-sniff note above.
- `folder_id` (optional) — the folder to file the new asset in. Omit it to file the asset in the root.
- `prompt` (optional) — provenance, persisted on `media_assets.prompt` and read back by `get_media`. Use it for the brief the content was written from, not for the content itself.

**The mime allowlist is the operator's, not a fixed list you can recite.** It covers the text mimes (`text/plain`, `text/markdown`, `text/csv`, `application/json`, `application/xml`, `text/xml`, `application/yaml`, `text/yaml`), the audio and video containers the recording pipeline takes (`audio/webm`, `audio/ogg`, `audio/mp4`, `audio/mpeg`, `audio/mp3`, `audio/wav`, `audio/x-wav`, `audio/x-m4a`, `audio/flac`, `video/webm`, `video/mp4`), every mime a registered derivative producer accepts (core ships `application/pdf`; the Typst plugin adds `text/x-typst`), and the image types (`image/png`, `image/jpeg`, `image/webp`) when your model's driver accepts image input and the operator has not disabled them. **HTML and SVG are not on that list by default.** A browser parses both as a document that can carry script, so the operator governs them with `SPORA_MEDIA_ARCHIVE_ALLOW_ACTIVE_TYPES` — only an install with that toggle on allowlists `text/html` and `image/svg+xml`. A refusal names the allowlist as it stands on that install — read it rather than guessing. **And the broader allowlist does not make `create_media` a binary uploader:** the bytes are re-sniffed, so declaring `image/png` while sending text stores `text/plain`.

**Filenames are unique within a folder, and the root counts as one.** A second `create_media` with a filename the target folder already holds is refused, and the message tells you to find the row your earlier call created (see *`create_media` is NOT idempotent* below) or to store this one under a different name. The archive never holds two rows with the same name in one folder — the second one could not be filed anywhere.

The response is the usual asset header plus a download card, and the structured data carries `asset_id`, `asset_url`, `filename`, `mime_type`, and `byte_size`. There is no `files` array and no `op` discriminator: the card comes from the response content, so the data stays a flat metadata bag you can read in one hop. Use the returned `asset_id` as the parent for a follow-up `create_derivative` — that is the whole intended chain (author Markdown → `create_derivative` → DOCX/PDF render).

`data.asset_url` is that same owner-or-admin route, so it is a handle for your own follow-up calls and never a link to hand over. `get_public_url`'s is the exception — a different path, `/api/v1/public/media/<id>?token=…`, needing no session — which is why it is the one operation the operator approves.

## Returned assets — never lose the id

Every `media` op that produces or surfaces an asset names its id in the response text. Where it is, per op:

| Op | Where the id is |
| --- | --- |
| `search` | one line per row: `- <asset_id> <filename> — <mime_type>, <bytes> bytes` |
| `get_media` | the header line `Media asset <id>: <filename>` |
| `get_public_url` | `Public URL for <id>: <url>` |
| `get_embed_code` | inside the snippet; take the id from `get_media` |
| `get_source` | `Source of <id> (<filename>, N bytes, mime=…)` |
| `list_derivatives` | one line per derivative: `- <media_id> <format> — <mime_type>, <url>` |
| `get_media` (parent) | the `Derivatives of this asset:` section, one `<media_id>` per line |
| `create_derivative` | `Created derivative <id> of <parent_id>` |
| `create_media` | the header line `Media asset <id>: <filename>` |
| `list_folders` | one line per folder: `- <folder_id> <name> — <path>, N asset(s)` |
| `create_folder` | `Created the media folder <path> (id <folder_id>)` |
| `search_assets` | one line per asset: `- <asset_id> <filename> — <mime_type>, <bytes> bytes` |

Always pass the id from the same op that returned it; do not assume the key is `asset_id` everywhere. Downstream calls — versions, source bytes, public URL, derivative renders, parent lookup, folder ops — take that same id. Keep the id verbatim in your memory and pass it back; do NOT re-issue a `search` to refind an asset id that was just handed back. `search` returns 24 rows at a time and you have no guarantee the asset you just saw is on the next page.

Two specific chains worth memorising:

- `get_media` returns `parent_id` when the asset is itself a derivative. Use that to walk up: `get_media(asset_id: parent_id)` to see the source and its full derivative graph.
- `get_media` lists the derivative rows for parent assets — each with its own `media_id`. Use those directly for `list_derivatives` / `get_media` / `get_source` follow-ups.

## Folders

The media library has folders. A folder belongs to the principal, carries a name and a path (`/Corporate`, `/Corporate/2026`), and holds assets. Two rules are enforced by the database: **sibling folders have unique names**, and **filenames are unique within a folder**.

The tree is yours to shape: you may create a folder, rename one and delete one, and you may file assets in it.

| Op | What it does | Approval |
| --- | --- | --- |
| `list_folders` | The principal's folders: `id`, `name`, `path`, `parent_id`, `asset_count` | auto |
| `create_folder` | Create a folder (`name`, optional `parent_id`) | **asks** |
| `rename_folder` | Rename a folder (`folder_id` + `name`); the path of its subfolders follows | **asks** |
| `delete_folder` | Delete a folder; its assets fall back to the root, its subfolders go with it | **asks** |
| `file_asset` | File an asset into a folder (`asset_id` + `folder_id`); omit `folder_id` to move it back into the root | auto |
| `search_assets` | The assets inside one folder (`folder_id`) | auto |

`list_folders` answers with one line per folder — `- <folder_id> <name> — <path>, N asset(s)` — and that id is what every other folder op takes. `create_folder` answers with the new folder's id too, so a folder you just created is one you can file into straight away. Call `list_folders` first when you have no other way to learn a folder id.

A folder name is **one path segment, never a path**: `parent_id` names the folder to sit under, and a name carrying a separator is refused. A sibling name that is taken is refused too — pick another name rather than retrying.

`file_asset` fails when the folder already holds a file with that name — pick another name or another folder rather than retrying. `search_assets` needs a `folder_id`; call `list_folders` first when you do not have one. A `folder_id` that names no folder of yours is refused with "That media folder does not exist." — so an empty folder and a wrong id do not look alike.

All six are scoped to your principal: another principal's folder or asset is never named as forbidden — the answer is an absent folder or an absent asset, the same answer a row that does not exist gets — so there is nothing to probe for.

**You may not move a folder into another folder.** `MediaFolderService::move()` rewrites the path of a whole subtree, and there is no operation for it — create the folder under the parent you want instead.

`create_media` takes an optional `folder_id` too, so a new asset can be filed as it is written. Same rule: a name the folder already holds is refused. Without a `folder_id` the asset lands in the root — file it afterwards with `file_asset` when it belongs to one.

**A folder you need may not exist yet.** When the request names a folder the tree does not have, `create_folder` it first — under the parent the request implies — and then file the asset into it. The assistant can shape the tree; it is not limited to the folders the operator laid down before the task.

## Kinds

Every asset carries a **kind**: `asset` (the default), `template`, or `example`. The kind is **advisory** — it is a label the operator uses to filter the library, and a kind in that set never refuses an asset. A mis-marked template still works, and the operator can change the mark at any time. Never claim that an asset "is not a template" because of its kind; read the content instead.

## Scope

The `scope` setting (default `agent`) is operator-configured, NOT an LLM parameter. The LLM does NOT select a scope — the wire contract has no `scope` field on any op.

- `scope=agent` (default) hides assets owned by other agents; only assets created by the calling agent are visible.
- `scope=principal` widens to anything owned by the calling agent's principal — direct uploads by the principal's owner user, plus every asset of every other agent of the same principal.
- Admins (`AuthService::isAdmin()`) bypass scope and see all rows.

## `create_derivative` idempotency

Re-rendering with the same `(parent_id, format, producer_plugin, producer_operation)` returns the existing derivative id without producing a new one. So calling `create_derivative` twice in a row is safe and cheap — the second call is a DB lookup, not a render. This is the safe retry pattern: if you aren't sure whether the previous `create_derivative` succeeded, call it again with the same arguments and check the returned id.

## `create_media` is NOT idempotent

`create_derivative` above can be retried blindly. `create_media` cannot. The byte ingest path has no natural key — dedup only exists on `(tool_call_id, source_url)`, and a tool call that carries authored text has neither — so **every call inserts a new row**. A retry after an ambiguous failure (a timeout, a dropped connection, a worker restart mid-write) leaves you with two near-identical assets and no way to tell which one the operator will see.

So the retry pattern is inverted: **do not call `create_media` again to check whether it worked.** Instead, before retrying, call `search` and look at the newest row for the filename you used — `search` lists the library newest first, so the row you want is at the top. If a matching asset exists, keep its `asset_id` and carry on. If it does not, it is genuinely safe to call `create_media` again. When you already hold an `asset_id` — from this call or any earlier one in the task — reuse it for every downstream `get_media` / `create_derivative` / `get_source` and treat it as the one true id for the document.

To iterate on the content, call `get_source` on the existing asset, edit, then `create_media` under a *new* filename. Two versions side by side is the intended outcome; two rows with the same name is refused.

## Approval

Five ops ask the operator to approve each call, because each hands out durable state the agent cannot undo:

- `get_public_url` — a public link to stored bytes that keeps working outside the session.
- `create_media` — a new source asset with no natural key, so a retry duplicates the row and a loop has no ceiling.
- `create_folder`, `rename_folder`, `delete_folder` — a change to the tree the operator curates.

Every other op — `search`, `get_media`, `get_embed_code`, `get_source`, `list_derivatives`, `create_derivative`, `list_folders`, `file_asset`, `search_assets` — is enabled and auto-approved, so there is no prompt to wait on and nothing to justify up front. `create_derivative` is a write too, but it only creates a row derived from a parent the calling agent owns, and its idempotency is a DB-enforced UNIQUE index.

An operator can narrow any op's approval for a given agent in the dashboard, and can widen it onto an op that ships auto-approved — `file_asset` moves a row between folders and `create_media` writes one with no natural key, so an operator who does not want their agent filing and renaming unattended will turn those ops on for that agent. **Read the live state with `read_agent` (or `get_available_tools`) when you need to know which ops are gated on this install; the table above is the shipped default, not a promise about this agent.**

If a call does come back as an approval request, that is the gate working: make the call once, state in one line what asset and why ("render report.pdf to PNG for inline preview"), and do not retry it in a loop.

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
{ "action": "create_derivative", "asset_id": "0b8a…f31c", "format": "thumbnail-256", "options": { "longEdge": 1024 } }
```

```json
{ "action": "create_media", "filename": "quarterly-report.md", "mime_type": "text/markdown", "content": "# Q3\n\n…", "prompt": "Q3 revenue summary for the board deck" }
```

```json
{ "action": "create_derivative", "asset_id": "<asset_id from create_media>", "format": "pdf" }
```
