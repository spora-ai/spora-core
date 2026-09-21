<?php

declare(strict_types=1);

namespace Spora\Tools;

use Spora\Services\PrincipalContext;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Pause the tick and surface one to four structured multiple-choice
 * questions to the operator. Disabled by default — operators must opt
 * the agent in on the Tools tab before the model can use it.
 *
 * Wire shape (opencode-compatible): each question has 2-4 options
 * (`label`, `description`) and per-question `multiple` / `allowFreeText`
 * flags. The `header` is the short chip label rendered above each
 * question.
 *
 * Execution triggers a new `AWAITING_INPUT` lifecycle status (parallel
 * to `PENDING_APPROVAL`) and parks the task on the batch's tool_call_id
 * until the operator submits answers via
 * `POST /api/v1/tasks/{id}/answer`. The tool call itself never
 * resolves to a real ToolResult — the disposition flow handles
 * resuming the loop, and the operator's answers are replayed into
 * the LLM as a plaintext tool-result block (see description below).
 */
#[Tool(
    name: 'ask_user_question',
    description: 'Use this tool to get a decision from the operator that blocks your work. '
               . 'When NOT to use: clarifying user intent (use regular chat), trivial choices '
               . 'you can make yourself, or anything not blocking the work. '
               . 'Enforced limits (violations return errors): 1-4 questions per call, 2-4 '
               . 'options per question, header ≤30 chars. '
               . 'Per question, all three fields are required: '
               . '`question` (phrased as a question), '
               . '`header` (shown as a chip/tab label), '
               . '`options` (each has a `label` ≈1-5 words — soft UI guidance — and a '
               . '`description` ≈one line — soft UI guidance; options are mutually exclusive '
               . 'unless `multiple=true`, set true when the operator can pick more than one). '
               . 'Defaults: `multiple=false` (single-select chips); `allowFreeText=true` '
               . '(operator may type a custom answer, in addition to or instead of chip '
               . 'selections). '
               . 'Answer flow: the operator navigates through every question in the UI, '
               . 'then submits all selections in one reply. Do not expect turn-by-turn Q&A '
               . '— don\'t ask a follow-up based on a partial answer. '
               . 'Return shape: one tool-result block per question, in the order asked: '
               . '`[ask_user_question selections: ["Tea"]]` for single-select, '
               . '`[ask_user_question selections: ["Yes", "Morning"] free_text: "any time works"]` '
               . 'when free-text is also given.',
    displayName: 'Ask User',
    category: 'meta',
    icon: 'help-circle',
)]
#[ToolOperation(
    name: 'ask',
    description: 'Pose 1-4 structured multiple-choice questions to the operator and park the task until they answer.',
    enabledByDefault: false,
    requiresApprovalByDefault: false,
)]
#[ToolParameter(
    name: 'questions',
    type: 'array',
    description: 'The questions to ask. 1-4 entries; each entry has `question`, `header`, and `options`.',
    required: true,
)]
final class AskUserQuestionTool extends AbstractTool
{
    public function execute(
        array $arguments,
        int $agentId,
        ?int $userId = null,
        ?int $taskId = null,
        ?PrincipalContext $context = null,
    ): ToolResult {
        return $this->doAsk($arguments, $taskId);
    }

    public function describeAction(array $arguments): string
    {
        $questions = is_array($arguments['questions'] ?? null) ? $arguments['questions'] : [];
        $count = count($questions);
        return $count === 2
            ? 'Ask the operator 2 questions'
            : "Ask the operator {$count} question" . ($count === 1 ? '' : 's');
    }

    public function ask(array $arguments): ToolResult // NOSONAR php:S1172 — required by HasOperations dispatch trait
    {
        return $this->doAsk($arguments, null);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function doAsk(array $arguments, ?int $taskId): ToolResult
    {
        $questionsRaw = $arguments['questions'] ?? null;
        if ($questionsRaw === null) {
            return new ToolResult(false, "Required argument 'questions' is missing.");
        }
        if (!is_array($questionsRaw) || !array_is_list($questionsRaw)) {
            return new ToolResult(false, "Argument 'questions' must be a list of 1-4 question objects.");
        }
        if (count($questionsRaw) < PendingQuestionBatch::QUESTIONS_MIN
            || count($questionsRaw) > PendingQuestionBatch::QUESTIONS_MAX
        ) {
            return new ToolResult(
                false,
                'A single ask_user_question call must include '
                . PendingQuestionBatch::QUESTIONS_MIN . '-'
                . PendingQuestionBatch::QUESTIONS_MAX . ' questions (got '
                . count($questionsRaw) . ').',
            );
        }

        $questions = [];
        foreach ($questionsRaw as $i => $raw) {
            if (!is_array($raw)) {
                return new ToolResult(false, "Question #" . ((int) $i + 1) . " must be an object.");
            }
            $question = trim((string) ($raw['question'] ?? ''));
            if ($question === '') {
                return new ToolResult(false, "Question #" . ((int) $i + 1) . " is missing its 'question' text.");
            }
            $header = trim((string) ($raw['header'] ?? ''));
            if ($header === '') {
                return new ToolResult(false, "Question #" . ((int) $i + 1) . " is missing its 'header' chip label.");
            }
            if (mb_strlen($header, 'UTF-8') > PendingQuestion::HEADER_MAX) {
                return new ToolResult(
                    false,
                    "Question #" . ((int) $i + 1) . " header is "
                    . mb_strlen($header, 'UTF-8') . " chars; max is "
                    . PendingQuestion::HEADER_MAX . ".",
                );
            }
            $optionsRaw = $raw['options'] ?? null;
            if (!is_array($optionsRaw) || !array_is_list($optionsRaw)) {
                return new ToolResult(false, "Question '{$header}' must have an 'options' list.");
            }
            if (count($optionsRaw) < PendingQuestion::OPTIONS_MIN
                || count($optionsRaw) > PendingQuestion::OPTIONS_MAX
            ) {
                return new ToolResult(
                    false,
                    "Question '{$header}' must have "
                    . PendingQuestion::OPTIONS_MIN . '-'
                    . PendingQuestion::OPTIONS_MAX . " options (got " . count($optionsRaw) . ').',
                );
            }
            foreach ($optionsRaw as $j => $opt) {
                if (!is_array($opt)) {
                    return new ToolResult(false, "Question '{$header}' option #" . ((int) $j + 1) . " must be an object.");
                }
                $label = trim((string) ($opt['label'] ?? ''));
                if ($label === '') {
                    return new ToolResult(false, "Question '{$header}' option #" . ((int) $j + 1) . " has an empty label.");
                }
            }
            $questions[] = PendingQuestion::fromLlmInput($raw);
        }

        if ($taskId === null) {
            return new ToolResult(false, 'Cannot pose questions without a task id.');
        }

        // The tool's actual "result" is the parked-batch placeholder —
        // the caller (TickPhaseRunner) reads $result->data['pending_questions']
        // and treats the call as AwaitingInput. The persisted state and
        // history row are written by the runner, not here.
        return new ToolResult(
            true,
            'Waiting for user answers...',
            ['pending_questions' => true],
        );
    }
}
