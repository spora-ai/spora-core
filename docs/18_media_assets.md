# Plugin Author Guide — Embedding Media in Tool Results

Many plugins generate binary output: images from a diffusion API, audio from a TTS service, video from a generative model, screenshots from a headless browser, PDFs from a renderer. Returning raw URLs in plain text gets the job done but produces ugly, non-interactive chat bubbles — and any payload the upstream API hands back as inline bytes (hex or base64) is otherwise lost.

Spora provides a small, opinionated pipeline for this:

```
binary bytes ─► AssetStore::store() ─► AssetReference (url, mode)
                                                  │
ToolResult.content ◄──── MediaEmbed::* ◄──────────┘
        │                         │
        ▼                         ▼
   chat UI renders        operators configure
   inline media           via SPORA_* env vars
```

This page walks through each piece.

---

## 1. The two ingredients

### `Spora\Services\AssetStore`

A service interface in `app/Services/AssetStore.php`. Three implementations ship in core:

| Implementation | Mode | Behaviour |
|---|---|---|
| `DataUrlAssetStore` | `data_url` | Returns the payload inline as `data:<mime>;base64,…`. No disk write. |
| `LocalAssetStore` | `local` | Writes to `<storage>/assets/<token>.<ext>` and returns a URL like `/api/v1/assets/<token>.<ext>` (served by `Spora\Http\AssetController`). |
| `AutoAssetStore` | `auto` (default) | Composes the two above and dispatches per call based on a size threshold. |

The container binds `AssetStore::class` to whichever mode the operator picks via `SPORA_ASSET_STORE_MODE`. Plugins depend on the **interface**, never on a concrete class.

### `Spora\Tools\MediaEmbed`

A final, stateless utility class in `app/Tools/MediaEmbed.php`. Five static helpers — `image()`, `audioFromUrl()`, `videoFromUrl()`, `audioFromBytes()`, `videoFromBytes()`. These return the canonical HTML the chat UI knows how to render (see [Frontend markdown allow-list](https://github.com/spora-ai/spora-frontend/blob/main/src/composables/useMarkdown.ts) for the source of truth).

---

## 2. Configuration

| Env var | Default | Effect |
|---|---|---|
| `SPORA_ASSET_STORE_MODE` | `auto` | `auto` / `data_url` / `local`. Invalid values throw at boot. |
| `SPORA_ASSET_STORE_AUTO_THRESHOLD_BYTES` | `1048576` (1 MiB) | In `auto` mode, payloads ≤ this many bytes use `data_url`; larger payloads use `local`. |
| `SPORA_ASSET_STORE_MAX_BYTES` | `52428800` (50 MiB) | Hard ceiling per asset. `AssetStore::store()` throws `AssetTooLargeException` above this. |

Settings are merged from defaults → `config.php` → env vars, same as every other `SPORA_*` setting. See `ContainerDefinitions::configDefinition()` for the merge order.

---

## 3. Pattern A — you have a URL

The simplest case. The upstream API already gave you a CDN URL; just embed it:

```php
use Spora\Tools\MediaEmbed;

$content = "Generated image:\n\n" . MediaEmbed::image($cdnUrl, "alt text");
```

`MediaEmbed::image()` produces `![alt text](https://cdn.example/…)` — standard markdown that the renderer turns into `<img>`. No `AssetStore` involvement, no bytes in the DB, no auth surface.

The same pattern works for already-hosted audio and video:

```php
$content = "Here is the audio:\n\n" . MediaEmbed::audioFromUrl($cdnUrl);
$content = "Here is the video:\n\n" . MediaEmbed::videoFromUrl($cdnUrl, 1920, 1080);
```

---

## 4. Pattern B — you have bytes (or hex from the API)

The upstream API hands back a blob — either raw bytes (e.g. fetched yourself) or a hex/base64-encoded string in the response body. Two ways to wire it:

### Option 1 — call `MediaEmbed::*FromBytes()` directly

```php
use Spora\Tools\MediaEmbed;

$bytes = hex2bin($response['data']['audio']);          // upstream gave us hex
$content = "Synthesized speech.\n\n"
         . MediaEmbed::audioFromBytes($bytes, $this->assetStore, 'speech.mp3');
```

`MediaEmbed::audioFromBytes()` calls `AssetStore::store()` internally and returns the embedded `<audio>`. The `AssetStore` decides between `data:` URL and `/api/v1/assets/…` URL based on the operator's mode setting.

### Option 2 — use the `StoresBinaryAssets` trait (opt-in)

```php
use Spora\Plugins\Concerns\StoresBinaryAssets;
use Spora\Tools\MediaEmbed;

final class MyAudioTool extends AbstractTool {
    use StoresBinaryAssets;

    protected function doWork(/* … */): ToolResult {
        $hex = $this->fetchUpstreamHex();
        // Returns [url, mode] — give `mode` to the UI in ToolResult::$data.
        [$url, $mode] = $this->embedHex($hex, mime: 'audio/mpeg', filename: 'speech.mp3');

        $content = "Audio ready.\n\n" . MediaEmbed::audioFromUrl($url);
        return new ToolResult(true, $content, ['asset_mode' => $mode]);
    }
}
```

`embedHex()` does the odd-length-hex guard, `hex2bin()` decode, and `AssetStore::store()` in one call. It returns `[url, mode]` so you can surface `mode` in `ToolResult::$data` (the UI may use it to render a "local copy" badge vs. inline).

### When to prefer bytes over URL

Most third-party APIs (MiniMax, ElevenLabs, OpenAI TTS) can return either inline bytes or a hosted URL. Prefer the URL when it's available and the URL will be valid for the duration of the user's task (typically 24 hours for CDN links). Fall back to bytes + `AssetStore` when:

- The API only returns inline bytes (e.g. some TTS endpoints).
- The hosted URL expires faster than your tool needs it (e.g. 1-hour URLs for assets you want to keep around for chat history).
- The payload is multi-megabyte and you want to avoid bloating the chat-history DB row with a base64 data URL.

---

## 5. Plumbing in your plugin

PHP-DI auto-resolves typed constructor parameters, so:

```php
use Spora\Services\AssetStore;

final class MyTool extends AbstractTool {
    public function __construct(
        private readonly AssetStore $assetStore,
        // … other deps …
    ) {}
}
```

is enough — the container will inject the configured `AssetStore` automatically. No plugin-level DI configuration is required.

If your tool uses the `StoresBinaryAssets` trait, the trait's `setAssetStore(AssetStore)` setter is auto-wired by PHP-DI too — you do **not** need to inject `AssetStore` explicitly through your constructor (though you can if you want to).

---

## 6. What the chat UI does with the HTML

- `<img>`, `<audio>`, `<video>`, `<source>` are in the allow-list.
- `data:` URIs are allowed on `src` of `<audio>`, `<video>`, and `<source>` (via a per-call DOMPurify hook) but blocked on `<a href>` so `data:text/html,…` XSS stays closed.
- Media elements get a sensible default style in `spora-frontend/src/style.css` under `.chat-bubble-content video` / `.chat-bubble-content audio`.

If you emit HTML outside the canonical helpers (`MediaEmbed::*`), test that the result survives sanitization. Run the frontend tests in `spora-frontend/tests/composables/useMarkdown.spec.ts` against your markup.

---

## 7. Local-mode URLs are stable, not permanent

`LocalAssetStore` mints a token that embeds a daily-rotating HMAC. A URL generated today is valid until midnight UTC tomorrow; a URL from last week returns 404 even if the file is still on disk.

If you want long-term retention, run `php bin/spora assets:gc --max-age-days=N` periodically (cron) to free disk. The command does **not** invalidate URLs that are still in their validity window — it only unlinks files past `--max-age-days`.

---

## 8. End-to-end example

```php
final class TtsTool extends AbstractTool {
    use StoresBinaryAssets;

    public function __construct(
        HttpClientInterface $http,
        ToolConfigService $config,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($config, $http, $logger);
    }

    protected function doWork(ToolContext $ctx, array $args): ToolResult {
        $resp = $this->http->request('POST', 'https://api.tts.example/synthesize', [
            'json'    => ['text' => $args['text']],
            'timeout' => 60,
        ])->toArray();

        if (! empty($resp['audio_url'])) {
            // Easy path: upstream hosted it.
            $embed = MediaEmbed::audioFromUrl($resp['audio_url']);
            $mode  = null;
        } elseif (! empty($resp['audio_hex'])) {
            // API returned hex; store via AssetStore, then embed.
            [$url, $mode] = $this->embedHex(
                $resp['audio_hex'],
                mime: 'audio/mpeg',
                filename: 'speech.mp3',
            );
            $embed = MediaEmbed::audioFromUrl($url);
        } else {
            return new ToolResult(false, 'TTS API returned no audio.');
        }

        $content = "Synthesized {$resp['usage_characters']} characters.\n\n{$embed}";
        return new ToolResult(true, $content, [
            'audio_url'  => $resp['audio_url'] ?? null,
            'asset_mode' => $mode,
        ]);
    }
}
```

That's the full pattern. Roughly 10 lines per tool beyond the upstream HTTP call.