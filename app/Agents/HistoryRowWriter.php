<?php

declare(strict_types=1);

namespace Spora\Agents;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Agents\ValueObjects\HistoryMessageContext;
use Spora\Models\TaskHistory;
use Spora\Models\Usage;
use Spora\Services\ScrubDataUrls;
use Spora\Services\Text\Utf8Sanitizer;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * SQL + row-shaping helpers for the task_history / usage writes that
 * {@see Orchestrator::appendHistory()},
 * {@see Orchestrator::appendHistoryWithinTransaction()} and
 * {@see self::appendToolResult()} all invoke.
 *
 * Lives outside {@see Orchestrator} so the orchestration class stays
 * focused on policy / state-machine concerns instead of growing a new
 * private helper for every persistence shape. Both methods are
 * stateless and don't need DI wiring — call statically.
 *
 * Caller contract: {@see self::appendWithinTransaction()} assumes the
 * caller already holds the transaction boundary if composition is
 * required; {@see self::append()} opens its own.
 */
final class HistoryRowWriter
{
    /**
     * Shape the TaskHistory row from the canonical context fields.
     * Optionally writes content_blocks / attachments depending on
     * context payload — those columns are only set when the LLM turn
     * provided them, so leaving them absent must not collide with the
     * default JSON nulls in the schema.
     *
     * @return array<string, mixed>
     */
    public static function buildRow(
        int                    $taskId,
        string                 $role,
        ?string                $content,
        ?HistoryMessageContext $context,
    ): array {
        $context ??= new HistoryMessageContext();
        $row = [
            'task_id' => $taskId,
            'role' => $role,
            'content' => $content,
            'tool_call_id' => $context->toolCallId,
            'tool_name' => $context->toolName,
            'tool_call_payload' => $context->toolCallPayload,
            'input_tokens' => $context->inputTokens,
            'output_tokens' => $context->outputTokens,
        ];

        if ($context->contentBlocks !== []) {
            $row['content_blocks'] = array_map(
                static fn(\Spora\Drivers\ValueObjects\ContentBlock $block): array => $block->toArray(),
                $context->contentBlocks,
            );
        }

        if ($context->attachments !== null) {
            $row['attachments'] = $context->attachments;
        }

        return $row;
    }

    /**
     * Insert a usage row paired with a freshly-written history row, when
     * the LLM turn reported token / cost telemetry. No-op when the
     * context didn't include usage; cheaper than forwarding the work
     * back to the caller.
     */
    public static function insertUsageIfPresent(int $historyId, ?\Spora\Drivers\ValueObjects\Usage $usage): void
    {
        if ($usage === null) {
            return;
        }

        (new Usage([
            'task_history_id' => $historyId,
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'reasoning_tokens' => $usage->reasoningTokens,
            'cached_tokens' => $usage->cachedTokens,
            'cache_creation_tokens' => $usage->cacheCreationTokens,
            'cache_read_tokens' => $usage->cacheReadTokens,
            'provider' => $usage->provider,
            'raw_usage' => $usage->rawUsage,
            'driver_meta_info' => $usage->driverMetaInfo,
            'created_at' => date(Orchestrator::DB_TIMESTAMP_FORMAT),
        ]))->save();
    }

    /**
     * Append one history row.
     *
     * The sequence number is read under a row lock, so two writers cannot
     * claim the same one, and the row and its usage entry commit together.
     */
    public static function append(
        int                    $taskId,
        string                 $role,
        ?string                $content,
        ?HistoryMessageContext $context = null,
    ): void {
        $context ??= new HistoryMessageContext();
        $row  = self::buildRow($taskId, $role, $content, $context);
        $usage = $context->usage;

        Capsule::connection()->transaction(function () use ($taskId, $row, $usage): void {
            $nextSeq = TaskHistory::where('task_id', $taskId)->lockForUpdate()->max('sequence') ?? -1;
            $row['sequence'] = $nextSeq + 1;
            $history = TaskHistory::create($row);

            self::insertUsageIfPresent($history->id, $usage);
        });
    }

    /**
     * Append one history row inside a transaction the caller already holds.
     *
     * The caller's transaction boundary is the point: the row has to commit
     * together with the state change it belongs to.
     */
    public static function appendWithinTransaction(
        int                    $taskId,
        string                 $role,
        ?string                $content,
        ?HistoryMessageContext $context = null,
    ): void {
        $context ??= new HistoryMessageContext();

        $row = self::buildRow($taskId, $role, $content, $context);

        $nextSeq = TaskHistory::where('task_id', $taskId)->max('sequence') ?? -1;
        $row['sequence'] = $nextSeq + 1;
        $history = TaskHistory::create($row);

        self::insertUsageIfPresent($history->id, $context->usage);
    }

    /**
     * Append the result of a tool call as a `tool` history row.
     *
     * The one writer for the rows that carry a real {@see ToolResult}: the
     * content is scrubbed of data URLs, and the result's attachments travel
     * with the row so the builder can put the assets in front of the model.
     * A result without attachments leaves the column null, exactly as the
     * rows written before the channel existed.
     */
    public static function appendToolResult(int $taskId, ToolResult $result, string $toolCallId, string $toolName): void
    {
        self::append(
            $taskId,
            'tool',
            ScrubDataUrls::scrub(Utf8Sanitizer::scrubString($result->content)),
            new HistoryMessageContext(
                toolCallId: $toolCallId,
                toolName: $toolName,
                attachments: $result->attachments === [] ? null : $result->attachments,
            ),
        );
    }
}
