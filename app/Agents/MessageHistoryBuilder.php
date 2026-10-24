<?php

declare(strict_types=1);

namespace Spora\Agents;

use Spora\Agents\ValueObjects\ActiveCompactions;
use Spora\Drivers\LLMDriverInterface;
use Spora\Models\MediaAsset;
use Spora\Models\MediaDerivative;
use Spora\Models\TaskHistory;
use Spora\Services\MediaArchive\MediaDerivativeService;

/**
 * Replays {@see TaskHistory} rows into the OpenAI-compatible message list sent
 * to the LLM each tick. Rows with `role=attachment` are never sent as such
 * (providers reject the role) — they fold into the adjacent `user` row,
 * with the operator's typed prompt preserved as a leading text block.
 *
 * Pairing is order-symmetric: {@see consumeAttachmentPair()} handles the
 * reverse-order case and {@see consumeUserAttachmentPair()} the production
 * case, both producing a synthetic row whose `sequence` is the later of the
 * pair, so a compaction that covers the pair drops both halves or neither.
 *
 * A compaction replaces the rows it covers with a summary. The rows
 * themselves stay in `task_history` — the operator can still read them — so
 * what the provider receives is decided here: the rows a summary covers are
 * dropped, and the summary is sent in their place.
 *
 * Assistant rows with stored `content_blocks` (Anthropic thinking, redacted
 * thinking, images) are rendered through the block list so the provider
 * sees the original signed payload on the next outbound turn.
 *
 * The result passes through {@see ToolCallPairingReconciler}, which repairs
 * tool batches left unpaired by an interrupted tick.
 *
 * The internal `content` shape is `['type'=>'text'|'image', 'text'|'mediaType'|'base64', …]`;
 * the per-provider wire shape is built by the matching `LLMDriverInterface`
 * implementation (OpenAI, Anthropic, …).
 */
final class MessageHistoryBuilder
{
    public function __construct(
        private readonly ?LLMDriverInterface $driver = null,
    ) {}

    /**
     * @return list<array<string, mixed>>  OpenAI-compatible messages, in `sequence` order.
     */
    public function build(int $taskId): array
    {
        $rows = TaskHistory::where('task_id', $taskId)
            ->orderBy('sequence')
            ->get();

        $messages = $this->applySummaryCompaction($rows);

        // Must run after compaction, which orphans tool results itself.
        $messages = (new ToolCallPairingReconciler())->reconcile($messages);

        return $messages;
    }

    /**
     * Walks the rows in `sequence` order and converts each surviving row into
     * an LLM-shaped message.
     *
     * A summary stands in for the rows it covers: those rows are dropped and
     * the summary is sent in their place, at the position the range starts.
     * A summary whose range a later, wider one covers is redundant and is
     * dropped without being sent — `role: summary` is not a wire role any
     * provider accepts.
     *
     * Attachment rows fold into the adjacent `user` row in either order; the
     * merged row's `sequence` is the later of the pair, so the range check
     * reads the pair as one row and drops both halves or neither.
     *
     * @param  \Illuminate\Support\Collection<int, TaskHistory>  $rows
     * @return list<array<string, mixed>>
     */
    private function applySummaryCompaction(\Illuminate\Support\Collection $rows): array
    {
        $compactions = ActiveCompactions::find($rows);

        $messages  = [];
        $pending   = $compactions->summaries();
        $rowsArray = $rows->values()->all();

        $i = 0;
        while ($i < count($rowsArray)) {
            $row = $rowsArray[$i];

            if ($row->role === 'summary') {
                $i++;
                continue;
            }

            $pair      = $this->consumeAdjacentPair($row, $rowsArray, $i);
            $effective = $pair !== null ? $pair['row'] : $row;

            // A summary stands in for the rows its range starts at, so it is
            // sent at that position rather than at its own row's position.
            while ($pending !== [] && $pending[0]['first'] <= $effective->sequence) {
                $messages[] = $this->summaryMessage($pending[0]['row']);
                array_shift($pending);
            }

            if (!$compactions->covers($effective->sequence)) {
                $messages[] = $this->messageFromHistoryRow($effective);
            }

            $i = $pair !== null ? $pair['nextIndex'] : $i + 1;
        }

        // A summary whose range starts after every row still belongs in the
        // conversation — dropping it would lose what it replaced.
        foreach ($pending as $summary) {
            $messages[] = $this->summaryMessage($summary['row']);
        }

        return $messages;
    }

    /**
     * @param list<TaskHistory> $rowsArray
     * @return array{row: TaskHistory, nextIndex: int}|null
     */
    private function consumeAdjacentPair(TaskHistory $row, array $rowsArray, int $i): ?array
    {
        if ($row->role === 'attachment') {
            return $this->consumeAttachmentPair($rowsArray, $i);
        }
        if ($row->role === 'user') {
            return $this->consumeUserAttachmentPair($rowsArray, $i);
        }
        return null;
    }

    /**
     * Reverse-order merge: row at `$i` is `attachment`, next is `user`.
     * The companion production-order helper is {@see consumeUserAttachmentPair()}.
     *
     * @param list<TaskHistory> $rowsArray
     * @return array{row: TaskHistory, nextIndex: int}|null
     */
    private function consumeAttachmentPair(array $rowsArray, int $i): ?array
    {
        $next = $rowsArray[$i + 1] ?? null;
        if ($next === null || $next->role !== 'user') {
            return null;
        }
        $merged = clone $rowsArray[$i];
        $merged->content   = $next->content;
        $merged->sequence  = $next->sequence;
        return ['row' => $merged, 'nextIndex' => $i + 2];
    }

    /**
     * Production-order merge: row at `$i` is `user`, next is `attachment`.
     * The synthetic row reuses {@see attachmentMessage()} expansion; its
     * `sequence` is the later of the pair, so the compaction's range check
     * reads the pair as one row. Companion: {@see consumeAttachmentPair()}.
     *
     * @param list<TaskHistory> $rowsArray
     * @return array{row: TaskHistory, nextIndex: int}|null
     */
    private function consumeUserAttachmentPair(array $rowsArray, int $i): ?array
    {
        $next = $rowsArray[$i + 1] ?? null;
        if ($next === null || $next->role !== 'attachment') {
            return null;
        }
        $merged = clone $next;
        $merged->content  = $rowsArray[$i]->content;
        $merged->sequence = $next->sequence;
        return ['row' => $merged, 'nextIndex' => $i + 2];
    }
    /**
     * `summary` is internal bookkeeping that every provider rejects on the
     * wire, so it is sent as a plain user message.
     *
     * @return array{role: string, content: string|null}
     */
    private function summaryMessage(TaskHistory $row): array
    {
        return [
            'role'    => 'user',
            'content' => $row->content,
        ];
    }

    /**
     * `attachment` has no valid wire role, so it must never fall through to a
     * literal `{role: 'attachment'}` — every provider rejects that with a 400.
     *
     * @return array<string, mixed>
     */
    private function messageFromHistoryRow(TaskHistory $row): array
    {
        if ($row->role === 'attachment') {
            return $this->attachmentMessage($row);
        }

        $content = $row->content;
        if ($row->role === 'assistant' && is_array($row->content_blocks) && $row->content_blocks !== []) {
            $content = $row->content_blocks;
        }

        $message = [
            'role' => $row->role,
            'content' => $content,
        ];

        if ($row->role === 'tool') {
            $message = [
                'role' => 'tool',
                'tool_call_id' => $row->tool_call_id,
                'name' => $row->tool_name,
                'content' => $row->content,
            ];
        } elseif ($row->role === 'assistant' && $row->tool_call_payload !== null) {
            $message = [
                'role'    => 'assistant',
                'content' => $content,
            ];

            // Omitted rather than empty: no provider accepts `tool_calls: []`.
            $calls = $this->decodeToolCallPayload($row->tool_call_payload);
            if ($calls !== []) {
                $message['tool_calls'] = $calls;
            }
        }

        return $message;
    }

    /**
     * Expand an `attachment` row into a `user` message whose `content` is
     * either a list of ContentBlock dicts (metadata, text, and image blocks)
     * or a plain string when no blocks can be produced. Metadata blocks expose
     * the asset identity; image blocks require the driver to support image
     * input. The controller is expected to have rejected vision-incompatible
     * requests with `400 MEDIA_CAPABILITY_MISMATCH` before we get here.
     */
    private function attachmentMessage(TaskHistory $row): array
    {
        $renderer = new AttachmentRowRenderer(
            $this->driver,
            defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3),
        );

        $rendered = $renderer->render($row);
        if ($rendered !== null) {
            return [
                'role'    => 'user',
                'content' => $rendered,
            ];
        }

        return [
            'role'    => 'user',
            'content' => $renderer->fallbackText($row->content),
        ];
    }

    /**
     * Empty `arguments` become the literal `'{}'`: strict providers reject `[]`.
     * Non-array entries are dropped rather than forwarded as `tool_calls`.
     *
     * @return list<array<string, mixed>>
     */
    private function decodeToolCallPayload(string $payload): array
    {
        $decoded = json_decode($payload, true);
        if (! is_array($decoded)) {
            return [];
        }

        $calls = [];
        foreach ($decoded as $tc) {
            if (! is_array($tc)) {
                continue;
            }

            if (isset($tc['function']['arguments'])) {
                $args        = $tc['function']['arguments'];
                $decodedArgs = is_string($args) ? (json_decode($args, true) ?? []) : (array) $args;
                if ($decodedArgs === []) {
                    $tc['function']['arguments'] = '{}';
                }
            }

            $calls[] = $tc;
        }

        return $calls;
    }
}

/**
 * Renders a {@see TaskHistory} `attachment` row into the internal
 * `content` array for the merged `user` message. Internal to
 * {@see MessageHistoryBuilder} — instantiated by {@see MessageHistoryBuilder::attachmentMessage()}.
 */
final class AttachmentRowRenderer
{
    /**
     * Hard cap on inline image bytes. A 4K photo can exceed 20 MiB
     * after MIME decode; without a cap, a single oversized attachment
     * blows up the LLM context window and the request payload.
     */
    private const MAX_INLINE_IMAGE_BYTES = 20 * 1024 * 1024;

    /**
     * Hard cap on inline text bytes, applied to BOTH inline branches:
     * the `md` derivative and the raw source text.
     *
     * 512 KB ≈ 131k tokens — the largest value that still fits a 200k
     * context window with room for the system prompt and history. Text
     * sources therefore get *looser* than the old 256 KB cap, which
     * spares the common `get_source` round-trip.
     *
     * **What this bounds is one attachment; what bounds the row is
     * {@see MAX_INLINE_ROW_TEXT_BYTES}.** The two are the same number
     * today, and the arithmetic above is a statement about a *row*, so it
     * only held while one attachment could consume the whole of it. It did
     * not: this cap was applied per asset inside a loop over every
     * attachment with no count limit and no running total, so four 400 KB
     * sources inlined 1.6 MB and the context-window claim stopped being
     * true at the second attachment.
     *
     * The derivative branch had no cap at all before this change, which
     * meant a 200-page PDF's full markdown was inlined uncapped — the
     * latent context bug this constant now bounds. Past the cap the
     * caller falls through to the pointer branch, which names
     * `get_source` so the LLM knows the content exists and where to
     * read it. See {@see self::buildTextBlock()}.
     */
    private const MAX_INLINE_TEXT_BYTES = 512 * 1024;

    /**
     * The same 512 KB, spent across every attachment in a single row rather
     * than re-spent per attachment.
     *
     * Deliberately equal to {@see MAX_INLINE_TEXT_BYTES} so the pair cannot
     * drift into a second, larger reading: one attachment may fill the whole
     * row budget, and the next one finds nothing left. Kept as its own
     * constant rather than a reuse of the other because they are different
     * claims — "no single asset is this big" versus "no row inlines more
     * than this" — and only the second is the one the context-window
     * arithmetic above describes.
     *
     * Image blocks are not counted: they are separately capped per asset
     * ({@see MAX_INLINE_IMAGE_BYTES}) and cost far fewer tokens per byte.
     */
    private const MAX_INLINE_ROW_TEXT_BYTES = 512 * 1024;

    /**
     * Leading bytes we sample to confirm the asset really is text
     * before inlining it. A NUL byte anywhere in the sample flags the
     * file as binary so a mislabeled mime cannot poison the LLM context.
     */
    private const TEXT_INSPECTION_BYTES = 4096;

    /**
     * Mime prefixes/types we treat as text-safe for the no-converter
     * inline fallback. `text/*` covers everything in the text family;
     * the application/* set lists common text-based formats that do not
     * start with `text/` (JSON, XML, YAML, Typst source, code, etc.).
     * Binaries (PDF, image/*, audio/*, video/*, zip) are intentionally
     * absent — base64-encoding them is not useful to the LLM.
     */
    private const TEXT_MIME_PREFIX = 'text/';

    private const KNOWN_TEXT_APPLICATION_MIMES = [
        'application/json',
        'application/ld+json',
        'application/xml',
        'application/javascript',
        'application/x-javascript',
        'application/x-yaml',
        'application/yaml',
        'application/x-typst',
        'application/typst',
        'application/x-shellscript',
        'application/x-sh',
        'application/x-perl',
        'application/x-python',
        'application/x-ruby',
        'application/x-httpd-php',
        'application/sql',
        'application/graphql',
    ];

    public function __construct(
        private readonly ?LLMDriverInterface $driver,
        private readonly string $basePath,
    ) {}

    /**
     * Null when no resolvable blocks exist (legacy rows, all-refs-missing,
     * or attachments whose assets cannot be resolved). Callers fall back
     * to {@see fallbackText()} in that case.
     *
     * @return list<array<string, mixed>>|null
     */
    public function render(TaskHistory $row): ?array
    {
        $blocks = $this->collectAttachmentBlocks($row);
        $prompt = is_string($row->content) ? trim($row->content) : '';

        if ($blocks['text'] === [] && $blocks['image'] === [] && $blocks['metadata'] === []) {
            return null;
        }

        return $this->buildAttachmentContent($blocks, $prompt);
    }

    /**
     * Preserves the operator's typed prompt when no resolvable blocks
     * exist (legacy rows, all-refs-missing, or image-only attachments
     * on a non-vision driver). Returns `'[attachment]'` when `$rowContent`
     * is empty.
     */
    public function fallbackText(?string $rowContent): string
    {
        $content = is_string($rowContent) ? trim($rowContent) : '';
        return $content === '' ? '[attachment]' : $content;
    }

    /**
     * Image blocks for non-vision drivers are dropped here — defense in
     * depth alongside the controller's `MEDIA_CAPABILITY_MISMATCH`
     * pre-flight. Metadata blocks (asset_id + filename + local URL) are
     * collected separately so they stay siblings of the content block
     * they refer to and never get composed into the prompt body.
     *
     * The `md` derivatives are resolved in one batch before the loop
     * ({@see resolveTextDerivatives()}) rather than per attachment.
     *
     * `$remaining` is the row's shared inline-text budget, spent in
     * attachment order: each text block is charged what it actually inlined
     * and the next attachment inherits the remainder. Without it the
     * per-asset cap in {@see buildTextBlock()} would be re-spent once per
     * attachment and a row's inlined total would be cap × attachment count
     * — the multiplication the docblock on
     * {@see AttachmentRowRenderer::MAX_INLINE_TEXT_BYTES} was read as
     * forbidding.
     *
     * @return array{text: list<array<string, mixed>>, image: list<array<string, mixed>>, metadata: list<array<string, mixed>>}
     */
    private function collectAttachmentBlocks(TaskHistory $row): array
    {
        if (!is_array($row->attachments)) {
            return ['text' => [], 'image' => [], 'metadata' => []];
        }

        $supportsImages = $this->driver !== null && $this->driver->supportsImageInput();
        $textBlocks     = [];
        $imageBlocks    = [];
        $metadataBlocks = [];
        $derivatives    = $this->resolveTextDerivatives($row->attachments);
        $remaining      = self::MAX_INLINE_ROW_TEXT_BYTES;

        foreach ($row->attachments as $ref) {
            $asset = $this->resolveAttachmentAsset($ref);
            if ($asset === null) {
                continue;
            }
            $metadataBlocks[] = $this->attachmentMetadataBlock($asset);
            $kind = (string) ($ref['kind'] ?? 'text');
            if ($kind === 'image') {
                $image = $this->buildImageBlock($asset, $supportsImages);
                if ($image !== null) {
                    $imageBlocks[] = $image;
                }
                continue;
            }
            [$block, $inlined] = $this->buildTextBlock($asset, $derivatives[$asset->id] ?? null, $remaining);
            $textBlocks[] = $block;
            $remaining    = max(0, $remaining - $inlined);
        }

        return ['text' => $textBlocks, 'image' => $imageBlocks, 'metadata' => $metadataBlocks];
    }

    /**
     * @param array<string, mixed> $ref
     */
    private function resolveAttachmentAsset(array $ref): ?MediaAsset
    {
        $mediaId = $ref['media_id'] ?? null;
        if (!is_string($mediaId) || $mediaId === '') {
            return null;
        }
        return MediaAsset::query()->find($mediaId);
    }

    /**
     * Batch-resolve each attachment's `md` derivative, keyed by parent
     * asset id. Two queries for the whole row rather than a
     * `media_derivatives` lookup plus a `MediaAsset::find()` per
     * attachment — the builder runs on every turn, and this is the only
     * part of the branch-1 path that can be done without touching bytes.
     *
     * A parent with several `md` rows (two producers emitting the same
     * format) resolves to the oldest, so every reader of a given parent
     * sees the same text.
     *
     * @param  array<array-key, mixed> $refs
     * @return array<string, MediaAsset>
     */
    private function resolveTextDerivatives(array $refs): array
    {
        $parentIds = [];
        foreach ($refs as $ref) {
            if (!is_array($ref)) {
                continue;
            }
            $mediaId = $ref['media_id'] ?? null;
            if (is_string($mediaId) && $mediaId !== '') {
                $parentIds[$mediaId] = true;
            }
        }
        if ($parentIds === []) {
            return [];
        }

        $pairs = MediaDerivative::query()
            ->whereIn('parent_id', array_keys($parentIds))
            ->where('format', MediaDerivativeService::MARKDOWN_FORMAT)
            ->orderBy('created_at', 'asc')
            ->get(['parent_id', 'derivative_id']);

        if ($pairs->isEmpty()) {
            return [];
        }

        $rows = MediaAsset::query()
            ->whereIn('id', $pairs->pluck('derivative_id')->unique()->all())
            ->get()
            ->keyBy('id');

        $out = [];
        foreach ($pairs as $pair) {
            $parentId = (string) $pair->parent_id;
            $derivative = $rows->get((string) $pair->derivative_id);
            if ($derivative instanceof MediaAsset && !isset($out[$parentId])) {
                $out[$parentId] = $derivative;
            }
        }
        return $out;
    }

    /**
     * Returns null when the image isn't forwarded (non-vision driver or
     * oversized payload) so the caller can drop it without branching.
     *
     * @return array<string, mixed>|null
     */
    private function buildImageBlock(MediaAsset $asset, bool $supportsImages): ?array
    {
        if (!$supportsImages) {
            return null;
        }
        $bytes = $this->loadInlineImageBytes($asset);
        if ($bytes === null) {
            return null;
        }
        return [
            'type'      => 'image',
            'mediaType' => (string) ($asset->mime_type ?? 'application/octet-stream'),
            'base64'    => base64_encode($bytes),
        ];
    }

    /**
     * Produces the user-facing text block for a non-image attachment, plus
     * the inline bytes it consumed against the row's remaining `$budget`.
     *
     * The invariant: a text-ish source within both the per-asset cap and the
     * row's remaining budget is its own text; anything else gets an `md`
     * derivative; and if neither fits, the LLM is told where to read it.
     *
     * Resolution order:
     *   1. The asset has an `md` derivative (PDF, docx, …) and the
     *      recorded `byte_size` is within both {@see MAX_INLINE_TEXT_BYTES}
     *      and `$budget` — inline the derivative's bytes. The size gate
     *      reads the column first, so a 200-page PDF is never read off disk
     *      only to be discarded — the derivative path costs a
     *      `media_derivatives` lookup, a second `MediaAsset::find()` and a
     *      real `file_get_contents()` per attachment per turn. The loaded
     *      bytes are then re-measured, so a stale `byte_size` cannot smuggle
     *      an oversized body into the context window.
     *   2. The asset's mime type looks text-safe AND the raw bytes fit the
     *      same two bounds AND the leading bytes contain no NUL — inline the
     *      raw bytes. This is what keeps `create_media` and `note.txt` from
     *      spawning a pointless `foo.md` derivative of `foo.md`.
     *   3. Out of bounds, budget spent by an earlier attachment, NUL-
     *      containing, or binary with no derivative — the LLM gets the
     *      metadata prefix block (a sibling) for the asset_id plus a body
     *      naming `get_source`. The pointer is a tail fallback, not the
     *      common path: `[no extractable text]` would be actively wrong
     *      here, because an in-bounds-mime asset whose bytes merely exceed
     *      the budget absolutely does have text — the LLM would report
     *      "no text" and stop instead of reading it. It also keeps the LLM
     *      from trying `read_url file:///api/v1/assets/...`.
     *
     * A zero `$budget` therefore reaches branch 3 for an otherwise-inlinable
     * asset, which is the intended outcome: the budget is spent, and the
     * pointer tells the LLM the content exists and how to reach it.
     *
     * @return array{0: array<string, mixed>, 1: int} The block, and the bytes it inlined (0 on the pointer branch).
     */
    private function buildTextBlock(MediaAsset $asset, ?MediaAsset $derivative, int $budget): array
    {
        $displayName = $asset->filename ?? $asset->id;
        $cap         = min(self::MAX_INLINE_TEXT_BYTES, $budget);

        // 1. The `md` derivative of a binary document.
        $extracted = $this->loadInlinableDerivativeText($derivative, $cap);
        if ($extracted !== null) {
            return [
                [
                    'type' => 'text',
                    'text' => "# {$displayName} (extracted text)\n\n" . $extracted,
                ],
                strlen($extracted),
            ];
        }

        // 2. Text-ish source, in bounds: the bytes are their own text.
        if ($this->isLikelyTextMime($asset)) {
            $bytes = $this->loadAssetBytes($asset);
            if ($bytes !== null
                && strlen($bytes) <= $cap
                && $this->bytesLookLikeText($bytes)
            ) {
                return [
                    [
                        'type' => 'text',
                        'text' => "# {$displayName} (raw text — no converter registered)\n\n" . $bytes,
                    ],
                    strlen($bytes),
                ];
            }
        }

        // 3. Nothing inlineable — point at the tool that can read it.
        return [
            [
                'type' => 'text',
                'text' => "# {$displayName} (no inline text)\n\n"
                    . '[too large to inline — call `get_source` with the asset_id above to read the file]',
            ],
            0,
        ];
    }

    /**
     * The derivative's bytes when it exists AND is within both the per-asset
     * cap and the row's remaining budget, otherwise null. The `byte_size`
     * column is the gate: it is populated at creation time, so an oversized
     * derivative is rejected without touching the disk at all.
     */
    private function loadInlinableDerivativeText(?MediaAsset $derivative, int $cap): ?string
    {
        if ($derivative === null || ! $this->fitsInlineBudget((int) ($derivative->byte_size ?? 0), $cap)) {
            return null;
        }

        $bytes = $this->loadAssetBytes($derivative);
        // The column is only trustworthy for a derivative whose `createNew()`
        // set it. Re-check the real length for anything that predates it, so
        // a stale `byte_size` cannot smuggle an oversized body into the
        // context window.
        return ($bytes === null || $bytes === '' || ! $this->fitsInlineBudget(strlen($bytes), $cap))
            ? null
            : $bytes;
    }

    private function fitsInlineBudget(int $length, int $cap = self::MAX_INLINE_TEXT_BYTES): bool
    {
        return $length <= $cap;
    }

    /**
     * Whether the asset's mime type is safe to inline as raw text
     * without a registered converter. Matches the whole `text/*` family
     * plus a curated list of common text-based application types
     * (JSON, XML, YAML, code, Typst source, etc.). Binary formats
     * (PDF, image/*, audio/*, video/*, zip) are intentionally absent.
     */
    private function isLikelyTextMime(MediaAsset $asset): bool
    {
        $mime = strtolower((string) ($asset->mime_type ?? ''));
        if ($mime === '') {
            return false;
        }
        if (str_starts_with($mime, self::TEXT_MIME_PREFIX)) {
            return true;
        }
        return in_array($mime, self::KNOWN_TEXT_APPLICATION_MIMES, true);
    }

    /**
     * Cheap text-shape check on the first {@see TEXT_INSPECTION_BYTES}
     * of the asset. Rejects binary files mislabeled with a text mime
     * (any NUL byte in the sample flags the file as binary) and accepts
     * everything else — empty content counts as text. The 4 KB window
     * catches common binary magic (PNG, PDF, ZIP) while staying cheap.
     */
    private function bytesLookLikeText(string $bytes): bool
    {
        $sample = substr($bytes, 0, self::TEXT_INSPECTION_BYTES);
        if ($sample === '') {
            return true;
        }
        return strpos($sample, "\0") === false;
    }

    /**
     * Assembles the `content` array. Metadata blocks (one per attachment)
     * lead. The composed prompt + extracted text occupies a single
     * leading text block when a prompt is present (or when the
     * attachment layout is wider than a single text body); image
     * blocks follow.
     *
     * @param array{text: list<array<string, mixed>>, image: list<array<string, mixed>>, metadata: list<array<string, mixed>>} $blocks
     * @return list<array<string, mixed>>
     */
    private function buildAttachmentContent(array $blocks, string $prompt): array
    {
        // No prompt, no body text — metadata blocks possibly followed by images.
        // Covers both "metadata-only" (oversized image on non-vision driver)
        // and "image-only with no prompt" in one branch.
        if ($prompt === '' && $blocks['text'] === []) {
            return array_merge($blocks['metadata'], $blocks['image']);
        }

        // Trivial: single text body + single metadata + no prompt — pass
        // them through unchanged (no `---` rewrite).
        if ($prompt === '' && count($blocks['text']) === 1 && count($blocks['metadata']) === 1) {
            return [$blocks['metadata'][0], $blocks['text'][0]];
        }

        // General case: metadata + composed text + images.
        return array_merge(
            $blocks['metadata'],
            [['type' => 'text', 'text' => $this->composeTextContent($prompt, $blocks['text'])]],
            $blocks['image'],
        );
    }

    /**
     * @param list<array<string, mixed>> $textBlocks
     */
    private function composeTextContent(string $prompt, array $textBlocks): string
    {
        $attachmentSections = array_map(
            static fn(array $block): string => (string) ($block['text'] ?? ''),
            $textBlocks,
        );
        $attachmentsText = implode("\n\n", $attachmentSections);

        if ($prompt === '') {
            return $attachmentsText;
        }
        if ($attachmentsText === '') {
            return $prompt;
        }
        return $prompt . "\n\n---\n\n" . $attachmentsText;
    }

    /**
     * Identity prefix emitted alongside every attachment block (image and
     * text). Carries the asset_id + filename + mime + size + local URL
     * so the LLM can pass the asset_id or local URL to any tool that
     * accepts Media Archive references (e.g. `minimax:video` with the
     * plugin's resolver hook, or `media:get_media`). Kept compact —
     * ~60 tokens per attachment.
     *
     * @return array<string, mixed>
     */
    private function attachmentMetadataBlock(MediaAsset $asset): array
    {
        $sizeLabel = $asset->byte_size !== null
            ? ' (' . self::humanByteSize((int) $asset->byte_size) . ')'
            : '';

        return [
            'type' => 'text',
            'text' => sprintf(
                '[Attached asset_id=%s (filename: %s, type: %s%s) — local URL: %s]',
                $asset->id,
                $asset->filename ?? '(unnamed)',
                $asset->mime_type ?? 'application/octet-stream',
                $sizeLabel,
                $asset->publicUrl(),
            ),
        ];
    }

    private static function humanByteSize(int $bytes): string
    {
        return match (true) {
            $bytes >= 1_048_576 => number_format($bytes / 1_048_576, 1) . ' MB',
            $bytes >= 1_024     => number_format($bytes / 1_024, 1) . ' KB',
            default             => $bytes . ' B',
        };
    }

    /**
     * Returns the asset bytes when they fit within the inline-image cap,
     * otherwise null. The cap (20 MiB) prevents a single oversized
     * attachment from OOM-ing the LLM request — see
     * {@see self::MAX_INLINE_IMAGE_BYTES}.
     */
    private function loadInlineImageBytes(MediaAsset $asset): ?string
    {
        $bytes = $this->loadAssetBytes($asset);
        if ($bytes === null || strlen($bytes) > self::MAX_INLINE_IMAGE_BYTES) {
            return null;
        }

        return $bytes;
    }

    private function loadAssetBytes(MediaAsset $asset): ?string
    {
        if ($asset->storage_mode === 'data_url') {
            return is_string($asset->payload) ? $asset->payload : null;
        }
        if ($asset->storage_mode === 'local' && $asset->asset_token !== null && $asset->asset_token !== '') {
            $paths = new \Spora\Core\Paths($this->basePath);
            $path = $paths->storage('assets') . '/' . $asset->asset_token;
            $ext  = \Spora\Services\MediaArchive\MediaArchiveService::extensionForMime($asset->mime_type);
            if ($ext !== null) {
                $path .= '.' . $ext;
            }
            return is_file($path) ? (string) file_get_contents($path) : null;
        }
        return null;
    }
}
