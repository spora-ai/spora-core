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
 *      `attachment` rows that are folded into the next `user` row).
 *      Rows with `role=attachment` are NEVER sent to the provider as such:
 *      OpenAI/Anthropic both reject the role, so the builder routes every
 *      attachment row through {@see attachmentMessage()} which produces a
 *      valid `user` message with text + image content blocks (filtered by
 *      the driver's image-input capability).
 *   3. {@see stripScaffoldingKeys()} — removes the internal `_seq` bookkeeping
 *      key so the scaffolding never leaks to the provider.
 */
final class MessageHistoryBuilder
{
    /**
     * Hard cap on inline image bytes. A 4K photo can exceed 20 MiB
     * after MIME decode; without a cap, a single oversized attachment
     * blows up the LLM context window and the request payload.
     */
    private const MAX_INLINE_IMAGE_BYTES = 20 * 1024 * 1024;

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
     * rows are folded into the next `user` row so the wire payload never
     * carries the unsupported `attachment` role and the operator's typed
     * prompt travels together with the attachment context.
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

        $rowsArray = $rows->values()->all();
        $i = 0;
        while ($i < count($rowsArray)) {
            /** @var TaskHistory $row */
            $row = $rowsArray[$i];

            if ($this->isSummaryRow($row)) {
                $rangeEnd          = $this->parseSummaryRange($row->summarized_sequence_range);
                $lastSummarySeqEnd = $this->evictCompactedRows($messages, $rangeEnd, $lastSummarySeqEnd);
                $messages[]        = $this->summaryMessage($row);
                $i++;
                continue;
            }

            if ($row->sequence <= $lastSummarySeqEnd) {
                $i++;
                continue;
            }

            if ($row->role === 'attachment') {
                $pair = $this->consumeAttachmentPair($rowsArray, $i);
                if ($pair !== null) {
                    $i = $pair['nextIndex'];
                    if ($pair['row']->sequence > $lastSummarySeqEnd) {
                        $message           = $this->messageFromHistoryRow($pair['row']);
                        $message['_seq']   = $pair['row']->sequence;
                        $messages[]        = $message;
                    }
                    continue;
                }
            }

            $message         = $this->messageFromHistoryRow($row);
            $message['_seq'] = $row->sequence;
            $messages[]      = $message;
            $i++;
        }

        return $messages;
    }

    /**
     * If the row at `$i` is an attachment and is immediately followed by a
     * `user` row, return a synthetic row that carries the user's typed
     * prompt on the attachment row's data (so {@see attachmentMessage()}
     * can merge them) and the index to resume iteration at. Returns null
     * when no merge should happen — either because the next row is not a
     * `user` row, or because there is no next row.
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
        $supportsImages = $this->driver !== null && $this->driver->supportsImageInput();
        $textBlocks = [];
        $imageBlocks = [];
        if (is_array($row->attachments)) {
            foreach ($row->attachments as $ref) {
                $kind = (string) ($ref['kind'] ?? 'text');
                if ($kind === 'image') {
                    $block = $this->imageAttachmentBlockFromRef($ref, $supportsImages);
                    if ($block !== null) {
                        $imageBlocks[] = $block;
                    }
                    continue;
                }
                $block = $this->textAttachmentBlockFromRef($ref);
                if ($block !== null) {
                    $textBlocks[] = $block;
                }
            }
        }

        $prompt = is_string($row->content) ? trim($row->content) : '';
        $hasAttachments = $textBlocks !== [] || $imageBlocks !== [];

        if (!$hasAttachments) {
            // Defensive: legacy or malformed rows that have no resolvable
            // attachment still become a `user` message — never `attachment`.
            return [
                'role'    => 'user',
                'content' => $this->attachmentFallbackText($prompt),
            ];
        }

        // Fold text blocks + the operator prompt into a single text block.
        // The prompt leads; attachments follow, separated by a horizontal rule.
        $combined = $this->composeTextContent($prompt, $textBlocks);

        if ($imageBlocks === []) {
            // Text-only path: when the operator typed a prompt, lead with a
            // text block carrying the merged content; otherwise emit the
            // attachment text blocks verbatim. Content is always an array
            // when attachments are present so the wire shape is uniform.
            if ($prompt === '' && count($textBlocks) === 1) {
                return ['role' => 'user', 'content' => $textBlocks];
            }
            return ['role' => 'user', 'content' => array_merge(
                [['type' => 'text', 'text' => $combined]],
                array_slice($textBlocks, 0),
            )];
        }

        // Image-only path (no prompt, no text attachments): emit the image
        // blocks directly so the leading text block isn't an empty stub.
        if ($combined === '') {
            return ['role' => 'user', 'content' => $imageBlocks];
        }

        // Mixed text + images: leading text block carries the merged
        // prompt + extracted text; image blocks follow.
        $content = array_merge(
            [['type' => 'text', 'text' => $combined]],
            $imageBlocks,
        );
        return ['role' => 'user', 'content' => $content];
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
     * @param array<string, mixed> $ref
     * @return array<string, mixed>|null
     */
    private function textAttachmentBlockFromRef(array $ref): ?array
    {
        if (!isset($ref['media_id']) || !is_string($ref['media_id'])) {
            return null;
        }
        $asset = MediaAsset::query()->find($ref['media_id']);
        return $asset === null ? null : $this->textAttachmentBlock($asset);
    }

    /**
     * @param array<string, mixed> $ref
     * @return array<string, mixed>|null
     */
    private function imageAttachmentBlockFromRef(array $ref, bool $supportsImages): ?array
    {
        if (!$supportsImages) {
            return null;
        }
        if (!isset($ref['media_id']) || !is_string($ref['media_id'])) {
            return null;
        }
        $asset = MediaAsset::query()->find($ref['media_id']);
        return $asset === null ? null : $this->imageAttachmentBlock($asset, true);
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

    /**
     * Build the fallback string used when an attachment row has no
     * resolvable blocks (legacy rows, all-refs-missing, or image-only
     * attachments on a non-vision driver). The text content itself is
     * preserved so the operator's typed prompt still reaches the LLM.
     */
    private function attachmentFallbackText(?string $rowContent): string
    {
        $content = is_string($rowContent) ? trim($rowContent) : '';
        return $content === '' ? '[attachment]' : $content;
    }

    private function loadAssetBytes(MediaAsset $asset): ?string
    {
        if ($asset->storage_mode === 'data_url') {
            return is_string($asset->payload) ? $asset->payload : null;
        }
        if ($asset->storage_mode === 'local' && $asset->asset_token !== null && $asset->asset_token !== '') {
            $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
            $paths = new \Spora\Core\Paths($basePath);
            $path = $paths->storage('assets') . '/' . $asset->asset_token;
            $ext  = \Spora\Services\MediaArchive\MediaArchiveService::extensionForMime($asset->mime_type);
            if ($ext !== null) {
                $path .= '.' . $ext;
            }
            return is_file($path) ? (string) file_get_contents($path) : null;
        }
        return null;
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
