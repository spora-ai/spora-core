<?php

declare(strict_types=1);

namespace Spora\Agents;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Agents\ValueObjects\HistoryMessageContext;
use Spora\Models\TaskHistory;

/**
 * SQL + row-shaping helpers for the task_history / usage writes that
 * {@see Orchestrator::appendHistory()} and
 * {@see Orchestrator::appendHistoryWithinTransaction()} both invoke.
 *
 * Lives outside {@see Orchestrator} so the orchestration class stays
 * focused on policy / state-machine concerns instead of growing a new
 * private helper for every persistence shape. Both methods are
 * stateless and don't need DI wiring — call statically.
 *
 * Caller contract: methods here assume the caller already holds the
 * appropriate transaction boundary if composition is required; the
 * class performs no transactional bookkeeping of its own.
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

        Capsule::table('usage')->insert([
            'task_history_id' => $historyId,
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'reasoning_tokens' => $usage->reasoningTokens,
            'cached_tokens' => $usage->cachedTokens,
            'cache_creation_tokens' => $usage->cacheCreationTokens,
            'cache_read_tokens' => $usage->cacheReadTokens,
            'provider' => $usage->provider,
            'raw_usage' => $usage->rawUsage === null
                ? null
                : json_encode($usage->rawUsage, JSON_THROW_ON_ERROR),
            'driver_meta_info' => $usage->driverMetaInfo === null
                ? null
                : json_encode($usage->driverMetaInfo, JSON_THROW_ON_ERROR),
            'created_at' => date(Orchestrator::DB_TIMESTAMP_FORMAT),
        ]);
    }
}
