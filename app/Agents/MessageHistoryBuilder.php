<?php

declare(strict_types=1);

namespace Spora\Agents;

use Spora\Models\TaskHistory;

/**
 * Replays {@see TaskHistory} rows into the OpenAI-compatible message list sent
 * to the LLM each tick.
 *
 * Three responsibilities are extracted from {@see Orchestrator} so the
 * orchestrator itself stays under the SonarQube `php:S3776` cognitive-complexity
 * ceiling:
 *   1. {@see applySummaryCompaction()} — drops rows whose `sequence` falls
 *      inside a `summary` row's `summarized_sequence_range`, keeping the
 *      summary row itself.
 *   2. {@see messageFromHistoryRow()} — maps a single row into the LLM wire
 *      shape (`tool`, `assistant+tool_calls`, plain role+content), normalising
 *      empty tool-call arguments to `'{}'` along the way.
 *   3. {@see stripScaffoldingKeys()} — removes the internal `_seq` bookkeeping
 *      key so the scaffolding never leaks to the provider.
 */
final class MessageHistoryBuilder
{
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
        if ($row->role === 'tool') {
            return [
                'role'         => 'tool',
                'tool_call_id' => $row->tool_call_id,
                'name'         => $row->tool_name,
                'content'      => $row->content,
            ];
        }

        if ($row->role === 'assistant' && $row->tool_call_payload !== null) {
            return [
                'role'       => 'assistant',
                'content'    => null,
                'tool_calls' => $this->decodeToolCallPayload($row->tool_call_payload),
            ];
        }

        return [
            'role'    => $row->role,
            'content' => $row->content,
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
