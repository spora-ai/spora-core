<?php

declare(strict_types=1);

namespace Tests\Feature\MediaArchive;

use Mockery;
use RuntimeException;
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
use Throwable;

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
    TaskHistory::create([
        'task_id'  => $task->id,
        'sequence' => 1,
        'role'     => 'user',
        'content'  => 'Summarize this paper',
    ]);

    $messages = (new MessageHistoryBuilder())->build($task->id);

    expect($messages)->toHaveCount(1);
    expect($messages[0]['role'])->toBe('user');
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
    expect(substr_count($text, '# alpha.txt (extracted text)'))->toBe(1);
    expect(substr_count($text, '# beta.txt (extracted text)'))->toBe(1);
});

/**
 * PDF fixture — `MimeSniffer` keys on the `%PDF-` magic bytes at offset 0
 * (see MimeSniffer::MAGIC_SIGNATURES), so the body content is irrelevant
 * to MIME detection. The PDF parser is replaced with a Mockery mock that
 * returns whatever the test wants — the conversion pipeline then writes
 * that into `markdown_content` and the message builder reads it back.
 */
const PDF_MAGIC = "%PDF-1.4\n";

function mockPdfParserReturning(string $markdown): \Iamgerwin\PdfToMarkdownParser\PdfToMarkdownParser
{
    $parser = Mockery::mock(\Iamgerwin\PdfToMarkdownParser\PdfToMarkdownParser::class);
    $parser->shouldReceive('parseContent')
        ->andReturn($markdown);
    return $parser;
}

function mockPdfParserThrowing(Throwable $error): \Iamgerwin\PdfToMarkdownParser\PdfToMarkdownParser
{
    $parser = Mockery::mock(\Iamgerwin\PdfToMarkdownParser\PdfToMarkdownParser::class);
    $parser->shouldReceive('parseContent')
        ->andThrow($error);
    return $parser;
}

/**
 * Build a {@see MediaArchiveService} whose {@see MediaConverterRegistry}
 * resolves {@see PdfToMarkdownConverter} with the supplied parser mock.
 *
 * The default {@see MediaArchiveTestSupport::buildConverterRegistry()}
 * builds converters through a PSR-11 stub that uses `Mockery::mock(...)`
 * with no `shouldReceive` setup, which gives us an empty return — useless
 * for asserting anything about the markdown pipeline. This helper wires
 * the caller's mock parser into the converter's constructor instead.
 */
function makePdfPipelineServiceWithParser(\Iamgerwin\PdfToMarkdownParser\PdfToMarkdownParser $parser): \Spora\Services\MediaArchive\MediaArchiveService
{
    $tmp = sys_get_temp_dir() . '/spora-pdf-pipeline-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;
    $paths    = new Paths(BASE_PATH);
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $database = new DatabaseAssetStore(50 * 1024 * 1024);
    $local    = new LocalAssetStore($paths, $security, 50 * 1024 * 1024);

    $container = new class ($parser) implements \Psr\Container\ContainerInterface {
        public function __construct(private readonly \Iamgerwin\PdfToMarkdownParser\PdfToMarkdownParser $parser) {}
        public function get(string $id): mixed
        {
            return match ($id) {
                \Spora\Services\MediaArchive\Converters\PdfToMarkdownConverter::class
                    => new \Spora\Services\MediaArchive\Converters\PdfToMarkdownConverter($this->parser),
                \Spora\Services\MediaArchive\Converters\PlainTextPassthroughConverter::class
                    => new \Spora\Services\MediaArchive\Converters\PlainTextPassthroughConverter(),
                default => throw new RuntimeException("Not registered: {$id}"),
            };
        }
        public function has(string $id): bool
        {
            return in_array($id, [
                \Spora\Services\MediaArchive\Converters\PdfToMarkdownConverter::class,
                \Spora\Services\MediaArchive\Converters\PlainTextPassthroughConverter::class,
            ], true);
        }
    };

    MediaConverterDiscovery::reset();
    MediaConverterDiscovery::add(\Spora\Services\MediaArchive\Converters\PdfToMarkdownConverter::class);
    MediaConverterDiscovery::add(\Spora\Services\MediaArchive\Converters\PlainTextPassthroughConverter::class);
    $registry = new \Spora\Services\MediaArchive\MediaConverterRegistry($container);

    $logger   = new \Psr\Log\NullLogger();
    $sniffer  = new \Spora\Services\MediaArchive\MimeSniffer();
    $metadata = new \Spora\Services\MediaArchive\MetadataExtractor($logger, false);
    $resolver = new \Spora\Services\MediaArchive\MediaArchiveUrlResolver(
        new \Spora\Services\MediaArchive\RemoteMediaFetcher(new \Symfony\Component\HttpClient\MockHttpClient([]), $logger, 30, 100 * 1024 * 1024),
        $sniffer,
        $logger,
        true,
        100 * 1024 * 1024,
    );

    return new \Spora\Services\MediaArchive\MediaArchiveService(
        new AutoAssetStore($database, $local, 1_048_576),
        $resolver,
        $sniffer,
        $metadata,
        $registry,
        new \Spora\Services\MediaArchive\MediaIngestDecoder(),
        $logger,
    );
}

test('PDF upload: parser returns text → markdown_content populated → LLM gets the text', function (): void {
    $service = makePdfPipelineServiceWithParser(mockPdfParserReturning("Chapter 1\n\nIt was the best of times."));

    $asset = $service->ingest(new MediaIngestRequest(
        bytes: PDF_MAGIC . '%PDF body content',
        mime: 'application/pdf',
        filename: 'novel.pdf',
        userId: 1,
        uploadSource: 'upload',
    ));

    expect($asset->mime_type)->toBe('application/pdf');
    expect($asset->markdown_content)->not->toBeNull();
    expect($asset->markdown_content)->toContain('Chapter 1');
    expect($asset->markdown_content)->toContain('best of times');

    $userId = bootAuthLayer()->register('pdf-pipeline@example.com', 'Password1!', 'P');
    $agentId = buildMarkdownPipelineAgent($userId);
    $task = \Spora\Models\Task::create([
        'agent_id' => $agentId, 'user_id' => $userId,
        'status' => 'RUNNING', 'user_prompt' => 'Summarize chapter 1',
        'step_count' => 0, 'max_steps' => 10,
    ]);
    TaskHistory::create([
        'task_id' => $task->id, 'sequence' => 0,
        'role' => 'attachment', 'content' => '',
        'attachments' => [['media_id' => $asset->id, 'kind' => 'text']],
    ]);
    TaskHistory::create([
        'task_id' => $task->id, 'sequence' => 1,
        'role' => 'user', 'content' => 'Summarize chapter 1',
    ]);

    $messages = (new MessageHistoryBuilder())->build($task->id);
    expect($messages)->toHaveCount(1);
    expect($messages[0]['role'])->toBe('user');
    expect($messages[0]['content'])->toHaveCount(1);
    $text = $messages[0]['content'][0]['text'];
    expect($text)->toContain('Summarize chapter 1');
    expect($text)->toContain('# novel.pdf (extracted text)');
    expect($text)->toContain('Chapter 1');
    expect($text)->toContain('best of times');
});

test('PDF upload: parser returns empty string → LLM gets [no extractable text] placeholder', function (): void {
    // This is the "scanned PDF, no OCR layer" case: the parser succeeds,
    // returns an empty string, and the converter trims it to "". The
    // asset is saved with markdown_content = "" and the LLM sees the
    // placeholder. This is the most likely reason a user observes "the
    // LLM says it has no file content" — the upload succeeded but the
    // PDF had no text layer.
    $service = makePdfPipelineServiceWithParser(mockPdfParserReturning(''));

    $asset = $service->ingest(new MediaIngestRequest(
        bytes: PDF_MAGIC . 'scanned pages with no OCR',
        mime: 'application/pdf',
        filename: 'scan.pdf',
        userId: 1,
        uploadSource: 'upload',
    ));

    // markdown_content is set to "" (empty string), not null.
    expect($asset->markdown_content)->toBe('');

    $userId = bootAuthLayer()->register('pdf-empty@example.com', 'Password1!', 'P');
    $agentId = buildMarkdownPipelineAgent($userId);
    $task = \Spora\Models\Task::create([
        'agent_id' => $agentId, 'user_id' => $userId,
        'status' => 'RUNNING', 'user_prompt' => 'What does this PDF say?',
        'step_count' => 0, 'max_steps' => 10,
    ]);
    TaskHistory::create([
        'task_id' => $task->id, 'sequence' => 0,
        'role' => 'attachment', 'content' => '',
        'attachments' => [['media_id' => $asset->id, 'kind' => 'text']],
    ]);
    TaskHistory::create([
        'task_id' => $task->id, 'sequence' => 1,
        'role' => 'user', 'content' => 'What does this PDF say?',
    ]);

    $messages = (new MessageHistoryBuilder())->build($task->id);
    expect($messages)->toHaveCount(1);
    // The placeholder is the ONLY thing the LLM has to work with — no real
    // file content reaches the prompt.
    $text = $messages[0]['content'][0]['text'];
    expect($text)->toContain('[no extractable text]');
    expect($text)->not->toContain('scanned pages with no OCR');
});

test('PDF upload: parser throws → conversion swallowed → LLM gets [no extractable text] placeholder', function (): void {
    // The corruption case. The parser raises, the registry propagates,
    // MediaArchiveService catches it, logs a warning, and continues.
    // The asset is saved without markdown_content; the LLM sees the
    // same placeholder as the empty-string case.
    $service = makePdfPipelineServiceWithParser(mockPdfParserThrowing(new RuntimeException('corrupt pdf')));

    $asset = $service->ingest(new MediaIngestRequest(
        bytes: PDF_MAGIC . 'corrupt garbage',
        mime: 'application/pdf',
        filename: 'corrupt.pdf',
        userId: 1,
        uploadSource: 'upload',
    ));

    // markdown_content stays null because the exception was caught.
    expect($asset->markdown_content)->toBeNull();

    $userId = bootAuthLayer()->register('pdf-corrupt@example.com', 'Password1!', 'P');
    $agentId = buildMarkdownPipelineAgent($userId);
    $task = \Spora\Models\Task::create([
        'agent_id' => $agentId, 'user_id' => $userId,
        'status' => 'RUNNING', 'user_prompt' => 'Read this PDF',
        'step_count' => 0, 'max_steps' => 10,
    ]);
    TaskHistory::create([
        'task_id' => $task->id, 'sequence' => 0,
        'role' => 'attachment', 'content' => '',
        'attachments' => [['media_id' => $asset->id, 'kind' => 'text']],
    ]);
    TaskHistory::create([
        'task_id' => $task->id, 'sequence' => 1,
        'role' => 'user', 'content' => 'Read this PDF',
    ]);

    $messages = (new MessageHistoryBuilder())->build($task->id);
    expect($messages)->toHaveCount(1);
    $text = $messages[0]['content'][0]['text'];
    expect($text)->toContain('[no extractable text]');
    expect($text)->not->toContain('corrupt garbage');
});

test('production row order: user row first, attachment row second → LLM still gets the file content', function (): void {
    // Orchestrator::start writes the user row first (via appendHistory)
    // and the attachment row second (via appendAttachmentRow). The
    // existing pipeline test reverses that order, which lets
    // consumeAttachmentPair() merge them into a single message —
    // a code path that does NOT fire in production.
    //
    // The LLM still has to receive the file content, but with the
    // production order the result is TWO user messages: the typed
    // prompt on its own, then a separate user message whose content
    // is the extracted markdown. The LLM should still see the file
    // content. If it claims it doesn't, this test will pin down where
    // the gap is.
    $service = buildMarkdownPipelineService();
    $asset = $service->ingest(new MediaIngestRequest(
        bytes: 'Lorem ipsum dolor sit amet',
        mime: 'text/plain',
        filename: 'paper.txt',
        userId: 1,
        uploadSource: 'upload',
    ));

    $userId = bootAuthLayer()->register('md-prod-order@example.com', 'Password1!', 'P');
    $agentId = buildMarkdownPipelineAgent($userId);
    $task = \Spora\Models\Task::create([
        'agent_id' => $agentId, 'user_id' => $userId,
        'status' => 'RUNNING', 'user_prompt' => 'Summarize this paper',
        'step_count' => 0, 'max_steps' => 10,
    ]);

    TaskHistory::create([
        'task_id' => $task->id, 'sequence' => 0,
        'role' => 'user', 'content' => 'Summarize this paper',
    ]);
    TaskHistory::create([
        'task_id' => $task->id, 'sequence' => 1,
        'role' => 'attachment', 'content' => '',
        'attachments' => [['media_id' => $asset->id, 'kind' => 'text']],
    ]);

    $messages = (new MessageHistoryBuilder())->build($task->id);

    // Whatever the merge behaviour, the LLM must receive the file content
    // somewhere in the message stream.
    $combined = '';
    foreach ($messages as $msg) {
        if (is_array($msg['content'])) {
            foreach ($msg['content'] as $block) {
                if (isset($block['text'])) {
                    $combined .= $block['text'] . "\n\n";
                }
            }
        } elseif (is_string($msg['content'])) {
            $combined .= $msg['content'] . "\n\n";
        }
    }
    expect($combined)->toContain('Summarize this paper');
    expect($combined)->toContain('# paper.txt (extracted text)');
    expect($combined)->toContain('Lorem ipsum dolor sit amet');
    expect($combined)->not->toContain('[no extractable text]');
});

test('multiple text attachments in production row order: dedup survives', function (): void {
    // Regression sibling for the production row order (user-then-attachment).
    // The single-attachment case above is not affected by the dedup bug
    // because the trivial `prompt==='' && count===1` early return fires
    // regardless of which row order produced the inputs. The bug DOES fire
    // in production for multiple text attachments: the attachment row is
    // written by appendAttachmentRow() with empty content, so buildAttachment-
    // Content sees `prompt==='' && count>1`, which skips the trivial early
    // return and used to fall into `array_merge($combined, $blocks['text'])`
    // — emitting each filename header + extracted markdown twice. This test
    // pins dedup on that production path.
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
    $agentId = buildMarkdownPipelineAgent($userId);
    $task = \Spora\Models\Task::create([
        'agent_id'    => $agentId,
        'user_id'     => $userId,
        'status'      => 'RUNNING',
        'user_prompt' => 'Compare these notes',
        'max_steps'   => 10,
    ]);

    TaskHistory::create([
        'task_id'  => $task->id,
        'sequence' => 0,
        'role'     => 'user',
        'content'  => 'Compare these notes',
    ]);
    TaskHistory::create([
        'task_id'      => $task->id,
        'sequence'     => 1,
        'role'         => 'attachment',
        'content'      => '',
        'attachments'  => [
            ['media_id' => $assetA->id, 'kind' => 'text'],
            ['media_id' => $assetB->id, 'kind' => 'text'],
        ],
    ]);

    $messages = (new MessageHistoryBuilder())->build($task->id);

    // Aggregate every text payload so dedup is checked globally across the
    // resulting message stream (the production path produces two messages:
    // the typed prompt on its own, then the attachment as a separate user
    // message — the dedup invariant lives across both).
    $combined = '';
    foreach ($messages as $msg) {
        if (is_array($msg['content'])) {
            foreach ($msg['content'] as $block) {
                if (isset($block['text'])) {
                    $combined .= $block['text'] . "\n\n";
                }
            }
        } elseif (is_string($msg['content'])) {
            $combined .= $msg['content'] . "\n\n";
        }
    }

    // Before the fix, the trailing array_merge re-appended both blocks, so
    // each marker appeared twice in $combined.
    expect(substr_count($combined, '# alpha.txt (extracted text)'))->toBe(1);
    expect(substr_count($combined, '# beta.txt (extracted text)'))->toBe(1);
    // The LLM still receives the operator's prompt and the file bodies.
    expect($combined)->toContain('Compare these notes');
    expect($combined)->toContain('Alpha section content.');
    expect($combined)->toContain('Beta section content.');
    expect($combined)->not->toContain('[no extractable text]');
});
