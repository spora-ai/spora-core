<?php

declare(strict_types=1);

namespace Spora\Agents;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;
use Spora\Agents\ValueObjects\ActiveCompactions;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\ValueObjects\LLMRequest;
use Spora\Models\Agent;
use Spora\Models\TaskHistory;
use Spora\Services\ScrubDataUrls;
use Spora\Services\Text\Utf8Sanitizer;

/**
 * Replaces the older part of a task's history with a summary.
 *
 * The summarised rows are not deleted — they stay in `task_history` with
 * their sequences untouched, so the operator can still read them in the chat.
 * What changes is what the model is sent: {@see MessageHistoryBuilder} drops
 * the rows the active summary covers and sends the summary in their place.
 *
 * A compaction appends one `role=summary` row at the end of the history,
 * carrying the sequence range it replaces. Appending rather than inserting
 * is what keeps every existing row's sequence stable, and the SPA's
 * `since_sequence` cursor is built on that stability — an inserted row would
 * shift the rows after it and make the SPA render them twice.
 */
final class ConversationCompactor
{
    /**
     * How many rows survive a compaction verbatim. The summary stands in for
     * everything before them.
     */
    public const KEEP_ROWS = 5;

    public function __construct(
        private readonly DriverFactory $driverFactory,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * @param  int    $maxTokensOutput  The agent's output budget. The summary
     *                                  request is capped well below it.
     * @param  float  $temperature
     * @return bool   True when a summary row was written.
     */
    public function compact(int $taskId, Agent $agent, int $maxTokensOutput, float $temperature): bool
    {
        $rows = TaskHistory::where('task_id', $taskId)
            ->orderBy('sequence')
            ->get();

        $compactions = ActiveCompactions::find($rows);

        // The rows the model still sees verbatim: everything the summaries
        // already replaced is gone from the model's point of view.
        $verbatim = $rows->filter(
            static fn(TaskHistory $row): bool => $row->role !== 'summary'
                && !$compactions->covers($row->sequence),
        )->values();

        if ($verbatim->count() <= self::KEEP_ROWS + 1) {
            return false;
        }

        $shadowed = $verbatim->take($verbatim->count() - self::KEEP_ROWS);

        $summaryMessages = $this->summarizerInput($compactions, $shadowed);
        if ($summaryMessages === []) {
            return false;
        }

        $summaryText = $this->summarize($agent, $summaryMessages, $maxTokensOutput, $temperature);

        // The new range starts where the previous one did, so the summary it
        // replaces covers both — the earlier summary's rows and the ones that
        // followed them.
        $firstSequence = $compactions->firstSequence() ?? (int) $shadowed->first()->sequence;
        $lastSequence  = max((int) $shadowed->last()->sequence, $compactions->lastSequence());

        $this->appendSummaryRow($taskId, $summaryText, $firstSequence, $lastSequence);

        $this->logger?->info('Compacted task history', [
            'task_id'   => $taskId,
            'range'     => "{$firstSequence}-{$lastSequence}",
            'compacted' => $shadowed->count(),
        ]);

        return true;
    }

    /**
     * The conversation the summariser sees.
     *
     * The summaries in force are carried in: each is the model's only memory
     * of the rows it covers, so dropping it would lose that context.
     *
     * @param  Collection<int, TaskHistory>  $shadowed
     * @return list<array{role: string, content: string}>
     */
    private function summarizerInput(ActiveCompactions $compactions, Collection $shadowed): array
    {
        $messages = [];

        foreach ($compactions->summaries() as $summary) {
            $messages[] = [
                'role'    => 'user',
                'content' => $summary['row']->content ?? '',
            ];
        }

        foreach ($shadowed as $row) {
            // Even one multi-MB image can overflow the summarizer's own
            // context window.
            $content = ScrubDataUrls::scrub($row->content ?? '');

            // The summarizer request carries no prior `tool_calls` to anchor
            // against, so an unpaired tool message is rejected with error 2013.
            if ($row->role === 'tool') {
                $messages[] = [
                    'role'    => 'user',
                    'content' => '[tool:' . ($row->tool_name ?? '?') . '] ' . $content,
                ];
                continue;
            }

            if ($content === '') {
                continue;
            }

            $messages[] = [
                'role'    => $row->role === 'assistant' ? 'assistant' : 'user',
                'content' => $content,
            ];
        }

        return $messages;
    }

    /**
     * @param  list<array{role: string, content: string}>  $summaryMessages
     */
    private function summarize(Agent $agent, array $summaryMessages, int $maxTokensOutput, float $temperature): string
    {
        $systemPrompt = ($agent->system_prompt !== null && $agent->system_prompt !== '')
            ? $agent->system_prompt
            : 'You are a helpful AI assistant.';

        $summaryInstruction = 'Summarize the conversation below concisely, preserving key facts, decisions, and any pending tasks. Output only the summary.';

        $summaryRequest = new LLMRequest(
            systemPrompt: $systemPrompt,
            messages: array_merge($summaryMessages, [['role' => 'user', 'content' => $summaryInstruction]]),
            tools: [],
            maxTokens: min($maxTokensOutput, 1024),
            temperature: $temperature,
        );

        $driver   = $this->driverFactory->makeFromAgent($agent);
        $response = $driver->complete($summaryRequest);

        return $response->content ?? 'Conversation summarized.';
    }

    /**
     * Appends the summary row at the end of the history.
     *
     * The sequence is read under a row lock inside the transaction because
     * `task_history` carries a unique index on `(task_id, sequence)` — two
     * compactions racing on the same task would otherwise collide on it.
     */
    private function appendSummaryRow(int $taskId, string $summaryText, int $firstSequence, int $lastSequence): void
    {
        Capsule::connection()->transaction(function () use ($taskId, $summaryText, $firstSequence, $lastSequence): void {
            $nextSequence = (TaskHistory::where('task_id', $taskId)->lockForUpdate()->max('sequence') ?? -1) + 1;

            TaskHistory::create([
                'task_id'                   => $taskId,
                'sequence'                  => $nextSequence,
                'role'                      => 'summary',
                'content'                   => Utf8Sanitizer::scrubString($summaryText),
                'summarized_sequence_range' => "{$firstSequence}-{$lastSequence}",
            ]);
        });
    }
}
