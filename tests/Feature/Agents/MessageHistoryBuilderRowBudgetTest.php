<?php

declare(strict_types=1);

namespace Tests\Feature\Agents;

use Spora\Agents\MessageHistoryBuilder;
use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Models\MediaAsset;
use Spora\Models\TaskHistory;

defined('ROW_BUDGET_PASSWORD') || define('ROW_BUDGET_PASSWORD', 'Password1!');

/**
 * The inline-text budget is a **row** budget, not a per-attachment one.
 *
 * `AttachmentRowRenderer::MAX_INLINE_TEXT_BYTES` is documented as "512 KB ≈
 * 131k tokens — the largest value that still fits a 200k context window with
 * room for the system prompt and history". That is a statement about one
 * message. But the cap was applied inside `collectAttachmentBlocks()`, a loop
 * over every attachment in the row with no count limit and no running total,
 * and `build()` selects every `TaskHistory` row — so the real bound was
 * 512 KB × N. Four 400 KB sources inlined 1.6 MB, and the arithmetic in the
 * docblock stopped holding at the second attachment.
 *
 * These assert the *total*, not each asset, because the per-asset behaviour
 * was already correct and already covered: the bug is only visible when the
 * attachments are counted together.
 */
function seedRowBudgetAgent(): int
{
    $authService = bootAuthLayer();
    $userId      = $authService->register('row-budget@example.com', ROW_BUDGET_PASSWORD, 'Row');

    $config = \Spora\Models\LLMDriverConfiguration::create([
        'principal_id'       => null,
        'name'               => 'Row Budget Config',
        'driver_class'       => AnthropicCompatibleDriver::class,
        'settings'           => json_encode(['api_key' => 'test']),
        'is_global'          => true,
        'is_default'         => true,
        'context_window'     => 200000,
        'max_tokens_output'  => 4096,
    ]);

    return \Spora\Models\Agent::create([
        'principal_id'       => createUserPrincipalPublic($userId),
        'name'               => 'Row Budget Agent',
        'llm_driver_config_id' => $config->id,
        'max_steps'          => 10,
        'is_active'          => true,
    ])->id;
}

function makeRowBudgetTask(int $agentId): \Spora\Models\Task
{
    $agent = \Spora\Models\Agent::find($agentId);

    return \Spora\Models\Task::create([
        'agent_id'        => $agentId,
        'principal_id'    => (int) $agent->principal_id,
        'trigger_user_id' => $agent->user_id,
        'status'          => 'RUNNING',
        'user_prompt'     => 'row budget test',
        'step_count'      => 0,
        'max_steps'       => 10,
    ]);
}

/**
 * One 300 KB `text/plain` asset, inlined raw.
 *
 * @return array{0: string, 1: string} The asset id and its payload.
 */
function makeRowBudgetAsset(string $uuid, string $filename, int $kilobytes): array
{
    // Real hex, and every caller must keep it that way: MariaDB maps
    // `$table->uuid()` to its native `UUID` type (`MariaDbGrammar::typeUuid`,
    // for 10.7+), which rejects anything outside [0-9a-f] — where MySQL maps
    // the same column to `char(36)` and never checks. So an id with a
    // non-hex letter in it passes on MySQL and SQLite and dies on MariaDB
    // with a bare QueryException that names neither the column nor the value.
    // The `char(36)` column also rejects anything that is not 8-4-4-4-12, so
    // assert the whole shape rather than the alphabet alone.
    expect($uuid)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');

    $body = str_repeat('A', $kilobytes * 1024);

    $asset = MediaAsset::create([
        'id'           => $uuid,
        'asset_url'    => "/api/v1/assets/{$uuid}.log",
        'storage_mode' => 'data_url',
        'media_type'   => 'document',
        'mime_type'    => 'text/plain',
        'byte_size'    => strlen($body),
        'user_id'      => 1,
        'payload'      => $body,
        'filename'     => $filename,
    ]);

    return [$asset->id, $body];
}

/**
 * Attach every asset to one attachment row and return every text block the
 * builder emitted for it, joined.
 *
 * All of them, not just the composed body: with N attachments the content
 * array is `[metadata × N, composed text, image…]`, so the block index moves
 * with the attachment count — and the metadata headers are part of what the
 * context window pays for.
 *
 * @param  list<string> $assetIds
 */
function buildRowBudgetMessage(array $assetIds): string
{
    $agentId = seedRowBudgetAgent();
    $task    = makeRowBudgetTask($agentId);

    TaskHistory::create([
        'task_id'     => $task->id,
        'sequence'    => 0,
        'role'        => 'attachment',
        'content'     => '',
        'attachments' => array_map(
            static fn(string $id): array => ['media_id' => $id, 'kind' => 'text'],
            $assetIds,
        ),
    ]);

    $messages = (new MessageHistoryBuilder())->build($task->id);
    expect($messages)->toHaveCount(1);

    $text = '';
    foreach ($messages[0]['content'] as $block) {
        if (($block['type'] ?? null) === 'text') {
            $text .= (string) ($block['text'] ?? '');
        }
    }

    return $text;
}

test('four in-budget attachments do not inline four budgets worth of text', function (): void {
    $ids = [];
    foreach (['a', 'b', 'c', 'd'] as $n) {
        [$id] = makeRowBudgetAsset(
            "4444444{$n}-4444-4444-8444-44444444444{$n}",
            "chunk-{$n}.log",
            300, // 300 KB — comfortably under the 512 KB per-asset cap
        );
        $ids[] = $id;
    }

    $body = buildRowBudgetMessage($ids);

    // The regression: this used to be ~1.2 MB. The budget is 512 KB, so the
    // composed body must not exceed it by more than the pointer blocks and
    // metadata headers the loop still emits for every attachment.
    expect(strlen($body))->toBeLessThan(700 * 1024);
});

test('the first attachment is still inlined; the rest fall back to get_source pointers', function (): void {
    $ids = [];
    // `e`/`f` would read fine but are not hex; `a`/`b`/`c` are, and the
    // suffixes still differ, so the three ids stay distinct.
    foreach (['a', 'b', 'c'] as $n) {
        [$id] = makeRowBudgetAsset(
            "5555555{$n}-5555-4555-8555-55555555555{$n}",
            "chunk-{$n}.log",
            300,
        );
        $ids[] = $id;
    }

    $body = buildRowBudgetMessage($ids);

    // The budget is spent by the first, so exactly one body is inlined and
    // every later attachment is named instead — which is the point: the LLM
    // still learns the content exists and can read it on demand.
    expect($body)->toContain('# chunk-a.log (raw text')
        ->and($body)->toContain('# chunk-b.log (no inline text)')
        ->and($body)->toContain('# chunk-c.log (no inline text)')
        ->and($body)->toContain('get_source');

    // And the payload of the dropped ones is genuinely absent, not merely
    // relocated: 300 KB of "A" from a later chunk would show up here.
    expect(substr_count($body, str_repeat('A', 300 * 1024)))->toBe(1);
});

test('a single attachment under the cap is unaffected', function (): void {
    // Guards the new accounting against over-reaching: the common one-file
    // case must not lose its inline body.
    [$id] = makeRowBudgetAsset('66666666-6666-4666-8666-666666666666', 'solo.log', 400);

    $body = buildRowBudgetMessage([$id]);

    expect($body)->toContain('# solo.log (raw text')
        ->and($body)->not->toContain('get_source');
});

test('attachments that fit together are all inlined, not cut at the first one', function (): void {
    // The budget is a total, not a one-shot: three small files summing well
    // under 512 KB must all arrive.
    $ids = [];
    foreach (['d', 'e', 'f'] as $n) {
        [$id] = makeRowBudgetAsset(
            "7777777{$n}-7777-4777-8777-77777777777{$n}",
            "small-{$n}.log",
            50,
        );
        $ids[] = $id;
    }

    $body = buildRowBudgetMessage($ids);

    expect($body)->toContain('# small-d.log (raw text')
        ->and($body)->toContain('# small-e.log (raw text')
        ->and($body)->toContain('# small-f.log (raw text')
        ->and($body)->not->toContain('get_source');
});
