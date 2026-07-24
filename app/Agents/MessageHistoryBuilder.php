<?php

declare(strict_types=1);

namespace Spora\Agents;

use Spora\Drivers\LLMDriverInterface;
use Spora\Models\MediaAsset;
use Spora\Models\TaskHistory;

/**
 * Replays {@see TaskHistory} rows into the OpenAI-compatible message list sent
 * to the LLM each tick. Rows with `role=attachment` are never sent as such
 * (providers reject the role) — they fold into the adjacent `user` row,
 * with the operator's typed prompt preserved as a leading text block.
 *
 * Pairing is order-symmetric: {@see consumeAttachmentPair()} handles the
 * reverse-order case and {@see consumeUserAttachmentPair()} the production
 * case, both producing a synthetic row whose `sequence` is the later of the
 * pair so summary-compaction `_seq` filtering still drops the right range.
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
        $this->stripScaffoldingKeys($messages);

        return $messages;
    }

    /**
     * Walks the rows in `sequence` order, applying summary compaction and
     * converting each surviving row into an LLM-shaped message. Attachment
     * rows fold into the adjacent `user` row in either order; the merged
     * row's `sequence` is the later of the pair so `_seq`-based eviction
     * (see {@see evictCompactedRows()}) still drops the right range.
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
     * `sequence` is the later of the pair so `_seq`-filter eviction
     * still drops the right range. Companion: {@see consumeAttachmentPair()}.
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
     * Removes non-summary messages whose `_seq` is inside the summarised range.
     * Summary rows keep their own `_seq` and are always preserved.
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
     * either a list of ContentBlock dicts (text + image blocks) or a plain
     * string when no blocks can be produced. Image blocks require the
     * driver to support image input; the controller is expected to have
     * rejected vision-incompatible requests with `400 MEDIA_CAPABILITY_MISMATCH`
     * before we get here.
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
     * Rewrites empty `arguments` arrays to the literal `'{}'` string —
     * strict providers (OpenAI, MiniMax, LM Studio) reject `[]` for the
     * tool-call `arguments` field.
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
     * Scaffolding key — stripped before the wire payload is built.
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

    public function __construct(
        private readonly ?LLMDriverInterface $driver,
        private readonly string $basePath,
    ) {}

    /**
     * Null when no resolvable blocks exist (legacy rows, all-refs-missing,
     * or image-only attachments on a non-vision driver). Callers fall
     * back to {@see fallbackText()} in that case.
     *
     * @return list<array<string, mixed>>|null
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
     * pre-flight.
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
     * Assembles the `content` array. The operator prompt leads, followed
     * by `---` and the filename header + extracted markdown. Image blocks
     * (when present) follow the leading text block. Trivial case — a
     * single text attachment with no prompt — passes the original block
     * through unchanged (no `---` rewrite).
     *
     * @param array{text: list<array<string, mixed>>, image: list<array<string, mixed>>} $blocks
     * @return list<array<string, mixed>>
     */
    private function buildAttachmentContent(array $blocks, string $prompt): array
    {
        if ($blocks['image'] === []) {
            if ($prompt === '' && count($blocks['text']) === 1) {
                return $blocks['text'];
            }
            return [['type' => 'text', 'text' => $this->composeTextContent($prompt, $blocks['text'])]];
        }

        if ($prompt === '') {
            return $blocks['image'];
        }
        return array_merge(
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
