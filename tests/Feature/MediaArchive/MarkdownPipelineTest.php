<?php

declare(strict_types=1);

namespace Tests\Feature\MediaArchive;

use Spora\Agents\MessageHistoryBuilder;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\MediaAsset;
use Spora\Models\MediaDerivative;
use Spora\Models\TaskHistory;
use Spora\Services\AutoAssetStore;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;
use Spora\Services\MediaArchive\MediaDerivativeService;
use Spora\Services\MediaArchive\MediaIngestRequest;
use Tests\Support\EmptyTextDerivativeProducer;
use Tests\Support\MediaArchiveTestSupport;
use Tests\Support\TextDerivativeProducer;
use Tests\Support\ThrowingTextDerivativeProducer;

/**
 * End-to-end test of the `md` derivative pipeline:
 *   upload text/PDF → MediaDerivativeService::ensureTextDerivative
 *   → MediaDerivativeProducerInterface → media_assets + media_derivatives
 *   → MessageHistoryBuilder reads it back → text block in the user message.
 *
 * The invariant under test: a text-ish source within the inline budget is
 * its own text (no derivative at all — which is what stops
 * `create_media` storing every document twice), a binary document gets an
 * `md` derivative, and anything out of bounds gets a `get_source` pointer
 * rather than a false claim that there is no text.
 */
afterEach(function (): void {
    MediaDerivativeProducerDiscovery::reset();
});

function buildMarkdownPipelineService(): MediaArchiveService
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
    bootAuthLayer();
    $config = LLMDriverConfiguration::create([
        'principal_id' => null,
        'name'         => 'Markdown Pipeline Config',
        'driver_class' => AnthropicCompatibleDriver::class,
        'settings'     => json_encode(['api_key' => 'test']),
        'is_global'    => true,
        'is_default'   => true,
    ]);
    $agent = \Spora\Models\Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name'                 => 'Markdown Pipeline Agent',
        'llm_driver_config_id' => $config->id,
        'max_steps'            => 10,
        'is_active'            => true,
    ]);
    return $agent->id;
}

/**
 * A task with one prompt + one attachment row, in production order
 * (Orchestrator::start writes the user row first).
 *
 * @param list<string> $mediaIds
 */
function markdownPipelineTask(int $userId, string $prompt, array $mediaIds, int $attachmentSequence = 1): \Spora\Models\Task
{
    $agentId = buildMarkdownPipelineAgent($userId);
    $task = \Spora\Models\Task::create([
        'agent_id'        => $agentId,
        'principal_id'    => createUserPrincipalPublic($userId),
        'trigger_user_id' => $userId,
        'status'          => 'RUNNING',
        'user_prompt'     => $prompt,
        'step_count'      => 0,
        'max_steps'       => 10,
    ]);

    TaskHistory::create([
        'task_id'  => $task->id,
        'sequence' => 0,
        'role'     => 'user',
        'content'  => $prompt,
    ]);
    TaskHistory::create([
        'task_id'      => $task->id,
        'sequence'     => $attachmentSequence,
        'role'         => 'attachment',
        'content'      => '',
        'attachments'  => array_map(
            static fn(string $id): array => ['media_id' => $id, 'kind' => 'text'],
            $mediaIds,
        ),
    ]);

    return $task;
}

/** The composed prompt+attachment body block, which is the last text block. */
function composedText(array $messages): string
{
    $content = $messages[0]['content'];
    expect($content)->toBeArray();
    $blocks = array_values(array_filter(
        $content,
        static fn(array $b): bool => ($b['type'] ?? '') === 'text',
    ));
    $last = end($blocks);
    expect($last)->not->toBeFalse();
    return (string) $last['text'];
}

test('a text source gets no derivative — the bytes are its own text', function (): void {
    $service = buildMarkdownPipelineService();
    $asset = $service->ingest(new MediaIngestRequest(
        bytes: "Lorem ipsum dolor sit amet\nconsectetur adipiscing elit",
        mime: 'text/plain',
        filename: 'notes.txt',
        userId: 1,
        uploadSource: 'upload',
    ));

    // No producer claims `text/*` as a source, so `ensureTextDerivative()`
    // declines. This is the half of the invariant that stops
    // `create_media` from storing every document twice.
    $derivatives = MediaDerivative::query()->where('parent_id', $asset->id)->count();
    expect($derivatives)->toBe(0);
});

test('attachment row + user prompt produce a single user message with the file text inlined', function (): void {
    $service = buildMarkdownPipelineService();
    $asset = $service->ingest(new MediaIngestRequest(
        bytes: "Lorem ipsum dolor sit amet",
        mime: 'text/plain',
        filename: 'paper.txt',
        userId: 1,
        uploadSource: 'upload',
    ));

    $userId = bootAuthLayer()->register('md-pipeline@example.com', 'Password1!', 'Md');
    $task = markdownPipelineTask($userId, 'Summarize this paper', [$asset->id]);

    $messages = (new MessageHistoryBuilder())->build($task->id);

    // Two rows in → ONE merged user message out.
    expect($messages)->toHaveCount(1);
    expect($messages[0]['role'])->toBe('user');
    // CRITICAL: never emit role: attachment on the wire.
    foreach ($messages as $msg) {
        expect($msg['role'])->not->toBe('attachment');
    }
    $text = composedText($messages);
    expect($text)->toContain('Summarize this paper');
    expect($text)->toContain('---');
    expect($text)->toContain('Lorem ipsum dolor sit amet');
});

test('attachment + prompt does not duplicate the body across blocks', function (): void {
    // Regression: buildAttachmentContent used to merge the original text blocks
    // back into the output even though composeTextContent() had already folded
    // their text into the leading combined block.
    $service = buildMarkdownPipelineService();
    $asset = $service->ingest(new MediaIngestRequest(
        bytes: "Lorem ipsum dolor sit amet",
        mime: 'text/plain',
        filename: 'paper.txt',
        userId: 1,
        uploadSource: 'upload',
    ));

    $userId = bootAuthLayer()->register('md-pipeline-dedupe@example.com', 'Password1!', 'Md');
    $task = markdownPipelineTask($userId, 'Summarize this paper', [$asset->id]);

    $messages = (new MessageHistoryBuilder())->build($task->id);

    expect($messages)->toHaveCount(1);
    expect($messages[0]['role'])->toBe('user');
    // Layout: [metadata_prefix, composedPromptBlock]. Metadata is a sibling
    // block; the composed body carries the prompt + file text.
    expect($messages[0]['content'])->toHaveCount(2);
    expect($messages[0]['content'][0]['type'])->toBe('text');
    expect($messages[0]['content'][0]['text'])->toContain('[Attached asset_id=');
    expect($messages[0]['content'][1]['type'])->toBe('text');
    $text = (string) $messages[0]['content'][1]['text'];
    expect($text)->not->toContain('[Attached asset_id=');

    // The body must appear exactly once across the composed block.
    expect(substr_count($text, 'Lorem ipsum dolor sit amet'))->toBe(1);
});

test('multiple text attachments + prompt produces a single combined block', function (): void {
    // Regression sibling: the same duplication bug fires when more than one
    // text attachment is attached alongside a typed prompt.
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

    $userId = bootAuthLayer()->register('md-pipeline-multi@example.com', 'Password1!', 'Md');
    $task = markdownPipelineTask($userId, 'Compare these notes', [$assetA->id, $assetB->id]);

    $messages = (new MessageHistoryBuilder())->build($task->id);

    expect($messages)->toHaveCount(1);
    // Layout: [metadata_a, metadata_b, composedPromptBlock].
    expect($messages[0]['content'])->toHaveCount(3);
    expect($messages[0]['content'][0]['text'])->toContain($assetA->id);
    expect($messages[0]['content'][1]['text'])->toContain($assetB->id);
    $text = (string) $messages[0]['content'][2]['text'];
    expect($text)->toContain('Compare these notes');
    expect($text)->toContain('---');
    expect(substr_count($text, 'Alpha section content.'))->toBe(1);
    expect(substr_count($text, 'Beta section content.'))->toBe(1);
});

test('a PDF gets an md derivative on ingest and the LLM sees the extracted text', function (): void {
    MediaDerivativeProducerDiscovery::add(TextDerivativeProducer::class);
    $service = buildMarkdownPipelineService();

    $asset = $service->ingest(new MediaIngestRequest(
        bytes: "%PDF-1.4\n%PDF body content",
        mime: 'application/pdf',
        filename: 'novel.pdf',
        userId: 1,
        uploadSource: 'upload',
    ));

    expect($asset->mime_type)->toBe('application/pdf');
    $derivative = MediaDerivative::query()->where('parent_id', $asset->id)->first();
    expect($derivative)->not->toBeNull();
    expect($derivative->format)->toBe('md');

    $userId = bootAuthLayer()->register('pdf-pipeline@example.com', 'Password1!', 'P');
    $task = markdownPipelineTask($userId, 'Summarize chapter 1', [$asset->id]);

    $messages = (new MessageHistoryBuilder())->build($task->id);
    expect($messages)->toHaveCount(1);
    // Layout: [metadata_prefix, composedPromptBlock].
    expect($messages[0]['content'])->toHaveCount(2);
    expect($messages[0]['content'][0]['text'])->toContain('[Attached asset_id=');
    $text = (string) $messages[0]['content'][1]['text'];
    expect($text)->toContain('Summarize chapter 1');
    expect($text)->toContain('# novel.pdf (extracted text)');
    expect($text)->toContain('extracted text');
    expect($text)->not->toContain('[Attached asset_id=');
});

test('a producer that yields nothing leaves the PDF without a derivative and the LLM gets a pointer', function (): void {
    // The scanned-PDF case: extraction succeeds but returns nothing. No
    // empty derivative row is persisted — every reader would otherwise
    // have to special-case a contentless derivative — so the message falls
    // through to the `get_source` pointer. The upload still succeeded.
    MediaDerivativeProducerDiscovery::add(EmptyTextDerivativeProducer::class);
    $service = buildMarkdownPipelineService();

    $asset = $service->ingest(new MediaIngestRequest(
        bytes: "%PDF-1.4\nscanned pages with no OCR",
        mime: 'application/pdf',
        filename: 'scan.pdf',
        userId: 1,
        uploadSource: 'upload',
    ));
    expect(MediaAsset::query()->find((string) $asset->id))->not->toBeNull();
    expect(MediaDerivative::query()->where('parent_id', $asset->id)->count())->toBe(0);

    $userId = bootAuthLayer()->register('pdf-empty@example.com', 'Password1!', 'P');
    $task = markdownPipelineTask($userId, 'What does this PDF say?', [$asset->id]);

    $messages = (new MessageHistoryBuilder())->build($task->id);
    expect($messages)->toHaveCount(1);
    expect($messages[0]['content'][0]['text'])->toContain('[Attached asset_id=');
    $text = composedText($messages);
    expect($text)->toContain('get_source');
    expect($text)->not->toContain('scanned pages with no OCR');
});

test('a throwing producer is swallowed: the upload succeeds and the LLM gets a pointer', function (): void {
    // The corruption case. The producer raises, `ensureTextDerivative()`
    // logs and returns null, and the asset is stored anyway. The LLM is
    // told where the content is rather than told it does not exist.
    MediaDerivativeProducerDiscovery::add(ThrowingTextDerivativeProducer::class);
    $service = buildMarkdownPipelineService();

    $asset = $service->ingest(new MediaIngestRequest(
        bytes: "%PDF-1.4\ncorrupt garbage",
        mime: 'application/pdf',
        filename: 'corrupt.pdf',
        userId: 1,
        uploadSource: 'upload',
    ));
    expect(MediaAsset::query()->find((string) $asset->id))->not->toBeNull();
    expect(MediaDerivative::query()->where('parent_id', $asset->id)->count())->toBe(0);

    $userId = bootAuthLayer()->register('pdf-corrupt@example.com', 'Password1!', 'P');
    $task = markdownPipelineTask($userId, 'Read this PDF', [$asset->id]);

    $messages = (new MessageHistoryBuilder())->build($task->id);
    expect($messages)->toHaveCount(1);
    expect($messages[0]['content'][0]['text'])->toContain('[Attached asset_id=');
    $text = composedText($messages);
    // The pointer names the tool that CAN read it. `[no extractable text]`
    // would be a lie here: the content exists, it just is not inlined.
    expect($text)->toContain('get_source');
    expect($text)->not->toContain('[no extractable text]');
    expect($text)->not->toContain('corrupt garbage');
});

test('production row order: user row first, attachment row second collapses to one user message', function (): void {
    $service = buildMarkdownPipelineService();
    $asset = $service->ingest(new MediaIngestRequest(
        bytes: 'Lorem ipsum dolor sit amet',
        mime: 'text/plain',
        filename: 'paper.txt',
        userId: 1,
        uploadSource: 'upload',
    ));

    $userId = bootAuthLayer()->register('md-prod-order@example.com', 'Password1!', 'P');
    $task = markdownPipelineTask($userId, 'Summarize this paper', [$asset->id]);

    $messages = (new MessageHistoryBuilder())->build($task->id);

    expect($messages)->toHaveCount(1);
    expect($messages[0]['role'])->toBe('user');
    // Layout: [metadata_prefix, composedPromptBlock].
    expect($messages[0]['content'][0]['type'])->toBe('text');
    expect($messages[0]['content'][0]['text'])->toContain('[Attached asset_id=');
    expect($messages[0]['content'][0]['text'])->toContain($asset->id);
    $text = (string) $messages[0]['content'][1]['text'];
    expect($text)->toContain('Summarize this paper');
    expect($text)->toContain('---');
    expect($text)->toContain('Lorem ipsum dolor sit amet');
    expect($text)->not->toContain('[Attached asset_id=');
    expect(substr_count($text, 'Lorem ipsum dolor sit amet'))->toBe(1);
});

test('multiple text attachments in production row order: one user message with dedup survives', function (): void {
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

    $userId = bootAuthLayer()->register('md-prod-multi@example.com', 'Password1!', 'M');
    $task = markdownPipelineTask($userId, 'Compare these notes', [$assetA->id, $assetB->id]);

    $messages = (new MessageHistoryBuilder())->build($task->id);

    expect($messages)->toHaveCount(1);
    // Layout: [metadata_a, metadata_b, composedPromptBlock].
    expect($messages[0]['content'])->toHaveCount(3);
    $text = (string) $messages[0]['content'][2]['text'];
    expect($text)->toContain('Compare these notes');
    expect($text)->toContain('---');
    expect(substr_count($text, 'Alpha section content.'))->toBe(1);
    expect(substr_count($text, 'Beta section content.'))->toBe(1);
    expect($text)->not->toContain('[Attached asset_id=');
});
