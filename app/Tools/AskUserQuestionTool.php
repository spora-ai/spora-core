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
        if ($count === 2) {
            return 'Ask the operator 2 questions';
        }
        $suffix = $count === 1 ? '' : 's';
        return "Ask the operator {$count} question{$suffix}";
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
        $questions = $this->extractQuestions($arguments['questions'] ?? null);
        if ($questions instanceof ToolResult) {
            return $questions;
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

    /**
     * @param mixed $raw
     * @return list<PendingQuestion>|ToolResult
     */
    private function extractQuestions(mixed $raw): array|ToolResult
    {
        if ($raw === null) {
            return new ToolResult(false, "Required argument 'questions' is missing.");
        }
        if (!is_array($raw) || !array_is_list($raw)) {
            return new ToolResult(false, "Argument 'questions' must be a list of 1-4 question objects.");
        }
        return $this->parseQuestions($raw);
    }

    /**
     * @param list<mixed> $raw
     * @return list<PendingQuestion>|ToolResult
     */
    private function parseQuestions(array $raw): array|ToolResult
    {
        $countError = $this->validateQuestionsCount(count($raw));
        if ($countError instanceof ToolResult) {
            return $countError;
        }
        $questions = [];
        foreach ($raw as $i => $entry) {
            $parsed = $this->parseSingleQuestion($entry, (int) $i);
            if ($parsed instanceof ToolResult) {
                return $parsed;
            }
            $questions[] = $parsed;
        }
        return $questions;
    }

    private function validateQuestionsCount(int $count): ?ToolResult
    {
        if ($count >= PendingQuestionBatch::QUESTIONS_MIN && $count <= PendingQuestionBatch::QUESTIONS_MAX) {
            return null;
        }
        return new ToolResult(
            false,
            'A single ask_user_question call must include '
            . PendingQuestionBatch::QUESTIONS_MIN . '-'
            . PendingQuestionBatch::QUESTIONS_MAX . ' questions (got '
            . $count . ').',
        );
    }

    /**
     * @param mixed $raw
     */
    private function parseSingleQuestion(mixed $raw, int $zeroBasedIndex): PendingQuestion|ToolResult
    {
        if (!is_array($raw)) {
            return new ToolResult(false, "Question #" . ($zeroBasedIndex + 1) . " must be an object.");
        }
        $shapeError = $this->validateQuestionShape($raw, $zeroBasedIndex);
        if ($shapeError instanceof ToolResult) {
            return $shapeError;
        }
        return PendingQuestion::fromLlmInput($raw);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function validateQuestionShape(array $raw, int $zeroBasedIndex): ?ToolResult
    {
        $prefix = "Question #" . ($zeroBasedIndex + 1);
        $question = trim((string) ($raw['question'] ?? ''));
        $header = trim((string) ($raw['header'] ?? ''));
        $textError = $this->validateTextAndHeader($question, $header, $prefix);
        if ($textError instanceof ToolResult) {
            return $textError;
        }
        $lengthError = $this->validateHeaderLength($header, $prefix);
        if ($lengthError instanceof ToolResult) {
            return $lengthError;
        }
        return $this->validateOptionsChain($raw['options'] ?? null, $header);
    }

    private function validateTextAndHeader(string $question, string $header, string $prefix): ?ToolResult
    {
        if ($question === '') {
            return new ToolResult(false, "{$prefix} is missing its 'question' text.");
        }
        if ($header === '') {
            return new ToolResult(false, "{$prefix} is missing its 'header' chip label.");
        }
        return null;
    }

    private function validateHeaderLength(string $header, string $prefix): ?ToolResult
    {
        $length = mb_strlen($header, 'UTF-8');
        if ($length <= PendingQuestion::HEADER_MAX) {
            return null;
        }
        return new ToolResult(
            false,
            "{$prefix} header is {$length} chars; max is "
            . PendingQuestion::HEADER_MAX . '.',
        );
    }

    /**
     * @param mixed $optionsRaw
     */
    private function validateOptionsChain(mixed $optionsRaw, string $header): ?ToolResult
    {
        if (!is_array($optionsRaw) || !array_is_list($optionsRaw)) {
            return new ToolResult(false, "Question '{$header}' must have an 'options' list.");
        }
        $count = count($optionsRaw);
        if ($count < PendingQuestion::OPTIONS_MIN || $count > PendingQuestion::OPTIONS_MAX) {
            return new ToolResult(
                false,
                "Question '{$header}' must have "
                . PendingQuestion::OPTIONS_MIN . '-'
                . PendingQuestion::OPTIONS_MAX . " options (got {$count}).",
            );
        }
        return $this->validateOptionLabels($optionsRaw, $header);
    }

    /**
     * @param list<mixed> $optionsRaw
     */
    private function validateOptionLabels(array $optionsRaw, string $header): ?ToolResult
    {
        foreach ($optionsRaw as $j => $opt) {
            if (!is_array($opt)) {
                return new ToolResult(false, "Question '{$header}' option #" . ((int) $j + 1) . " must be an object.");
            }
            if (trim((string) ($opt['label'] ?? '')) === '') {
                return new ToolResult(false, "Question '{$header}' option #" . ((int) $j + 1) . " has an empty label.");
            }
        }
        return null;
    }
}
