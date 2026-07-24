<?php

declare(strict_types=1);

namespace Spora\Agents;

use Spora\Drivers\LLMDriverInterface;
use Spora\Models\MediaAsset;
use Spora\Models\TaskHistory;

/**
 * Replays {@see TaskHistory} rows into the OpenAI-compatible message list sent
 * to the LLM each tick. Three responsibilities:
 *   1. {@see applySummaryCompaction()} — drops rows whose `sequence` falls
 *      inside a `summary` row's `summarized_sequence_range`, keeping the
 *      summary row itself.
 *   2. {@see messageFromHistoryRow()} — maps a single row into the LLM wire
 *      shape (`tool`, `assistant+tool_calls`, plain role+content, and
 *      `attachment` rows that are folded into the adjacent `user` row).
 *      Rows with `role=attachment` are NEVER sent to the provider as such:
 *      OpenAI/Anthropic both reject the role, so the builder routes every
 *      attachment row through {@see attachmentMessage()} which produces a
 *      valid `user` message with text + image content blocks (filtered by
 *      the driver's image-input capability).
 *   3. {@see stripScaffoldingKeys()} — removes the internal `_seq` bookkeeping
 *      key so the scaffolding never leaks to the provider.
 *
 * Attachment pairing is order-symmetric: the builder merges an attachment row
 * with its adjacent user row in either order, so production (`user` first,
 * then `attachment`) and the reverse ordering used by some fixtures both
 * collapse to a single `user` message. The merge helpers are
 * {@see consumeAttachmentPair()} (reverse-order) and
 * {@see consumeUserAttachmentPair()} (production order). Both produce a
 * synthetic row whose `sequence` is the later of the pair so summary
 * compaction semantics are preserved.
 *
 * Attachment-row rendering (text + image content blocks, fallback
 * string, asset byte loading) lives in {@see AttachmentRowRenderer},
 * which {@see attachmentMessage()} delegates to.
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
        $this->stripScaffoldingKeys($messages);

        return $messages;
    }

    /**
     * Walks the rows in `sequence` order, applying summary compaction and
     * converting each surviving row into an LLM-shaped message. Attachment
     * rows are folded into the adjacent `user` row in either order — the
     * production path writes `user` first then `attachment` while some
     * fixtures use the reverse — so the wire payload never carries the
     * unsupported `attachment` role and the operator's typed prompt always
     * travels together with the attachment context.
     *
     * `_seq` is set on every emitted message so {@see stripScaffoldingKeys()}
     * can target the key without altering the user-visible structure.
     *
     * @param  \Illuminate\Support\Collection<int, TaskHistory>  $rows
     * @return list<array<string, mixed>>
     */
    private function applySummaryCompaction(\Illuminate\Support\Collection $rows): array
    {
        $messages          = [];
        $lastSummarySeqEnd = -1;
        $rowsArray         = $rows->values()->all();

        $i = 0;
        while ($i < count($rowsArray)) {
            $row = $rowsArray[$i];

            if ($this->isSummaryRow($row)) {
                $lastSummarySeqEnd = $this->applySummaryRow($row, $messages, $lastSummarySeqEnd);
                $i++;
                continue;
            }

            if ($row->sequence <= $lastSummarySeqEnd) {
                $i++;
                continue;
            }

            $i = $this->dispatchRow($row, $rowsArray, $i, $messages, $lastSummarySeqEnd);
        }

        return $messages;
    }

    /**
     * Apply a `summary` row: evict any prior messages inside its range,
     * append the summary itself, return the new high-water mark.
     *
     * @param list<array<string, mixed>> $messages
     */
    private function applySummaryRow(
        TaskHistory $row,
        array &$messages,
        int $lastSummarySeqEnd,
    ): int {
        $rangeEnd          = $this->parseSummaryRange($row->summarized_sequence_range);
        $lastSummarySeqEnd = $this->evictCompactedRows($messages, $rangeEnd, $lastSummarySeqEnd);
        $messages[]        = $this->summaryMessage($row);
        return $lastSummarySeqEnd;
    }

    /**
     * Dispatch a single surviving row to its wire shape, attempting
     * user/attachment pair consumption in either order first.
     *
     * @param list<TaskHistory>          $rowsArray
     * @param list<array<string, mixed>> $messages
     * @return int  The new index to resume iteration at.
     */
    private function dispatchRow(
        TaskHistory $row,
        array $rowsArray,
        int $i,
        array &$messages,
        int $lastSummarySeqEnd,
    ): int {
        $pair = $this->consumeAdjacentPair($row, $rowsArray, $i);
        if ($pair !== null) {
            if ($pair['row']->sequence > $lastSummarySeqEnd) {
                $message         = $this->messageFromHistoryRow($pair['row']);
                $message['_seq'] = $pair['row']->sequence;
                $messages[]      = $message;
            }
            return $pair['nextIndex'];
        }

        $message         = $this->messageFromHistoryRow($row);
        $message['_seq'] = $row->sequence;
        $messages[]      = $message;
        return $i + 1;
    }

    /**
     * Try the appropriate pair-consumption helper for `$row`'s role.
     * Returns the synthetic-row payload or null when the adjacent row
     * does not pair (in which case the caller emits `$row` standalone).
     *
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
     * If the row at `$i` is an `attachment` and the next row is a `user`,
     * return a synthetic row whose `content` carries the user's prompt
     * (so {@see attachmentMessage()} merges them) and the index to
     * resume iteration at. Returns null when the adjacent row is not a
     * `user` (or is absent), letting the caller fall through to the
     * standalone attachment path. The companion production-order helper
     * is {@see consumeUserAttachmentPair()}.
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
     * Production-order merge: if the row at `$i` is a `user` and the next
     * row is an `attachment`, return a synthetic row whose `role` is
     * `attachment` so the existing {@see attachmentMessage()} expansion
     * path is reused. The synthetic row carries the user prompt in
     * `content` and the attachment refs in `attachments`; its `sequence`
     * is the later of the pair so summary-compaction `_seq` filtering
     * still drops the right range. The companion reverse-order helper is
     * {@see consumeAttachmentPair()}. Both are needed because
     * {@see \Spora\Agents\Orchestrator::start/continue} persist the
     * production order, while existing fixtures and some tests use the
     * reverse.
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
    private function isSummaryRow(TaskHistory $row): bool
    {
        return $row->role === 'summary' && $row->summarized_sequence_range !== null;
    }

    private function parseSummaryRange(string $range): int
    {
        if (preg_match('/^(\d+)-(\d+)$/', $range, $m) !== 1) {
            return -1;
        }

        return (int) $m[2];
    }

    /**
     * Removes non-summary messages whose `_seq` is inside the summarised range,
     * keeping every summary row untouched (each has its own `_seq`).
     *
     * @param  list<array<string, mixed>>  $messages
     * @return int  The new $lastSummarySeqEnd value (the largest range end seen).
     */
    private function evictCompactedRows(array &$messages, int $rangeEnd, int $lastSummarySeqEnd): int
    {
        if ($rangeEnd < 0) {
            return $lastSummarySeqEnd;
        }

        $messages = array_values(array_filter(
            $messages,
            static fn(array $msg): bool => ($msg['_seq'] ?? -1) > $rangeEnd || ($msg['role'] ?? '') === 'summary',
        ));

        return max($lastSummarySeqEnd, $rangeEnd);
    }

    /**
     * @return array{role: string, content: string|null, _seq?: int}
     */
    private function summaryMessage(TaskHistory $row): array
    {
        return [
            'role'    => 'summary',
            'content' => $row->content,
            '_seq'    => $row->sequence,
        ];
    }

    /**
     * Maps a non-summary row to its LLM wire shape.
     *
     * The `attachment` branch is the load-bearing guard: regardless of
     * whether `$row->attachments` is non-empty, we always route through
     * {@see attachmentMessage()} which returns a `user` message. The
     * legacy fallthrough `{role: 'attachment', content: ...}` was a 400
     * `invalid role: attachment` waiting to happen on every provider.
     *
     * @return array<string, mixed>
     */
    private function messageFromHistoryRow(TaskHistory $row): array
    {
        if ($row->role === 'attachment') {
            return $this->attachmentMessage($row);
        }

        $message = [
            'role'    => $row->role,
            'content' => $row->content,
        ];

        if ($row->role === 'tool') {
            $message = [
                'role'         => 'tool',
                'tool_call_id' => $row->tool_call_id,
                'name'         => $row->tool_name,
                'content'      => $row->content,
            ];
        } elseif ($row->role === 'assistant' && $row->tool_call_payload !== null) {
            $message = [
                'role'       => 'assistant',
                'content'    => null,
                'tool_calls' => $this->decodeToolCallPayload($row->tool_call_payload),
            ];
        }

        return $message;
    }

    /**
     * Expand an `attachment` row into a `user` message whose `content` is
     * either:
     *   - a list of ContentBlock dicts (text + image blocks), or
     *   - a plain string when no blocks can be produced (e.g. all refs
     *     reference missing assets, or the row is a legacy `attachment`
     *     row with null/empty `attachments` JSON).
     *
     * The merged row that {@see consumeAttachmentPair()} produces carries
     * the operator's typed prompt on `$row->content`. When text-kind
     * attachments are present, we fold the prompt above the filename
     * header and extracted markdown so the LLM sees the request as a
     * single `user` turn rather than two. Image-kind attachments become
     * base64 image blocks (only when `supportsImageInput()` is true; the
     * controller should have already rejected the request with
     * `400 MEDIA_CAPABILITY_MISMATCH`).
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
     * Decodes a stored `tool_call_payload` JSON string and rewrites any
     * empty `arguments` array to the literal `'{}'` string that strict
     * providers (OpenAI, MiniMax, LM Studio) require.
     *
     * @return list<array<string, mixed>>
     */
    private function decodeToolCallPayload(string $payload): array
    {
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return [];
        }

        foreach ($decoded as $i => $tc) {
            if (!isset($tc['function']['arguments'])) {
                continue;
            }

            $args         = $tc['function']['arguments'];
            $decodedArgs  = is_string($args) ? (json_decode($args, true) ?? []) : (array) $args;
            if ($decodedArgs === []) {
                $decoded[$i]['function']['arguments'] = '{}';
            }
        }

        return array_values($decoded);
    }

    /**
     * Removes the internal `_seq` key from every emitted message. Mutates
     * in place and returns nothing — the key is pure scaffolding.
     *
     * @param  list<array<string, mixed>>  $messages
     */
    private function stripScaffoldingKeys(array &$messages): void
    {
        foreach ($messages as &$msg) {
            unset($msg['_seq']);
        }
        unset($msg);
    }
}

/**
 * Renders a {@see TaskHistory} `attachment` row into the OpenAI-compatible
 * `content` array for the merged `user` message. Lives outside
 * {@see MessageHistoryBuilder} so the public builder stays under the
 * SonarQube class-method cap while keeping the attachment rendering
 * pipeline as a private implementation detail of {@see attachmentMessage()}.
 */
final class AttachmentRowRenderer
{
    /**
     * Hard cap on inline image bytes. A 4K photo can exceed 20 MiB
     * after MIME decode; without a cap, a single oversized attachment
     * blows up the LLM context window and the request payload.
     */
    private const MAX_INLINE_IMAGE_BYTES = 20 * 1024 * 1024;

    public function __construct(
        private readonly ?LLMDriverInterface $driver,
        private readonly string $basePath,
    ) {}

    /**
     * @return list<array<string, mixed>>|null  Null when no resolvable
     *         blocks exist (legacy rows, all-refs-missing, or image-only
     *         attachments on a non-vision driver). Callers should fall
     *         back to {@see fallbackText()} in that case.
     */
    public function render(TaskHistory $row): ?array
    {
        $blocks = $this->collectAttachmentBlocks($row);
        $prompt = is_string($row->content) ? trim($row->content) : '';

        if ($blocks['text'] === [] && $blocks['image'] === []) {
            return null;
        }

        return $this->buildAttachmentContent($blocks, $prompt);
    }

    /**
     * Build the fallback string used when an attachment row has no
     * resolvable blocks (legacy rows, all-refs-missing, or image-only
     * attachments on a non-vision driver). The text content itself is
     * preserved so the operator's typed prompt still reaches the LLM.
     */
    public function fallbackText(?string $rowContent): string
    {
        $content = is_string($rowContent) ? trim($rowContent) : '';
        return $content === '' ? '[attachment]' : $content;
    }

    /**
     * Walk `$row->attachments` and split refs into text blocks and image
     * blocks. Image blocks are null when the LLM does not support image
     * input; those refs are dropped here (defense in depth alongside the
     * controller's `MEDIA_CAPABILITY_MISMATCH` pre-flight).
     *
     * @return array{text: list<array<string, mixed>>, image: list<array<string, mixed>>}
     */
    private function collectAttachmentBlocks(TaskHistory $row): array
    {
        $supportsImages = $this->driver !== null && $this->driver->supportsImageInput();
        $textBlocks = [];
        $imageBlocks = [];
        if (!is_array($row->attachments)) {
            return ['text' => $textBlocks, 'image' => $imageBlocks];
        }
        foreach ($row->attachments as $ref) {
            $mediaId = $ref['media_id'] ?? null;
            if (!is_string($mediaId) || $mediaId === '') {
                continue;
            }
            $asset = MediaAsset::query()->find($mediaId);
            if ($asset === null) {
                continue;
            }
            $kind = (string) ($ref['kind'] ?? 'text');
            if ($kind === 'image') {
                $block = $this->imageAttachmentBlock($asset, $supportsImages);
                if ($block !== null) {
                    $imageBlocks[] = $block;
                }
                continue;
            }
            $textBlocks[] = $this->textAttachmentBlock($asset);
        }
        return ['text' => $textBlocks, 'image' => $imageBlocks];
    }

    /**
     * Assemble the wire `content` for a user message that has at least one
     * resolvable attachment. The prompt leads, followed by `---` and the
     * filename header + extracted markdown. Image blocks (when present)
     * follow a single leading text block.
     *
     * Trivial case — a single text attachment with no operator prompt —
     * returns the original block unchanged: no combined-block rewrite, no
     * `---` separator. (`{@see composeTextContent()}()` still runs first to
     * compute `$combined`, which the early return then discards.)
     *
     * @param array{text: list<array<string, mixed>>, image: list<array<string, mixed>>} $blocks
     * @return list<array<string, mixed>>
     */
    private function buildAttachmentContent(array $blocks, string $prompt): array
    {
        $combined = $this->composeTextContent($prompt, $blocks['text']);
        $textOnly = $blocks['image'] === [];

        if ($textOnly) {
            return $this->buildTextOnlyContent($blocks, $prompt, $combined);
        }
        return $this->buildImageContent($blocks, $prompt, $combined);
    }

    /**
     * @param array{text: list<array<string, mixed>>, image: list<array<string, mixed>>} $blocks
     */
    private function buildTextOnlyContent(array $blocks, string $prompt, string $combined): array
    {
        if ($prompt === '' && count($blocks['text']) === 1) {
            return $blocks['text'];
        }
        return [['type' => 'text', 'text' => $combined]];
    }

    /**
     * @param array{text: list<array<string, mixed>>, image: list<array<string, mixed>>} $blocks
     */
    private function buildImageContent(array $blocks, string $prompt, string $combined): array
    {
        if ($prompt === '') {
            return $blocks['image'];
        }
        return array_merge(
            [['type' => 'text', 'text' => $combined]],
            $blocks['image'],
        );
    }

    /**
     * Compose the text-block body for an attachment row: operator prompt
     * (when present), followed by `---` separator, then a `# filename
     * (extracted text)` header and the extracted markdown for each text
     * attachment, in order.
     *
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
     * @return array<string, mixed>|null
     */
    private function imageAttachmentBlock(MediaAsset $asset, bool $supportsImages): ?array
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

    /**
     * @return array<string, mixed>
     */
    private function textAttachmentBlock(MediaAsset $asset): array
    {
        $extracted = $asset->markdown_content !== null && $asset->markdown_content !== ''
            ? $asset->markdown_content
            : null;
        $displayName = $asset->filename ?? $asset->id;
        $body = $extracted ?? '[no extractable text]';
        return [
            'type' => 'text',
            'text' => "# {$displayName} (extracted text)\n\n" . $body,
        ];
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
