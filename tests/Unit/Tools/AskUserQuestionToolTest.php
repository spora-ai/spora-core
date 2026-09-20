<?php

declare(strict_types=1);

use Spora\Tools\AskUserQuestionTool;
use Spora\Tools\PendingQuestion;

function askUserQuestionTool(): AskUserQuestionTool
{
    return new AskUserQuestionTool();
}

it('rejects when questions argument is missing', function (): void {
    $result = askUserQuestionTool()->execute([], 1, null, 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain("'questions' is missing");
});

it('rejects when questions is not a list', function (): void {
    $result = askUserQuestionTool()->execute(['questions' => 'not-list'], 1, null, 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('list');
});

it('rejects when fewer than 1 question is supplied', function (): void {
    $result = askUserQuestionTool()->execute(['questions' => []], 1, null, 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('1-4 questions');
});

it('rejects when more than 4 questions are supplied', function (): void {
    $questions = [];
    for ($i = 0; $i < 5; $i++) {
        $questions[] = validQuestion("header {$i}", 'Q?');
    }
    $result = askUserQuestionTool()->execute(['questions' => $questions], 1, null, 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('1-4 questions');
});

it('rejects a question without text', function (): void {
    $result = askUserQuestionTool()->execute([
        'questions' => [
            ['question' => '', 'header' => 'H', 'options' => [['label' => 'a'], ['label' => 'b']]],
        ],
    ], 1, null, 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain("missing its 'question' text");
});

it('rejects a question without header', function (): void {
    $result = askUserQuestionTool()->execute([
        'questions' => [
            ['question' => 'why', 'header' => '', 'options' => [['label' => 'a'], ['label' => 'b']]],
        ],
    ], 1, null, 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain("missing its 'header'");
});

it('rejects a header longer than 30 characters', function (): void {
    $result = askUserQuestionTool()->execute([
        'questions' => [
            validQuestion(str_repeat('h', 31), 'Q?'),
        ],
    ], 1, null, 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('31 chars')
        ->and($result->content)->toContain('30');
});

it('rejects fewer than 2 options per question', function (): void {
    $result = askUserQuestionTool()->execute([
        'questions' => [
            ['question' => 'q', 'header' => 'h', 'options' => [['label' => 'a']]],
        ],
    ], 1, null, 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('2-4 options');
});

it('rejects more than 4 options per question', function (): void {
    $options = [['label' => 'a'], ['label' => 'b'], ['label' => 'c'], ['label' => 'd'], ['label' => 'e']];
    $result = askUserQuestionTool()->execute([
        'questions' => [
            ['question' => 'q', 'header' => 'h', 'options' => $options],
        ],
    ], 1, null, 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('2-4 options');
});

it('rejects an option with empty label', function (): void {
    $result = askUserQuestionTool()->execute([
        'questions' => [
            ['question' => 'q', 'header' => 'h', 'options' => [['label' => ''], ['label' => 'b']]],
        ],
    ], 1, null, 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('empty label');
});

it('rejects when running without a task id', function (): void {
    $result = askUserQuestionTool()->execute([
        'questions' => [validQuestion('h', 'q')],
    ], 1, null, null);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('task id');
});

it('returns the pending-questions placeholder for valid input', function (): void {
    $taskId = createAskTestTask();
    $result = askUserQuestionTool()->execute([
        'questions' => [validQuestion('db', 'Which database?')],
    ], 1, null, $taskId);
    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Waiting for user answers')
        ->and($result->data['pending_questions'])->toBeTrue();
});

it('defaults multiple=false and allowFreeText=true', function (): void {
    $taskId = createAskTestTask();
    $tool = askUserQuestionTool();
    $result = $tool->execute([
        'questions' => [
            [
                'question' => 'Pick one',
                'header'   => 'Pick',
                'options'  => [['label' => 'A'], ['label' => 'B']],
            ],
        ],
    ], 1, null, $taskId);
    expect($result->success)->toBeTrue();

    $pendingQuestion = PendingQuestion::fromLlmInput([
        'question' => 'Pick one',
        'header'   => 'Pick',
        'options'  => [['label' => 'A'], ['label' => 'B']],
    ]);
    expect($pendingQuestion->multiple)->toBeFalse()
        ->and($pendingQuestion->allowFreeText)->toBeTrue();
});

it('emits a single-operation schema with the ask op', function (): void {
    $schema = askUserQuestionTool()->getParametersSchema();
    expect($schema['type'])->toBe('object')
        ->and($schema['properties'])->toHaveKey('questions');
});

function validQuestion(string $header, string $question): array
{
    return [
        'question' => $question,
        'header'   => $header,
        'options'  => [
            ['label' => 'Option 1', 'description' => 'First'],
            ['label' => 'Option 2', 'description' => 'Second'],
        ],
    ];
}

function createAskTestTask(): int
{
    $userId = bootAuthLayer()->register('ask-tool@example.com', 'Password1!', 'Ask');
    simulateLoggedInSession($userId, 'ask-tool@example.com');

    $agent = Spora\Models\Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name'         => 'Ask Agent',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);

    return Spora\Models\Task::create([
        'agent_id'       => $agent->id,
        'principal_id'   => $agent->principal_id,
        'trigger_user_id' => $userId,
        'status'         => 'RUNNING',
        'user_prompt'    => 'p',
        'step_count'     => 0,
        'max_steps'      => 10,
    ])->id;
}
