<?php

declare(strict_types=1);

namespace Tests\Feature\Agents;

use Spora\Agents\MessageHistoryBuilder;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Models\MediaAsset;
use Spora\Models\MediaDerivative;
use Spora\Models\TaskHistory;
use Spora\Services\AutoAssetStore;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\MediaIngestRequest;
use Symfony\Component\HttpClient\MockHttpClient;
use Tests\Support\MediaArchiveTestSupport;

defined('TEST_PASSWORD') || define('TEST_PASSWORD', 'Password1!');

/**
 * Plan §12 B2b — attachment row → content-block expansion.
 */
function seedAttachmentAgent(): int
{
    $authService = bootAuthLayer();
    $userId      = $authService->register('att@example.com', TEST_PASSWORD, 'Att');
    $config = \Spora\Models\LLMDriverConfiguration::create([
        'principal_id' => null,
        'name'         => 'Test Global Config',
        'driver_class' => AnthropicCompatibleDriver::class,
        'settings'     => json_encode(['api_key' => 'test']),
        'is_global'    => true,
        'is_default'   => true,
        'context_window'    => 200000,
        'max_tokens_output' => 4096,
    ]);
    $agent = \Spora\Models\Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
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
        'principal_id' => (int) \Spora\Models\Agent::find($agentId)->principal_id,
        'trigger_user_id' => \Spora\Models\Agent::find($agentId)->user_id,
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

test('attachment with text asset expands to a metadata prefix + text block from the raw file bytes', function (): void {
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
    // A text source is its own text: no derivative is minted, the builder
    // inlines the bytes directly.
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
    expect($text)->toContain('# paper.txt (raw text');
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
    expect($text)->toContain('# paper.txt (raw text');
    expect($text)->toContain('paper body');
    // Metadata text must not leak into the composed body — it lives in
    // the sibling prefix block only.
    expect($text)->not->toContain('[Attached asset_id=');
    // Dedup invariant: each filename header appears exactly once.
    expect(substr_count($text, '# paper.txt (raw text'))->toBe(1);
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

/**
 * The raw-bytes fallback and the pointer fallback.
 *
 * A text-ish source within the inline budget is inlined from the asset's
 * storage backend, so the LLM has the actual file content (Typst, JSON,
 * YAML, code, CSV, ...) with no extraction step involved. Anything out of
 * budget, NUL-containing, or binary-without-a-derivative falls through to
 * a body naming `get_source` -- never to `[no extractable text]`, which
 * would be a false claim about an asset whose text simply was not inlined.
 *
 * Most of these tests bypass the ingest pipeline (which would normalise
 * the mime via `MimeSniffer::sniffFromBytes`) to keep the fixture focused
 * on the renderer: a non-null bytes payload on the row itself.
 */

test('attachment inlines raw bytes for a text mime with no extraction (Typst)', function (): void {
    // Reproduces the production failure: the operator uploads a .typ
    // file and there is no extraction for it. Pre-fix the LLM got
    // `# cv.typ (extracted text)\n\n[no extractable text]` and tried
    // read_url with a file:/// URL. The LLM now gets the actual Typst
    // source inlined as a text block.
    $agentId = seedAttachmentAgent();
    $task = makeAttachmentTask($agentId);

    $typstSource = "#let name = \"Ada\"\n= Resume of #name\n\n#lorem(50)\n";
    $asset = MediaAsset::create([
        'id'           => '11111111-1111-4111-8111-111111111111',
        'asset_url'    => '/api/v1/assets/11111111-1111-4111-8111-111111111111.typ',
        'storage_mode' => 'data_url',
        'media_type'   => 'document',
        'mime_type'    => 'text/x-typst',
        'byte_size'    => strlen($typstSource),
        'user_id'      => 1,
        'payload'      => $typstSource,
        'filename'     => 'cv.typ',
    ]);

    TaskHistory::create([
        'task_id'      => $task->id,
        'sequence'     => 0,
        'role'         => 'user',
        'content'      => 'Was steht in diesem Dokument?',
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
    $blocks = $messages[0]['content'];
    // Layout: [metadata_prefix, composedPromptBlock]. The composed
    // block carries the operator prompt + the raw-text body header +
    // the file bytes.
    expect($blocks)->toHaveCount(2);
    expect($blocks[0]['type'])->toBe('text');
    expect($blocks[0]['text'])->toContain('[Attached asset_id=');
    expect($blocks[0]['text'])->toContain($asset->id);
    $composed = $blocks[1];
    expect($composed['type'])->toBe('text');
    expect($composed['text'])->toContain('Was steht in diesem Dokument?');
    expect($composed['text'])->toContain('---');
    expect($composed['text'])->toContain('# cv.typ (raw text — no converter registered)');
    expect($composed['text'])->toContain($typstSource);
    // Metadata lives in the sibling prefix block, not the composed body.
    expect($composed['text'])->not->toContain('[Attached asset_id=');
});

test('attachment inlines raw bytes for known text-based application mimes (JSON)', function (): void {
    $agentId = seedAttachmentAgent();
    $task = makeAttachmentTask($agentId);

    $json = '{"name":"Ada","skills":["Typst","PHP"]}';
    $asset = MediaAsset::create([
        'id'           => '22222222-2222-4222-8222-222222222222',
        'asset_url'    => '/api/v1/assets/22222222-2222-4222-8222-222222222222.json',
        'storage_mode' => 'data_url',
        'media_type'   => 'document',
        'mime_type'    => 'application/json',
        'byte_size'    => strlen($json),
        'user_id'      => 1,
        'payload'      => $json,
        'filename'     => 'profile.json',
    ]);

    TaskHistory::create([
        'task_id'      => $task->id,
        'sequence'     => 0,
        'role'         => 'user',
        'content'      => 'Parse this profile',
    ]);
    TaskHistory::create([
        'task_id'      => $task->id,
        'sequence'     => 1,
        'role'         => 'attachment',
        'content'      => '',
        'attachments'  => [['media_id' => $asset->id, 'kind' => 'text']],
    ]);

    $messages = (new MessageHistoryBuilder())->build($task->id);
    $composed = $messages[0]['content'][1];
    expect($composed['text'])->toContain('# profile.json (raw text — no converter registered)');
    expect($composed['text'])->toContain($json);
});

test('attachment skips raw-byte inline for a binary mime with no derivative (PDF)', function (): void {
    // PDF is the canary: a common upload format whose bytes are binary.
    // The renderer must NOT inline them as text, and must not claim
    // there is nothing to extract — the content is there, it is just not
    // inlined, so the body points at the tool that reads it.
    $agentId = seedAttachmentAgent();
    $task = makeAttachmentTask($agentId);
    // PDF magic header followed by arbitrary bytes — contains NULs.
    $pdfBytes = "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n" . str_repeat("\x00\xff", 50);
    $asset = MediaAsset::create([
        'id'           => '33333333-3333-4333-8333-333333333333',
        'asset_url'    => '/api/v1/assets/33333333-3333-4333-8333-333333333333.pdf',
        'storage_mode' => 'data_url',
        'media_type'   => 'document',
        'mime_type'    => 'application/pdf',
        'byte_size'    => strlen($pdfBytes),
        'user_id'      => 1,
        'payload'      => $pdfBytes,
        'filename'     => 'cv.pdf',
    ]);

    TaskHistory::create([
        'task_id'      => $task->id,
        'sequence'     => 0,
        'role'         => 'attachment',
        'content'      => '',
        'attachments'  => [['media_id' => $asset->id, 'kind' => 'text']],
    ]);

    $messages = (new MessageHistoryBuilder())->build($task->id);
    expect($messages)->toHaveCount(1);
    $composed = $messages[0]['content'][1];
    // The LLM is told where the content lives rather than being handed the
    // PDF's binary bytes or told it does not exist.
    expect($composed['text'])->toContain('# cv.pdf (no inline text)');
    expect($composed['text'])->toContain('get_source');
    expect($composed['text'])->not->toContain('[no extractable text]');
    // And the raw bytes do NOT leak into the body.
    expect($composed['text'])->not->toContain('%PDF-1.4');
});

test('a text source just under the 512 KB cap still arrives inline', function (): void {
    // Guards the cap against being *tightened* rather than loosened. The
    // budget is 512 KB; a 400 KB text file was already inlined under the
    // old 256 KB→512 KB change and must stay inlined.
    $agentId = seedAttachmentAgent();
    $task = makeAttachmentTask($agentId);
    $body = str_repeat('A', 400 * 1024);
    $asset = MediaAsset::create([
        'id'           => '77777777-7777-4777-8777-777777777777',
        'asset_url'    => '/api/v1/assets/77777777-7777-4777-8777-777777777777.log',
        'storage_mode' => 'data_url',
        'media_type'   => 'document',
        'mime_type'    => 'text/plain',
        'byte_size'    => strlen($body),
        'user_id'      => 1,
        'payload'      => $body,
        'filename'     => 'big-but-ok.log',
    ]);

    TaskHistory::create([
        'task_id'      => $task->id,
        'sequence'     => 0,
        'role'         => 'attachment',
        'content'      => '',
        'attachments'  => [['media_id' => $asset->id, 'kind' => 'text']],
    ]);

    $messages = (new MessageHistoryBuilder())->build($task->id);
    $composed = $messages[0]['content'][1];
    expect($composed['text'])->toContain('# big-but-ok.log (raw text');
    expect($composed['text'])->not->toContain('get_source');
});

test('a text source over the 512 KB cap produces branch 3 naming get_source', function (): void {
    $agentId = seedAttachmentAgent();
    $task = makeAttachmentTask($agentId);
    $oversize = str_repeat('A', 600 * 1024); // 600 KB — over the 512 KB budget
    $asset = MediaAsset::create([
        'id'           => '44444444-4444-4444-8444-444444444444',
        'asset_url'    => '/api/v1/assets/44444444-4444-4444-8444-444444444444.log',
        'storage_mode' => 'data_url',
        'media_type'   => 'document',
        'mime_type'    => 'text/plain',
        'byte_size'    => strlen($oversize),
        'user_id'      => 1,
        'payload'      => $oversize,
        'filename'     => 'huge.log',
    ]);

    TaskHistory::create([
        'task_id'      => $task->id,
        'sequence'     => 0,
        'role'         => 'attachment',
        'content'      => '',
        'attachments'  => [['media_id' => $asset->id, 'kind' => 'text']],
    ]);

    $messages = (new MessageHistoryBuilder())->build($task->id);
    $composed = $messages[0]['content'][1];
    expect($composed['text'])->toContain('# huge.log (no inline text)');
    expect($composed['text'])->toContain('get_source');
    // `[no extractable text]` is actively wrong here: this asset has
    // plenty of text, it just exceeds the budget.
    expect($composed['text'])->not->toContain('[no extractable text]');
    // The cap actually bounded the body: a 600 KB string would balloon
    // the context window.
    expect(strlen($composed['text']))->toBeLessThan(1024);
});

test('a large PDF md derivative is capped where it previously was not', function (): void {
    // The operator-consented tightening: branch 1 used to inline a
    // 200-page PDF's full markdown uncapped. This fixture FAILS if
    // someone removes the byte_size gate from branch 1.
    $agentId = seedAttachmentAgent();
    $task = makeAttachmentTask($agentId);
    $hugeMarkdown = str_repeat('M', 600 * 1024);
    $parent = MediaAsset::create([
        'id'           => '88888888-8888-4888-8888-888888888888',
        'asset_url'    => '/api/v1/assets/88888888-8888-4888-8888-888888888888.pdf',
        'storage_mode' => 'data_url',
        'media_type'   => 'document',
        'mime_type'    => 'application/pdf',
        'byte_size'    => 2048,
        'user_id'      => 1,
        'payload'      => '%PDF-1.4 huge',
        'filename'     => 'annual-report.pdf',
    ]);
    $derivative = MediaAsset::create([
        'id'           => '99999999-9999-4999-8999-999999999999',
        'asset_url'    => '/api/v1/assets/99999999-9999-4999-8999-999999999999.md',
        'storage_mode' => 'data_url',
        'media_type'   => 'document',
        'mime_type'    => 'text/markdown',
        'byte_size'    => strlen($hugeMarkdown),
        'user_id'      => 1,
        'payload'      => $hugeMarkdown,
        'filename'     => 'annual-report.md',
    ]);
    MediaDerivative::create([
        'id'                 => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        'parent_id'          => $parent->id,
        'derivative_id'      => $derivative->id,
        'format'             => 'md',
        'producer_plugin'    => 'tests',
        'producer_operation' => 'extract',
        'created_at'         => date('Y-m-d H:i:s'),
        'updated_at'         => date('Y-m-d H:i:s'),
    ]);

    TaskHistory::create([
        'task_id'      => $task->id,
        'sequence'     => 0,
        'role'         => 'attachment',
        'content'      => '',
        'attachments'  => [['media_id' => $parent->id, 'kind' => 'text']],
    ]);

    $messages = (new MessageHistoryBuilder())->build($task->id);
    $composed = $messages[0]['content'][1];
    // Branch 3, naming where the content is — not the whole 600 KB.
    expect($composed['text'])->toContain('# annual-report.pdf (no inline text)');
    expect($composed['text'])->toContain('get_source');
    expect($composed['text'])->not->toContain('MMMM');
    expect(strlen($composed['text']))->toBeLessThan(1024);
});

test('the byte_size gate short-circuits before the derivative is read', function (): void {
    // The derivative path costs a `media_derivatives` lookup, a second
    // `MediaAsset::find()` and a real `file_get_contents()` per attachment
    // per turn. Proving the gate runs FIRST rather than after the read:
    // the derivative claims an oversized `byte_size` while its stored
    // payload is tiny. If the renderer read it and only then checked
    // `strlen()`, the 8 bytes would be inlined as branch 1; if it checks
    // the column first, we get branch 3 and the payload is never touched.
    $agentId = seedAttachmentAgent();
    $task = makeAttachmentTask($agentId);
    $parent = MediaAsset::create([
        'id'           => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
        'asset_url'    => '/api/v1/assets/cccccccc-cccc-4ccc-8ccc-cccccccccccc.pdf',
        'storage_mode' => 'data_url',
        'media_type'   => 'document',
        'mime_type'    => 'application/pdf',
        'byte_size'    => 1024,
        'user_id'      => 1,
        'payload'      => '%PDF-1.4 small',
        'filename'     => 'tiny-but-huge.pdf',
    ]);
    $derivative = MediaAsset::create([
        'id'           => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
        'asset_url'    => '/api/v1/assets/dddddddd-dddd-4ddd-8ddd-dddddddddddd.md',
        'storage_mode' => 'data_url',
        'media_type'   => 'document',
        'mime_type'    => 'text/markdown',
        // Column says 2 MB; the payload is 11 bytes.
        'byte_size'    => 2 * 1024 * 1024,
        'user_id'      => 1,
        'payload'      => 'SHORTBODY',
        'filename'     => 'tiny-but-huge.md',
    ]);
    MediaDerivative::create([
        'id'                 => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
        'parent_id'          => $parent->id,
        'derivative_id'      => $derivative->id,
        'format'             => 'md',
        'producer_plugin'    => 'tests',
        'producer_operation' => 'extract',
        'created_at'         => date('Y-m-d H:i:s'),
        'updated_at'         => date('Y-m-d H:i:s'),
    ]);

    TaskHistory::create([
        'task_id'      => $task->id,
        'sequence'     => 0,
        'role'         => 'attachment',
        'content'      => '',
        'attachments'  => [['media_id' => $parent->id, 'kind' => 'text']],
    ]);

    $messages = (new MessageHistoryBuilder())->build($task->id);
    $composed = $messages[0]['content'][1];
    expect($composed['text'])->toContain('get_source');
    // A read-then-check implementation would have inlined these 9 bytes.
    expect($composed['text'])->not->toContain('SHORTBODY');
});

test('an in-budget md derivative is inlined as branch 1', function (): void {
    $agentId = seedAttachmentAgent();
    $task = makeAttachmentTask($agentId);
    $extracted = "# Chapter 1\n\nIt was the best of times.";
    $parent = MediaAsset::create([
        'id'           => 'abababab-abab-4bab-8bab-abababababab',
        'asset_url'    => '/api/v1/assets/abababab-abab-4bab-8bab-abababababab.pdf',
        'storage_mode' => 'data_url',
        'media_type'   => 'document',
        'mime_type'    => 'application/pdf',
        'byte_size'    => 1024,
        'user_id'      => 1,
        'payload'      => '%PDF-1.4 body',
        'filename'     => 'novel.pdf',
    ]);
    $derivative = MediaAsset::create([
        'id'           => 'cdcdcdcd-cdcd-4dcd-8dcd-cdcdcdcdcdcd',
        'asset_url'    => '/api/v1/assets/cdcdcdcd-cdcd-4dcd-8dcd-cdcdcdcdcdcd.md',
        'storage_mode' => 'data_url',
        'media_type'   => 'document',
        'mime_type'    => 'text/markdown',
        'byte_size'    => strlen($extracted),
        'user_id'      => 1,
        'payload'      => $extracted,
        'filename'     => 'novel.md',
    ]);
    MediaDerivative::create([
        'id'                 => 'efefefef-efef-4fef-8fef-efefefefefef',
        'parent_id'          => $parent->id,
        'derivative_id'      => $derivative->id,
        'format'             => 'md',
        'producer_plugin'    => 'tests',
        'producer_operation' => 'extract',
        'created_at'         => date('Y-m-d H:i:s'),
        'updated_at'         => date('Y-m-d H:i:s'),
    ]);

    TaskHistory::create([
        'task_id'      => $task->id,
        'sequence'     => 0,
        'role'         => 'attachment',
        'content'      => '',
        'attachments'  => [['media_id' => $parent->id, 'kind' => 'text']],
    ]);

    $messages = (new MessageHistoryBuilder())->build($task->id);
    $composed = $messages[0]['content'][1];
    expect($composed['text'])->toContain('# novel.pdf (extracted text)');
    expect($composed['text'])->toContain($extracted);
    // The literal header string the skill and docs describe is load-bearing.
    expect($composed['text'])->toContain('(extracted text)');
});

test('attachment rejects mislabeled binary content (text mime with NUL bytes) and points at get_source', function (): void {
    // Operator accidentally uploaded a binary file with a text mime
    // type. The leading-bytes NUL check must catch it so the LLM context
    // stays clean, and the body must still point at `get_source` — a NUL
    // in the first 4 KB is something no byte cap can rescue.
    $agentId = seedAttachmentAgent();
    $task = makeAttachmentTask($agentId);
    $binary = "PNG header would be here\n" . "\x00\x01\x02\x03\x00\x00";
    $asset = MediaAsset::create([
        'id'           => '55555555-5555-4555-8555-555555555555',
        'asset_url'    => '/api/v1/assets/55555555-5555-4555-8555-555555555555.txt',
        'storage_mode' => 'data_url',
        'media_type'   => 'document',
        'mime_type'    => 'text/plain', // mislabeled!
        'byte_size'    => strlen($binary),
        'user_id'      => 1,
        'payload'      => $binary,
        'filename'     => 'fake.txt',
    ]);

    TaskHistory::create([
        'task_id'      => $task->id,
        'sequence'     => 0,
        'role'         => 'attachment',
        'content'      => '',
        'attachments'  => [['media_id' => $asset->id, 'kind' => 'text']],
    ]);

    $messages = (new MessageHistoryBuilder())->build($task->id);
    $composed = $messages[0]['content'][1];
    expect($composed['text'])->toContain('# fake.txt (no inline text)');
    expect($composed['text'])->toContain('get_source');
    // The binary content must not leak into the body.
    expect($composed['text'])->not->toContain("\x00\x01\x02\x03");
});
