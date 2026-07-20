<?php

declare(strict_types=1);

namespace Tests\Feature\MediaArchive;

use Spora\Agents\MessageHistoryBuilder;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\TaskHistory;
use Spora\Services\AutoAssetStore;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\MediaConverterDiscovery;
use Spora\Services\MediaArchive\MediaIngestRequest;
use Tests\Support\MediaArchiveTestSupport;

afterEach(function (): void {
    MediaConverterDiscovery::reset();
});

/**
 * End-to-end test that proves the full markdown pipeline:
 *   upload text/PDF file → MediaArchiveService::runConversionPipeline
 *   → MediaConverterInterface implementations → media_assets.markdown_content
 *   → MessageHistoryBuilder reads it back → emits text block in user message.
 */
function buildMarkdownPipelineService(): \Spora\Services\MediaArchive\MediaArchiveService
{
    $tmp = sys_get_temp_dir() . '/spora-md-pipeline-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;
    $paths    = new Paths(BASE_PATH);
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $database = new DatabaseAssetStore(50 * 1024 * 1024);
    $local    = new LocalAssetStore($paths, $security, 50 * 1024 * 1024);
    return MediaArchiveTestSupport::buildService(new AutoAssetStore($database, $local, 1_048_576));
}

function buildMarkdownPipelineAgent(int $userId): int
{
    $authService = bootAuthLayer();
    $config = LLMDriverConfiguration::create([
        'user_id'      => null,
        'name'         => 'Markdown Pipeline Config',
        'driver_class' => AnthropicCompatibleDriver::class,
        'settings'     => json_encode(['api_key' => 'test']),
        'is_global'    => true,
        'is_default'   => true,
    ]);
    $agent = \Spora\Models\Agent::create([
        'user_id'              => $userId,
        'name'                 => 'Markdown Pipeline Agent',
        'llm_driver_config_id' => $config->id,
        'max_steps'            => 10,
        'is_active'            => true,
    ]);
    return $agent->id;
}

test('text upload populates markdown_content end-to-end', function (): void {
    $service = buildMarkdownPipelineService();
    $asset = $service->ingest(new MediaIngestRequest(
        bytes: "Lorem ipsum dolor sit amet\nconsectetur adipiscing elit",
        mime: 'text/plain',
        filename: 'notes.txt',
        userId: 1,
        uploadSource: 'upload',
    ));
    expect($asset->markdown_content)->not->toBeNull();
    expect($asset->markdown_content)->toContain('Lorem ipsum dolor sit amet');
});

test('attachment row + user prompt produce a single user message with extracted text', function (): void {
    $service = buildMarkdownPipelineService();
    $asset = $service->ingest(new MediaIngestRequest(
        bytes: "Lorem ipsum dolor sit amet",
        mime: 'text/plain',
        filename: 'paper.txt',
        userId: 1,
        uploadSource: 'upload',
    ));

    $authService = bootAuthLayer();
    $userId = $authService->register('md-pipeline@example.com', 'Password1!', 'Md');
    $agentId = buildMarkdownPipelineAgent($userId);
    $task = \Spora\Models\Task::create([
        'agent_id'    => $agentId,
        'user_id'     => $userId,
        'status'      => 'RUNNING',
        'user_prompt' => 'Summarize this paper',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);

    TaskHistory::create([
        'task_id'      => $task->id,
        'sequence'     => 0,
        'role'         => 'attachment',
        'content'      => '',
        'attachments'  => [['media_id' => $asset->id, 'kind' => 'text']],
    ]);
    TaskHistory::create([
        'task_id'  => $task->id,
        'sequence' => 1,
        'role'     => 'user',
        'content'  => 'Summarize this paper',
    ]);

    $messages = (new MessageHistoryBuilder())->build($task->id);

    // Two rows in → ONE merged user message out.
    expect($messages)->toHaveCount(1);
    expect($messages[0]['role'])->toBe('user');
    // CRITICAL: never emit role: attachment on the wire.
    foreach ($messages as $msg) {
        expect($msg['role'])->not->toBe('attachment');
    }
    $text = $messages[0]['content'][0]['text'];
    expect($text)->toContain('Summarize this paper');
    expect($text)->toContain('---');
    expect($text)->toContain('# paper.txt (extracted text)');
    expect($text)->toContain('Lorem ipsum dolor sit amet');
});

test('attachment + prompt does not duplicate extracted text across blocks', function (): void {
    // Regression: buildAttachmentContent used to merge the original text blocks
    // back into the output even though composeTextContent() had already folded
    // their text into the leading combined block. The combined block carries
    // `prompt + --- + # filename (extracted text) + <markdown>`; the trailing
    // originals added the same `# filename (extracted text) + <markdown>`
    // again. The previous test only inspected content[0], so the duplication
    // slipped through.
    $service = buildMarkdownPipelineService();
    $asset = $service->ingest(new MediaIngestRequest(
        bytes: "Lorem ipsum dolor sit amet",
        mime: 'text/plain',
        filename: 'paper.txt',
        userId: 1,
        uploadSource: 'upload',
    ));

    $authService = bootAuthLayer();
    $userId = $authService->register('md-pipeline-dedupe@example.com', 'Password1!', 'Md');
    $agentId = buildMarkdownPipelineAgent($userId);
    $task = \Spora\Models\Task::create([
        'agent_id'    => $agentId,
        'user_id'     => $userId,
        'status'      => 'RUNNING',
        'user_prompt' => 'Summarize this paper',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);

    TaskHistory::create([
        'task_id'      => $task->id,
        'sequence'     => 0,
        'role'         => 'attachment',
        'content'      => '',
        'attachments'  => [['media_id' => $asset->id, 'kind' => 'text']],
    ]);
    // Following user row carries the prompt that lands in the leading block.
    TaskHistory::create([
        'task_id'  => $task->id,
        'sequence' => 1,
        'role'     => 'user',
        'content'  => 'Summarize this paper',
    ]);

    $messages = (new MessageHistoryBuilder())->build($task->id);

    expect($messages)->toHaveCount(1);
    expect($messages[0]['role'])->toBe('user');
    // Exactly one block: the combined text block. No trailing duplicate.
    expect($messages[0]['content'])->toHaveCount(1);
    expect($messages[0]['content'][0]['type'])->toBe('text');
    $text = $messages[0]['content'][0]['text'];

    // The marker phrase must appear exactly once across the entire message —
    // not twice (once in the combined block, once in the duplicate).
    expect(substr_count($text, '# paper.txt (extracted text)'))->toBe(1);
    expect(substr_count($text, 'Lorem ipsum dolor sit amet'))->toBe(1);
});

test('multiple text attachments + prompt produces a single combined block', function (): void {
    // Regression sibling: the same duplication bug fires when more than one
    // text attachment is attached alongside a typed prompt (or even with an
    // empty prompt). composeTextContent folds every attachment into the
    // leading block; the original blocks must NOT be appended after.
    $service = buildMarkdownPipelineService();
    $assetA = $service->ingest(new MediaIngestRequest(
        bytes: 'Alpha section content.',
        mime: 'text/plain',
        filename: 'alpha.txt',
        userId: 1,
        uploadSource: 'upload',
    ));
    $assetB = $service->ingest(new MediaIngestRequest(
        bytes: 'Beta section content.',
        mime: 'text/plain',
        filename: 'beta.txt',
        userId: 1,
        uploadSource: 'upload',
    ));

    $authService = bootAuthLayer();
    $userId = $authService->register('md-pipeline-multi@example.com', 'Password1!', 'Md');
    $agentId = buildMarkdownPipelineAgent($userId);
    $task = \Spora\Models\Task::create([
        'agent_id'    => $agentId,
        'user_id'     => $userId,
        'status'      => 'RUNNING',
        'user_prompt' => 'Compare these notes',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);

    TaskHistory::create([
        'task_id'      => $task->id,
        'sequence'     => 0,
        'role'         => 'attachment',
        'content'      => '',
        'attachments'  => [
            ['media_id' => $assetA->id, 'kind' => 'text'],
            ['media_id' => $assetB->id, 'kind' => 'text'],
        ],
    ]);
    TaskHistory::create([
        'task_id'  => $task->id,
        'sequence' => 1,
        'role'     => 'user',
        'content'  => 'Compare these notes',
    ]);

    $messages = (new MessageHistoryBuilder())->build($task->id);

    expect($messages)->toHaveCount(1);
    expect($messages[0]['content'])->toHaveCount(1);
    $text = $messages[0]['content'][0]['text'];
    expect($text)->toContain('Compare these notes');
    expect($text)->toContain('---');
    expect($text)->toContain('# alpha.txt (extracted text)');
    expect($text)->toContain('# beta.txt (extracted text)');
    // Each filename header must appear exactly once.
    expect(substr_count($text, '# alpha.txt (extracted text)'))->toBe(1);
    expect(substr_count($text, '# beta.txt (extracted text)'))->toBe(1);
});
