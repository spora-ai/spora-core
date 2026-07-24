<?php

declare(strict_types=1);

namespace Spora\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Drivers\ValueObjects\Usage;
use Spora\Models\TaskHistory;

/**
 * Pure functions that turn a task's history rows (plus their usage
 * side-table rows) into the wire payload the admin UI consumes.
 *
 * Split out of {@see TaskService} so that the wire-shape lives next to
 * the sanitisation rules it enforces, and so that the orchestration
 * service stays focused on the task lifecycle (start / cancel / retry /
 * approve / reject). Every method here is static and side-effect free
 * (apart from the single `usage`-table read in `loadUsageByHistoryIds`
 * which the orchestrator calls from a `WHERE task_history_id IN (…)`
 * round trip to avoid N+1).
 */
final class TaskHistorySerializer
{
    /**
     * Build the per-row history payload and the parallel `usages` array that
     * drives {@see self::aggregateUsage()}. Performs a single
     * `WHERE task_history_id IN (…)` query rather than N+1 lookups.
     *
     * @param iterable<TaskHistory> $historyRows
     * @return array{history: list<array<string, mixed>>, usages: list<array<string, mixed>>}
     */
    public static function buildHistoryPayload(iterable $historyRows): array
    {
        $rows = [];
        $historyIds = [];
        $usages = [];

        foreach ($historyRows as $row) {
            $historyIds[] = $row->id;
        }
        $usageByHistoryId = self::loadUsageByHistoryIds($historyIds);

        foreach ($historyRows as $row) {
            $usage = $usageByHistoryId[$row->id] ?? null;
            if ($usage !== null) {
                $usages[] = $usage->toArray();
            }
            $rows[] = self::buildHistoryMessage($row, $usage);
        }

        return ['history' => $rows, 'usages' => $usages];
    }

    /**
     * @param list<int> $historyIds
     * @return array<int, Usage>
     */
    public static function loadUsageByHistoryIds(array $historyIds): array
    {
        if ($historyIds === []) {
            return [];
        }

        $rawRows = Capsule::table('usage')
            ->whereIn('task_history_id', $historyIds)
            ->get();

        $result = [];
        foreach ($rawRows as $rawRow) {
            $usage = new Usage(
                inputTokens: (int) ($rawRow->input_tokens ?? 0),
                outputTokens: (int) ($rawRow->output_tokens ?? 0),
                reasoningTokens: (int) ($rawRow->reasoning_tokens ?? 0),
                cachedTokens: (int) ($rawRow->cached_tokens ?? 0),
                cacheCreationTokens: (int) ($rawRow->cache_creation_tokens ?? 0),
                cacheReadTokens: (int) ($rawRow->cache_read_tokens ?? 0),
                provider: (string) ($rawRow->provider ?? 'unknown'),
                rawUsage: self::decodeJson($rawRow->raw_usage ?? null),
                driverMetaInfo: self::decodeJson($rawRow->driver_meta_info ?? null),
            );
            $result[(int) $rawRow->task_history_id] = $usage;
        }

        return $result;
    }

    /**
     * Build the wire payload for a single history row, including the
     * sanitised content blocks and (when present) a sanitised usage subobject.
     *
     * Reasoning is reachable only via `content_blocks[].type === "thinking"`.
     * The legacy flat `reasoning` column was dropped from `task_history` in
     * favour of structured content blocks; clients that need reasoning must
     * filter the blocks list.
     *
     * @return array{
     *     sequence: int,
     *     role: string,
     *     content: string|null,
     *     content_blocks: list<array<string, mixed>>,
     *     tool_call_id: string|null,
     *     tool_name: string|null,
     *     usage?: array<string, mixed>
     * }
     */
    public static function buildHistoryMessage(TaskHistory $history, ?Usage $usage = null): array
    {
        $blocks = is_array($history->content_blocks) ? $history->content_blocks : [];
        $message = [
            'sequence' => $history->sequence,
            'role' => $history->role,
            'content' => $history->content,
            'content_blocks' => self::sanitizeContentBlocksForApi($blocks),
            'tool_call_id' => $history->tool_call_id,
            'tool_name' => $history->tool_name,
        ];

        if ($usage !== null) {
            $message['usage'] = self::sanitizeUsageForApi($usage);
        }

        return $message;
    }

    /**
     * Strips server-side-only fields so the admin UI never sees Anthropic
     * signatures or encrypted redacted-thinking payloads.
     *
     * @param list<mixed> $blocks
     * @return list<array<string, mixed>>
     */
    public static function sanitizeContentBlocksForApi(array $blocks): array
    {
        $sanitized = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            unset($block['signature'], $block['data']);
            $sanitized[] = $block;
        }

        return $sanitized;
    }

    /**
     * Security boundary for the per-message usage payload sent to the admin
     * UI. Strips `raw_usage` (the verbatim provider usage subobject, kept only
     * for on-disk forensics) and `driver_meta_info` (the catch-all bag that
     * may carry provider-defined fields the operator has no business seeing).
     *
     * The remaining fields are the six typed counters plus the `provider`
     * tag.
     *
     * @return array<string, int|string>
     */
    public static function sanitizeUsageForApi(Usage $usage): array
    {
        $raw = $usage->toArray();
        unset($raw['raw_usage'], $raw['driver_meta_info']);

        return $raw;
    }

    /**
     * Sums the six token counters across the provided usage payloads. Provider
     * tag and forensics bag are intentionally excluded.
     *
     * @param list<array<string, mixed>> $usages
     * @return array<string, int>
     */
    public static function aggregateUsage(array $usages): array
    {
        $totals = [
            'input_tokens' => 0,
            'output_tokens' => 0,
            'reasoning_tokens' => 0,
            'cached_tokens' => 0,
            'cache_creation_tokens' => 0,
            'cache_read_tokens' => 0,
        ];

        foreach ($usages as $usage) {
            foreach ($totals as $key => $_) {
                $totals[$key] += (int) ($usage[$key] ?? 0);
            }
        }

        return $totals;
    }

    private static function decodeJson(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value === null || $value === '') {
            return null;
        }
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : null;
    }
}
