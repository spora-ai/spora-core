<?php

declare(strict_types=1);

use Spora\Agents\MessageHistoryBuilder;
use Spora\Agents\ValueObjects\ActiveCompactions;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Models\TaskHistory;

/**
 * A history row as {@see ActiveCompactions::find()} reads it.
 */
function compactionRow(int $sequence, string $role, ?string $range = null): TaskHistory
{
    return new TaskHistory([
        'sequence'                  => $sequence,
        'role'                      => $role,
        'content'                   => "Row {$sequence}",
        'summarized_sequence_range' => $range,
    ]);
}

it('parses a stored range', function (): void {
    expect(ActiveCompactions::parseRange('0-2'))->toBe([0, 2])
        ->and(ActiveCompactions::parseRange('7-7'))->toBe([7, 7]);
});

it('rejects a stored value that is not a range', function (): void {
    expect(ActiveCompactions::parseRange('not-a-range'))->toBeNull()
        ->and(ActiveCompactions::parseRange('0-'))->toBeNull()
        ->and(ActiveCompactions::parseRange(''))->toBeNull();
});

it('parses a reversed range as it is stored', function (): void {
    // The stored value is read, not validated: a reversed range covers
    // nothing, because no sequence is both above its first and below its
    // last.
    $compactions = ActiveCompactions::find(collect([
        compactionRow(0, 'user'),
        compactionRow(3, 'summary', '5-2'),
    ]));

    expect(ActiveCompactions::parseRange('5-2'))->toBe([5, 2])
        ->and($compactions->covers(3))->toBeFalse()
        ->and($compactions->covers(5))->toBeFalse();
});

it('finds no summary when the history has none', function (): void {
    $compactions = ActiveCompactions::find(collect([
        compactionRow(0, 'user'),
        compactionRow(1, 'assistant'),
    ]));

    expect($compactions->summaries())->toBe([])
        ->and($compactions->firstSequence())->toBeNull()
        ->and($compactions->lastSequence())->toBe(-1)
        ->and($compactions->covers(0))->toBeFalse();
});

it('finds the one summary in force', function (): void {
    $summary = compactionRow(3, 'summary', '0-2');

    $compactions = ActiveCompactions::find(collect([
        compactionRow(0, 'user'),
        compactionRow(1, 'assistant'),
        compactionRow(2, 'user'),
        $summary,
    ]));

    expect($compactions->summaries())->toHaveCount(1)
        ->and($compactions->summaries()[0]['row'])->toBe($summary)
        ->and($compactions->firstSequence())->toBe(0)
        ->and($compactions->lastSequence())->toBe(2);
});

it('drops a summary a later one supersedes', function (): void {
    $first  = compactionRow(3, 'summary', '0-1');
    $second = compactionRow(6, 'summary', '0-4');

    $compactions = ActiveCompactions::find(collect([
        compactionRow(0, 'user'),
        compactionRow(1, 'assistant'),
        $first,
        compactionRow(4, 'user'),
        compactionRow(5, 'assistant'),
        $second,
    ]));

    // The newer summary was written from the older one, so only the newer
    // one reaches the model.
    expect($compactions->summaries())->toHaveCount(1)
        ->and($compactions->summaries()[0]['row'])->toBe($second)
        ->and($compactions->firstSequence())->toBe(0)
        ->and($compactions->lastSequence())->toBe(4);
});

it('keeps two summaries whose ranges do not contain each other', function (): void {
    $first  = compactionRow(2, 'summary', '0-1');
    $second = compactionRow(6, 'summary', '4-5');

    $compactions = ActiveCompactions::find(collect([
        compactionRow(0, 'user'),
        compactionRow(1, 'assistant'),
        $first,
        compactionRow(3, 'user'),
        compactionRow(4, 'assistant'),
        compactionRow(5, 'user'),
        $second,
    ]));

    // Dropping either summary would drop rows whose context the model
    // never receives.
    expect($compactions->summaries())->toHaveCount(2)
        ->and($compactions->summaries()[0]['row'])->toBe($first)
        ->and($compactions->summaries()[1]['row'])->toBe($second)
        ->and($compactions->covers(0))->toBeTrue()
        ->and($compactions->covers(5))->toBeTrue()
        ->and($compactions->covers(3))->toBeFalse();
});

it('covers the rows a summary replaces and not the summary row itself', function (): void {
    $compactions = ActiveCompactions::find(collect([
        compactionRow(0, 'user'),
        compactionRow(1, 'assistant'),
        compactionRow(2, 'summary', '0-1'),
    ]));

    expect($compactions->covers(0))->toBeTrue()
        ->and($compactions->covers(1))->toBeTrue()
        ->and($compactions->covers(2))->toBeFalse()
        ->and($compactions->covers(3))->toBeFalse();
});

it('drops a summary whose stored range is malformed, rows and all', function (): void {
    $userId = bootAuthLayer()->register('active-compactions@example.com', 'Password1!', 'Active Compactions');
    $agent  = Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name'         => 'Active Compactions Agent',
        'max_steps'    => 5,
        'is_active'    => true,
    ]);

    $task = Task::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'agent_id'     => $agent->id,
        'status'       => 'COMPLETED',
        'user_prompt'  => 'p',
        'max_steps'    => 5,
    ]);

    TaskHistory::create([
        'task_id'  => $task->id,
        'sequence' => 0,
        'role'     => 'user',
        'content'  => 'Question',
    ]);
    TaskHistory::create([
        'task_id'  => $task->id,
        'sequence' => 1,
        'role'     => 'assistant',
        'content'  => 'Answer',
    ]);
    TaskHistory::create([
        'task_id'                   => $task->id,
        'sequence'                  => 2,
        'role'                      => 'summary',
        'content'                   => 'Unreadable summary.',
        'summarized_sequence_range' => 'not-a-range',
    ]);

    $compactions = ActiveCompactions::find(TaskHistory::where('task_id', $task->id)->orderBy('sequence')->get());

    expect($compactions->summaries())->toBe([]);

    // The model never receives the summary, and the rows it claims stay
    // in the conversation — the history is left as it was.
    $messages = (new MessageHistoryBuilder())->build($task->id);
    expect(array_column($messages, 'content'))->toBe(['Question', 'Answer']);
});
