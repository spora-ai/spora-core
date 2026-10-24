<?php

declare(strict_types=1);

namespace Spora\Agents;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;
use Spora\Agents\Exceptions\CompactionFailedException;
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

    /**
     * The ceiling on the summary request's output budget.
     *
     * The summary is a model turn, and a reasoning model spends its output
     * budget on thinking before it writes anything. A tight cap starves it:
     * it burns the whole cap thinking and returns no text at all, which
     * would replace the summarized turns with nothing. The agent's own
     * output budget is the ceiling, and this keeps a runaway summary from
     * growing past the point where it is still a summary.
     */
    private const MAX_SUMMARY_TOKENS = 8192;

    /**
     * How much of a tool result the summariser sees.
     *
     * Tool results are the bulk of a long conversation — one research turn
     * can carry tens of thousands of tokens of search output. The
     * summariser needs the gist of that evidence, not every byte: an
     * unbounded dump makes a reasoning model think for tens of thousands of
     * tokens, exhaust its budget before it writes anything, and take
     * minutes. The tool's name and query are at the head of the result, so
     * the head is what carries the signal.
     */
    private const TOOL_RESULT_CHARS = 1000;

    public function __construct(
        private readonly DriverFactory $driverFactory,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * @param  int    $maxTokensOutput  The agent's output budget. The summary
     *                                  request asks for the smaller of this
     *                                  and {@see self::MAX_SUMMARY_TOKENS}.
     * @param  float  $temperature
     * @return bool   True when a summary row was written.
     *
     * @throws CompactionFailedException  When the model returned no summary;
     *                                    the history is left unchanged.
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
                    'content' => '[tool:' . ($row->tool_name ?? '?') . '] ' . $this->truncateToolResult($content),
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
     * The head of a tool result, with a marker for the part that was cut.
     *
     * Cut on a character boundary: a byte-wise cut can split a multi-byte
     * character in half, and the request body would then be invalid UTF-8 —
     * which the HTTP client refuses to encode.
     */
    private function truncateToolResult(string $content): string
    {
        if (mb_strlen($content) <= self::TOOL_RESULT_CHARS) {
            return $content;
        }

        return mb_substr($content, 0, self::TOOL_RESULT_CHARS) . ' …[truncated]';
    }

    /**
     * @param  list<array{role: string, content: string}>  $summaryMessages
     */
    private function summarize(Agent $agent, array $summaryMessages, int $maxTokensOutput, float $temperature): string
    {
        // The summariser is not the agent. Handing it the agent's persona
        // makes it answer like the agent — a long report instead of a
        // summary — which is the opposite of what a compaction needs.
        $systemPrompt = 'You are a helpful assistant that summarises conversations.';

        $summaryInstruction = 'Summarize the conversation below concisely, preserving key facts, decisions, and any pending tasks. Output only the summary.';

        $summaryRequest = new LLMRequest(
            systemPrompt: $systemPrompt,
            messages: array_merge($summaryMessages, [['role' => 'user', 'content' => $summaryInstruction]]),
            tools: [],
            maxTokens: min($maxTokensOutput, self::MAX_SUMMARY_TOKENS),
            temperature: $temperature,
        );

        $driver   = $this->driverFactory->makeFromAgent($agent);
        $response = $driver->complete($summaryRequest);

        $summary = trim((string) ($response->content ?? ''));
        if ($summary === '') {
            // The model returned nothing. Writing the row would replace the
            // summarized turns with an empty summary, which is worse than
            // not compacting at all — the model would lose that context
            // without anything taking its place.
            throw new CompactionFailedException(
                'The model returned no summary for the conversation, so the history was left unchanged.',
            );
        }

        return $summary;
    }

    /**
     * Appends the summary row at the end of the history.
     *
     * `task_history` carries a unique index on `(task_id, sequence)`, so the
     * next sequence is read inside the transaction. `lockForUpdate()`
     * serialises that read on the engines that implement row locks; SQLite
     * compiles it away and serialises writers with a database-level lock
     * instead, so a compaction racing another there fails on the write
     * rather than claiming a sequence that is already taken.
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
