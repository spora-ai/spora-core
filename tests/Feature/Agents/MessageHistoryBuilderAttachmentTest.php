<?php

declare(strict_types=1);

namespace Tests\Feature\Agents;

use Spora\Agents\MessageHistoryBuilder;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Models\TaskHistory;
use Spora\Services\AutoAssetStore;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\MediaConverterDiscovery;
use Spora\Services\MediaArchive\MediaIngestRequest;
use Symfony\Component\HttpClient\MockHttpClient;
use Tests\Support\MediaArchiveTestSupport;

defined('TEST_PASSWORD') || define('TEST_PASSWORD', 'Password1!');

afterEach(function (): void {
    MediaConverterDiscovery::reset();
});

/**
 * Plan §12 B2b — attachment row → content-block expansion.
 */
function seedAttachmentAgent(): int
{
    $authService = bootAuthLayer();
    $userId      = $authService->register('att@example.com', TEST_PASSWORD, 'Att');
    $config = \Spora\Models\LLMDriverConfiguration::create([
        'user_id'      => null,
        'name'         => 'Test Global Config',
        'driver_class' => AnthropicCompatibleDriver::class,
        'settings'     => json_encode(['api_key' => 'test']),
        'is_global'    => true,
        'is_default'   => true,
        'context_window'    => 200000,
        'max_tokens_output' => 4096,
    ]);
    $agent = \Spora\Models\Agent::create([
        'user_id'              => $userId,
        'name'                 => 'Attachment Agent',
        'llm_driver_config_id' => $config->id,
        'max_steps'            => 10,
        'is_active'            => true,
    ]);
    return $agent->id;
}

function makeAttachmentTask(int $agentId): \Spora\Models\Task
{
    return \Spora\Models\Task::create([
        'agent_id'    => $agentId,
        'user_id'     => \Spora\Models\Agent::find($agentId)->user_id,
        'status'      => 'RUNNING',
        'user_prompt' => 'attachment test',
        'step_count'  => 0,
        'max_steps'   => 10,
    ]);
}

function buildAttachmentService(): \Spora\Services\MediaArchive\MediaArchiveService
{
    $tmp = sys_get_temp_dir() . '/spora-attachment-' . bin2hex(random_bytes(4));
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

test('attachment with text asset expands to a text block from markdown_content', function (): void {
    $agentId = seedAttachmentAgent();
    $task = makeAttachmentTask($agentId);
    $service = buildAttachmentService();
    $asset = $service->ingest(new MediaIngestRequest(
        bytes: 'invoice body',
        mime: 'text/plain',
        filename: 'invoice.txt',
        userId: 1,
        uploadSource: 'upload',
    ));
    // The text passthrough converter populates markdown_content.
    $row = TaskHistory::create([
        'task_id'      => $task->id,
        'sequence'     => 0,
        'role'         => 'attachment',
        'content'      => '',
        'attachments'  => [['media_id' => $asset->id, 'kind' => 'text']],
    ]);
    $messages = (new MessageHistoryBuilder())->build($task->id);
    expect($messages)->toHaveCount(1);
    expect($messages[0]['role'])->toBe('user');
    expect($messages[0]['content'])->toBeArray();
    expect($messages[0]['content'][0]['type'])->toBe('text');
    expect($messages[0]['content'][0]['text'])->toContain('invoice body');
});

test('attachment with image asset expands to an image block when LLM supports images', function (): void {
    $agentId = seedAttachmentAgent();
    $task = makeAttachmentTask($agentId);
    $service = buildAttachmentService();
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=',
        strict: true,
    );
    $asset = $service->ingest(new MediaIngestRequest(
        bytes: $png,
        mime: 'image/png',
        filename: 'pixel.png',
        userId: 1,
        uploadSource: 'upload',
    ));
    TaskHistory::create([
        'task_id'      => $task->id,
        'sequence'     => 0,
        'role'         => 'attachment',
        'content'      => '',
        'attachments'  => [['media_id' => $asset->id, 'kind' => 'image']],
    ]);
    $driver = new AnthropicCompatibleDriver(
        apiKey: 'test',
        model: 'claude-3-5-sonnet-20241022',
        baseUrl: 'https://api.anthropic.com',
        httpClient: new MockHttpClient(),
        logger: new \Psr\Log\NullLogger(),
        timeout: 60,
    );
    $messages = (new MessageHistoryBuilder($driver))->build($task->id);
    expect($messages)->toHaveCount(1);
    expect($messages[0]['content'])->toBeArray();
    $block = $messages[0]['content'][0];
    expect($block['type'])->toBe('image');
    expect($block['mediaType'])->toBe('image/png');
    expect($block['base64'])->not->toBe('');
});

test('attachment with image is dropped when the LLM does not support images', function (): void {
    $agentId = seedAttachmentAgent();
    $task = makeAttachmentTask($agentId);
    $service = buildAttachmentService();
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=',
        strict: true,
    );
    $asset = $service->ingest(new MediaIngestRequest(
        bytes: $png,
        mime: 'image/png',
        filename: 'pixel.png',
        userId: 1,
        uploadSource: 'upload',
    ));
    TaskHistory::create([
        'task_id'      => $task->id,
        'sequence'     => 0,
        'role'         => 'attachment',
        'content'      => '',
        'attachments'  => [['media_id' => $asset->id, 'kind' => 'image']],
    ]);
    $driver = new \Spora\Drivers\OpenAICompatibleDriver(
        apiKey: 'test',
        model: 'gpt-3.5-turbo',
        baseUrl: 'https://api.openai.com/v1',
        httpClient: new MockHttpClient(),
        logger: new \Psr\Log\NullLogger(),
        timeout: 60,
    );
    $messages = (new MessageHistoryBuilder($driver))->build($task->id);
    // The block list is empty (image was dropped), so the message falls
    // back to the row's content.
    expect($messages)->toHaveCount(1);
    expect($messages[0]['role'])->toBe('user');
});

test('attachment row referencing a missing asset skips the block gracefully', function (): void {
    $agentId = seedAttachmentAgent();
    $task = makeAttachmentTask($agentId);
    TaskHistory::create([
        'task_id'      => $task->id,
        'sequence'     => 0,
        'role'         => 'attachment',
        'content'      => 'fallback content',
        'attachments'  => [['media_id' => '00000000-0000-0000-0000-000000000000', 'kind' => 'text']],
    ]);
    $messages = (new MessageHistoryBuilder())->build($task->id);
    expect($messages)->toHaveCount(1);
    expect($messages[0]['role'])->toBe('user');
    // No blocks produced — fall back to content string.
    expect($messages[0]['content'])->toBe('fallback content');
});

test('attachment row with empty attachments JSON still emits a user message (bug guard)', function (): void {
    $agentId = seedAttachmentAgent();
    $task = makeAttachmentTask($agentId);
    TaskHistory::create([
        'task_id'     => $task->id,
        'sequence'    => 0,
        'role'        => 'attachment',
        'content'     => 'operator typed prompt',
        'attachments' => [],
    ]);
    $messages = (new MessageHistoryBuilder())->build($task->id);
    expect($messages)->toHaveCount(1);
    expect($messages[0]['role'])->toBe('user');
    // No attachment refs → fall through to row content as plain text.
    expect($messages[0]['content'])->toBe('operator typed prompt');
});

test('attachment row with null attachments JSON still emits a user message (bug guard)', function (): void {
    $agentId = seedAttachmentAgent();
    $task = makeAttachmentTask($agentId);
    TaskHistory::create([
        'task_id'     => $task->id,
        'sequence'    => 0,
        'role'        => 'attachment',
        'content'     => 'orphan attachment',
        // attachments column defaults to null on legacy rows
    ]);
    $messages = (new MessageHistoryBuilder())->build($task->id);
    expect($messages)->toHaveCount(1);
    expect($messages[0]['role'])->toBe('user');
    expect($messages[0]['content'])->toBe('orphan attachment');
});

test('attachment + following user row merge into one user message', function (): void {
    $agentId = seedAttachmentAgent();
    $task = makeAttachmentTask($agentId);
    $service = buildAttachmentService();
    $asset = $service->ingest(new MediaIngestRequest(
        bytes: 'paper body',
        mime: 'text/plain',
        filename: 'paper.txt',
        userId: 1,
        uploadSource: 'upload',
    ));
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
    // Two rows in → one merged user message out (no `role: attachment`).
    expect($messages)->toHaveCount(1);
    expect($messages[0]['role'])->toBe('user');
    expect($messages[0]['content'])->toBeArray();
    $text = $messages[0]['content'][0]['text'];
    expect($text)->toContain('Summarize this paper');
    expect($text)->toContain('---');
    expect($text)->toContain('# paper.txt (extracted text)');
    expect($text)->toContain('paper body');
});

test('attachment + image + following user row merges text and image blocks', function (): void {
    $agentId = seedAttachmentAgent();
    $task = makeAttachmentTask($agentId);
    $service = buildAttachmentService();
    $textAsset = $service->ingest(new MediaIngestRequest(
        bytes: 'notes body',
        mime: 'text/plain',
        filename: 'notes.txt',
        userId: 1,
        uploadSource: 'upload',
    ));
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=',
        strict: true,
    );
    $imageAsset = $service->ingest(new MediaIngestRequest(
        bytes: $png,
        mime: 'image/png',
        filename: 'pixel.png',
        userId: 1,
        uploadSource: 'upload',
    ));
    TaskHistory::create([
        'task_id'      => $task->id,
        'sequence'     => 0,
        'role'         => 'attachment',
        'content'      => '',
        'attachments'  => [
            ['media_id' => $textAsset->id, 'kind' => 'text'],
            ['media_id' => $imageAsset->id, 'kind' => 'image'],
        ],
    ]);
    TaskHistory::create([
        'task_id'  => $task->id,
        'sequence' => 1,
        'role'     => 'user',
        'content'  => 'Compare these',
    ]);
    $driver = new AnthropicCompatibleDriver(
        apiKey: 'test',
        model: 'claude-3-5-sonnet-20241022',
        baseUrl: 'https://api.anthropic.com',
        httpClient: new MockHttpClient(),
        logger: new \Psr\Log\NullLogger(),
        timeout: 60,
        supportsImageInput: true,
    );
    $messages = (new MessageHistoryBuilder($driver))->build($task->id);
    expect($messages)->toHaveCount(1);
    expect($messages[0]['role'])->toBe('user');
    expect($messages[0]['content'])->toBeArray();
    $first = $messages[0]['content'][0];
    expect($first['type'])->toBe('text');
    expect($first['text'])->toContain('Compare these');
    expect($first['text'])->toContain('notes body');
    // Image block follows the text block.
    $image = collect($messages[0]['content'])->firstWhere('type', 'image');
    expect($image)->not->toBeNull();
    expect($image['mediaType'])->toBe('image/png');
});

test('attachment with image is dropped when driver image capability is forced off', function (): void {
    $agentId = seedAttachmentAgent();
    $task = makeAttachmentTask($agentId);
    $service = buildAttachmentService();
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=',
        strict: true,
    );
    $imageAsset = $service->ingest(new MediaIngestRequest(
        bytes: $png,
        mime: 'image/png',
        filename: 'pixel.png',
        userId: 1,
        uploadSource: 'upload',
    ));
    TaskHistory::create([
        'task_id'      => $task->id,
        'sequence'     => 0,
        'role'         => 'attachment',
        'content'      => '',
        'attachments'  => [['media_id' => $imageAsset->id, 'kind' => 'image']],
    ]);
    TaskHistory::create([
        'task_id'  => $task->id,
        'sequence' => 1,
        'role'     => 'user',
        'content'  => 'Describe this',
    ]);
    // Driver with vision capability explicitly turned off.
    $driver = new AnthropicCompatibleDriver(
        apiKey: 'test',
        model: 'claude-3-5-sonnet-20241022',
        baseUrl: 'https://api.anthropic.com',
        httpClient: new MockHttpClient(),
        logger: new \Psr\Log\NullLogger(),
        timeout: 60,
        supportsImageInput: false,
    );
    $messages = (new MessageHistoryBuilder($driver))->build($task->id);
    expect($messages)->toHaveCount(1);
    expect($messages[0]['role'])->toBe('user');
    // Image was dropped (no image block in content); the prompt survives.
    $content = $messages[0]['content'];
    if (is_array($content)) {
        $types = array_map(static fn(array $b): string => (string) ($b['type'] ?? ''), $content);
        expect($types)->not->toContain('image');
    } else {
        expect($content)->toContain('Describe this');
    }
});
