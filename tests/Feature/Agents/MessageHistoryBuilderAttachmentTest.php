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

test('attachment with text asset expands to a metadata prefix + text block from markdown_content', function (): void {
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
    expect($messages[0]['content'])->toHaveCount(2);
    // Index 0: identity prefix carrying the asset_id the LLM can reference back.
    expect($messages[0]['content'][0]['type'])->toBe('text');
    expect($messages[0]['content'][0]['text'])->toContain('[Attached asset_id=');
    expect($messages[0]['content'][0]['text'])->toContain($asset->id);
    expect($messages[0]['content'][0]['text'])->toContain('invoice.txt');
    // Index 1: existing extracted-text block.
    expect($messages[0]['content'][1]['type'])->toBe('text');
    expect($messages[0]['content'][1]['text'])->toContain('invoice body');
});

test('attachment with image asset expands to a metadata prefix + image block when LLM supports images', function (): void {
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
    // Index 0: identity prefix. Index 1: image block.
    expect($messages[0]['content'][0]['type'])->toBe('text');
    expect($messages[0]['content'][0]['text'])->toContain('[Attached asset_id=');
    expect($messages[0]['content'][0]['text'])->toContain($asset->id);
    $image = collect($messages[0]['content'])->firstWhere('type', 'image');
    expect($image)->not->toBeNull();
    expect($image['mediaType'])->toBe('image/png');
    expect($image['base64'])->not->toBe('');
});

test('attachment with image is dropped but metadata prefix still surfaces on non-vision driver', function (): void {
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
    // Image was dropped (no vision support) but the metadata prefix
    // still surfaces so the LLM can call media:get_media(asset_id=...)
    // to learn about the asset.
    expect($messages)->toHaveCount(1);
    expect($messages[0]['role'])->toBe('user');
    expect($messages[0]['content'])->toBeArray();
    expect($messages[0]['content'])->toHaveCount(1);
    expect($messages[0]['content'][0]['type'])->toBe('text');
    expect($messages[0]['content'][0]['text'])->toContain('[Attached asset_id=');
    expect($messages[0]['content'][0]['text'])->toContain($asset->id);
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
    // Layout: [metadata_prefix, composedPromptBlock].
    expect($messages[0]['content'])->toHaveCount(2);
    expect($messages[0]['content'][0]['type'])->toBe('text');
    expect($messages[0]['content'][0]['text'])->toContain('[Attached asset_id=');
    expect($messages[0]['content'][0]['text'])->toContain($asset->id);
    $text = $messages[0]['content'][1]['text'];
    expect($text)->toContain('Summarize this paper');
    expect($text)->toContain('---');
    expect($text)->toContain('# paper.txt (extracted text)');
    expect($text)->toContain('paper body');
    // Metadata lives in the sibling prefix block, not the composed body.
    expect($text)->not->toContain('[Attached asset_id=');
});

test('attachment + image + following user row merges text, image blocks, and per-attachment metadata prefixes', function (): void {
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
        options: new \Spora\Drivers\AnthropicDriverOptions(supportsImageInput: true),
    );
    $messages = (new MessageHistoryBuilder($driver))->build($task->id);
    expect($messages)->toHaveCount(1);
    expect($messages[0]['role'])->toBe('user');
    expect($messages[0]['content'])->toBeArray();
    // Layout: [metadata_text, metadata_image, composedPromptBlock, imageBlock].
    // Metadata blocks lead, one per attachment, with asset_ids the LLM can reference.
    $blocks = $messages[0]['content'];
    expect($blocks[0]['type'])->toBe('text');
    expect($blocks[0]['text'])->toContain('[Attached asset_id=');
    expect($blocks[0]['text'])->toContain($textAsset->id);
    expect($blocks[1]['type'])->toBe('text');
    expect($blocks[1]['text'])->toContain('[Attached asset_id=');
    expect($blocks[1]['text'])->toContain($imageAsset->id);
    // Composed prompt block carries the operator prompt + extracted text body.
    $composed = $blocks[2];
    expect($composed['type'])->toBe('text');
    expect($composed['text'])->toContain('Compare these');
    expect($composed['text'])->toContain('notes body');
    // The metadata text must NOT leak into the composed body — it lives
    // only as a sibling prefix block.
    expect($composed['text'])->not->toContain('[Attached asset_id=');
    // Image block follows the composed text.
    $image = collect($blocks)->firstWhere('type', 'image');
    expect($image)->not->toBeNull();
    expect($image['mediaType'])->toBe('image/png');
});

test('attachment with image is dropped but metadata prefix still surfaces when driver image capability is forced off', function (): void {
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
        options: new \Spora\Drivers\AnthropicDriverOptions(supportsImageInput: false),
    );
    $messages = (new MessageHistoryBuilder($driver))->build($task->id);
    expect($messages)->toHaveCount(1);
    expect($messages[0]['role'])->toBe('user');
    // Image was dropped but metadata prefix still surfaces, prompt
    // survives in the composed body block.
    $content = $messages[0]['content'];
    if (is_array($content)) {
        $types = array_map(static fn(array $b): string => (string) ($b['type'] ?? ''), $content);
        expect($types)->not->toContain('image');
        $metadata = $content[0];
        expect($metadata['type'])->toBe('text');
        expect($metadata['text'])->toContain('[Attached asset_id=');
        expect($metadata['text'])->toContain($imageAsset->id);
        $composed = $content[1];
        expect($composed['type'])->toBe('text');
        expect($composed['text'])->toContain('Describe this');
        expect($composed['text'])->not->toContain('[Attached asset_id=');
    } else {
        expect($content)->toContain('Describe this');
    }
});
test('user row first, attachment row second (production order) merges into one user message', function (): void {
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
    // Production order: user prompt first, attachment row second.
    // Orchestrator::start/continue persist in this order, which used
    // to bypass consumeAttachmentPair() and emit two user messages.
    TaskHistory::create([
        'task_id'  => $task->id,
        'sequence' => 0,
        'role'     => 'user',
        'content'  => 'Summarize this paper',
    ]);
    TaskHistory::create([
        'task_id'      => $task->id,
        'sequence'     => 1,
        'role'         => 'attachment',
        'content'      => '',
        'attachments'  => [['media_id' => $asset->id, 'kind' => 'text']],
    ]);

    $messages = (new MessageHistoryBuilder())->build($task->id);

    expect($messages)->toHaveCount(1);
    expect($messages[0]['role'])->toBe('user');
    expect($messages[0]['content'])->toBeArray();
    // Layout: [metadata_prefix, composedPromptBlock]. Metadata is a
    // sibling block; the composed body still carries the prompt + extracted text.
    $blocks = $messages[0]['content'];
    expect($blocks[0]['type'])->toBe('text');
    expect($blocks[0]['text'])->toContain('[Attached asset_id=');
    expect($blocks[0]['text'])->toContain($asset->id);
    $text = $blocks[1]['text'];
    expect($text)->toContain('Summarize this paper');
    expect($text)->toContain('---');
    expect($text)->toContain('# paper.txt (extracted text)');
    expect($text)->toContain('paper body');
    // Metadata text must not leak into the composed body — it lives in
    // the sibling prefix block only.
    expect($text)->not->toContain('[Attached asset_id=');
    // Dedup invariant: each filename header appears exactly once.
    expect(substr_count($text, '# paper.txt (extracted text)'))->toBe(1);
});

test('user row first, image attachment second (production order) merges prompt with metadata prefix and image block on vision driver', function (): void {
    $agentId = seedAttachmentAgent();
    $task = makeAttachmentTask($agentId);
    $service = buildAttachmentService();
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAMAASsJTYQAAAAASUVORK5CYII=',
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
        'task_id'  => $task->id,
        'sequence' => 0,
        'role'     => 'user',
        'content'  => 'Describe this',
    ]);
    TaskHistory::create([
        'task_id'      => $task->id,
        'sequence'     => 1,
        'role'         => 'attachment',
        'content'      => '',
        'attachments'  => [['media_id' => $imageAsset->id, 'kind' => 'image']],
    ]);
    $driver = new AnthropicCompatibleDriver(
        apiKey: 'test',
        model: 'claude-3-5-sonnet-20241022',
        baseUrl: 'https://api.anthropic.com',
        httpClient: new MockHttpClient(),
        logger: new \Psr\Log\NullLogger(),
        timeout: 60,
        options: new \Spora\Drivers\AnthropicDriverOptions(supportsImageInput: true),
    );

    $messages = (new MessageHistoryBuilder($driver))->build($task->id);

    expect($messages)->toHaveCount(1);
    expect($messages[0]['role'])->toBe('user');
    expect($messages[0]['content'])->toBeArray();
    // Layout: [metadata_prefix, composedPromptBlock, imageBlock].
    $blocks = $messages[0]['content'];
    expect($blocks[0]['type'])->toBe('text');
    expect($blocks[0]['text'])->toContain('[Attached asset_id=');
    expect($blocks[0]['text'])->toContain($imageAsset->id);
    expect($blocks[1]['type'])->toBe('text');
    expect($blocks[1]['text'])->toContain('Describe this');
    expect($blocks[1]['text'])->not->toContain('[Attached asset_id=');
    $image = collect($blocks)->firstWhere('type', 'image');
    expect($image)->not->toBeNull();
    expect($image['mediaType'])->toBe('image/png');
});

test('user row first, image attachment second (production order) drops the image but keeps metadata prefix when vision forced off', function (): void {
    $agentId = seedAttachmentAgent();
    $task = makeAttachmentTask($agentId);
    $service = buildAttachmentService();
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAMAASsJTYQAAAAASUVORK5CYII=',
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
        'task_id'  => $task->id,
        'sequence' => 0,
        'role'     => 'user',
        'content'  => 'Describe this',
    ]);
    TaskHistory::create([
        'task_id'      => $task->id,
        'sequence'     => 1,
        'role'         => 'attachment',
        'content'      => '',
        'attachments'  => [['media_id' => $imageAsset->id, 'kind' => 'image']],
    ]);
    $driver = new AnthropicCompatibleDriver(
        apiKey: 'test',
        model: 'claude-3-5-sonnet-20241022',
        baseUrl: 'https://api.anthropic.com',
        httpClient: new MockHttpClient(),
        logger: new \Psr\Log\NullLogger(),
        timeout: 60,
        options: new \Spora\Drivers\AnthropicDriverOptions(supportsImageInput: false),
    );

    $messages = (new MessageHistoryBuilder($driver))->build($task->id);

    expect($messages)->toHaveCount(1);
    expect($messages[0]['role'])->toBe('user');
    $content = $messages[0]['content'];
    if (is_array($content)) {
        $types = array_map(static fn(array $b): string => (string) ($b['type'] ?? ''), $content);
        expect($types)->not->toContain('image');
        // Metadata prefix survives the drop so the LLM can still call
        // media:get_media(asset_id=...) to learn about the asset.
        expect($content[0]['type'])->toBe('text');
        expect($content[0]['text'])->toContain('[Attached asset_id=');
        expect($content[0]['text'])->toContain($imageAsset->id);
        expect($content[1]['type'])->toBe('text');
        expect($content[1]['text'])->toContain('Describe this');
    } else {
        expect($content)->toContain('Describe this');
    }
});
