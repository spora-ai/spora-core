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
 *      shape (`tool`, `assistant+tool_calls`, plain role+content), normalising
 *      empty tool-call arguments to `'{}'` along the way. Rows with
 *      `role=attachment` are expanded into a `user` message whose `content`
 *      is a list of `ContentBlock`s (text + image), filtered against the
 *      LLM's `supportsImageInput()` capability.
 *   3. {@see stripScaffoldingKeys()} — removes the internal `_seq` bookkeeping
 *      key so the scaffolding never leaks to the provider.
 */
final class MessageHistoryBuilder
{
    /**
     * Hard cap on the size of image bytes that {@see loadAssetBytes()}
     * returns for an inline image block. A 4K photo can easily exceed
     * 20 MiB after MIME decode; without a cap, a single oversized
     * attachment blows up the LLM context window and the request
     * payload. Set to 20 MiB — comfortably above a typical screenshot,
     * well below context-window failure.
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
     * converting each surviving row into an LLM-shaped message.
     *
     * `_seq` is set on every emitted message so {@see stripScaffoldingKeys()}
     * can target the key without altering the user-visible structure.
     *
     * @param  \Illuminate\Support\Collection<int, TaskHistory>  $rows
     * @return list<array<string, mixed>>
     */
    private function applySummaryCompaction(\Illuminate\Support\Collection $rows): array
    {
        $messages         = [];
        $lastSummarySeqEnd = -1;

        foreach ($rows as $row) {
            if ($this->isSummaryRow($row)) {
                $rangeEnd          = $this->parseSummaryRange($row->summarized_sequence_range);
                $lastSummarySeqEnd = $this->evictCompactedRows($messages, $rangeEnd, $lastSummarySeqEnd);
                $messages[]        = $this->summaryMessage($row);

                continue;
            }

            if ($row->sequence <= $lastSummarySeqEnd) {
                continue;
            }

            $message           = $this->messageFromHistoryRow($row);
            $message['_seq']    = $row->sequence;
            $messages[]         = $message;
        }

        return $messages;
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
     * Maps a non-summary row to its LLM wire shape. Every role projects to
     * a message — there is no "skip this role" case in the current schema.
     *
     * @return array<string, mixed>
     */
    private function messageFromHistoryRow(TaskHistory $row): array
    {
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
        } elseif ($row->role === 'attachment' && is_array($row->attachments) && $row->attachments !== []) {
            $message = $this->attachmentMessage($row);
        }

        return $message;
    }

    /**
     * Expand an `attachment` row into a `user` message whose `content` is
     * a list of ContentBlock dicts. Text-kind attachments become `text`
     * blocks (the asset's `markdown_content` or a fallback note when no
     * extraction succeeded). Image-kind attachments become `image` blocks
     * (the asset's bytes, base64-embedded) — but only when the agent's
     * LLM reports `supportsImageInput() === true`; otherwise the image
     * block is dropped (defense in depth — the controller should have
     * already rejected the request).
     */
    private function attachmentMessage(TaskHistory $row): array
    {
        $supportsImages = $this->driver !== null && $this->driver->supportsImageInput();
        $blocks = [];
        foreach ($row->attachments as $ref) {
            $block = $this->attachmentBlock($ref, $supportsImages);
            if ($block !== null) {
                $blocks[] = $block;
            }
        }

        return [
            'role'    => 'user',
            'content' => $blocks !== [] ? $blocks : ($row->content ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $ref
     * @return array<string, mixed>|null
     */
    private function attachmentBlock(array $ref, bool $supportsImages): ?array
    {
        if (!isset($ref['media_id']) || !is_string($ref['media_id'])) {
            return null;
        }
        $asset = MediaAsset::query()->find($ref['media_id']);
        if ($asset === null) {
            return null;
        }
        $kind = (string) ($ref['kind'] ?? 'text');
        return $kind === 'image'
            ? $this->imageAttachmentBlock($asset, $supportsImages)
            : $this->textAttachmentBlock($asset);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function imageAttachmentBlock(MediaAsset $asset, bool $supportsImages): ?array
    {
        if (!$supportsImages) {
            return null;
        }
        $bytes = $this->loadAssetBytes($asset);
        if ($bytes === null) {
            return null;
        }
        // Cap inline image size — base64-embedding a 50 MiB photo into
        // every LLM message would OOM the request and saturate the
        // provider's context window. The block is silently dropped
        // when oversized; the upstream capability check should have
        // already surfaced an error to the caller.
        if (strlen($bytes) > self::MAX_INLINE_IMAGE_BYTES) {
            return null;
        }
        return [
            'type'      => 'image',
            'mediaType' => (string) ($asset->mime_type ?? 'application/octet-stream'),
            'base64'    => base64_encode($bytes),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function textAttachmentBlock(MediaAsset $asset): array
    {
        $text = $asset->markdown_content !== null && $asset->markdown_content !== ''
            ? $asset->markdown_content
            : ($asset->filename ?? $asset->id);
        $displayName = $asset->filename ?? $asset->id;
        return [
            'type' => 'text',
            'text' => "Attached file ({$displayName}):\n\n" . $text,
        ];
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
