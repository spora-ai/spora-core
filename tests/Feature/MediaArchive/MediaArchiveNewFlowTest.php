<?php

declare(strict_types=1);

namespace Tests\Feature\MediaArchive;

use InvalidArgumentException;
use Mockery;
use RuntimeException;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Models\MediaAsset;
use Spora\Services\AutoAssetStore;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\DerivativeOutput;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaAssetReader;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;
use Spora\Services\MediaArchive\MediaIngestRequest;
use Spora\Services\MediaArchive\PdfMarkdownExtractor;
use Spora\Services\MediaArchive\Producers\PdfToMarkdownProducer;
use Tests\Support\MediaArchiveTestSupport;
use Throwable;

/**
 * Coverage for the PDF → `md` extraction surface that replaced the
 * converter pipeline: what the producer advertises, what it emits, and
 * that a corrupt PDF never takes the upload down with it.
 */
afterEach(function (): void {
    // Reset the discovery list so test ordering does not leak state
    // across suites.
    MediaDerivativeProducerDiscovery::reset();
});

/** `MimeSniffer` keys on the `%PDF-` magic at offset 0; the body is irrelevant. */
const PDF_FLOW_MAGIC = "%PDF-1.4\n";

/**
 * A {@see PdfToMarkdownProducer} whose parser is a mock returning
 * `$parserReturn`, reading through a real {@see MediaAssetReader} so the
 * source-bytes lookup is exercised rather than stubbed.
 *
 * @param string|Throwable $parserReturn A Throwable makes the parser throw.
 */
function newPdfProducer(string|Throwable $parserReturn): PdfToMarkdownProducer
{
    $tmp = sys_get_temp_dir() . '/spora-pdf-flow-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;

    $parser = Mockery::mock(\Iamgerwin\PdfToMarkdownParser\PdfToMarkdownParser::class);
    $parser->shouldReceive('parseContent')
        ->andReturnUsing(static function (string $bytes) use ($parserReturn): string {
            if ($parserReturn instanceof Throwable) {
                throw $parserReturn;
            }
            return $parserReturn;
        });

    $reader = new MediaAssetReader(
        new DatabaseAssetStore(50 * 1024 * 1024),
        new LocalAssetStore(
            new Paths(BASE_PATH),
            new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
            50 * 1024 * 1024,
        ),
    );

    return new PdfToMarkdownProducer(new PdfMarkdownExtractor($parser), $reader);
}

/** A stored `data_url` PDF row, which is what `produce()` needs as its source. */
function seedPdfFlowAsset(string $filename = 'novel.pdf'): MediaAsset
{
    $service = buildPdfFlowService();
    $asset = $service->ingest(new MediaIngestRequest(
        bytes: PDF_FLOW_MAGIC . '%PDF body content',
        mime: 'application/pdf',
        filename: $filename,
        userId: 1,
        uploadSource: 'upload',
    ));
    $asset = MediaAsset::query()->find((string) $asset->id);
    expect($asset)->not->toBeNull();
    return $asset;
}

function buildPdfFlowService(): MediaArchiveService
{
    $tmp = sys_get_temp_dir() . '/spora-pdf-flow-svc-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $store = new AutoAssetStore(
        new DatabaseAssetStore(50 * 1024 * 1024),
        new LocalAssetStore(new Paths(BASE_PATH), $security, 50 * 1024 * 1024),
        1_048_576,
    );
    return MediaArchiveTestSupport::buildService($store);
}

test('PdfToMarkdownProducer advertises application/pdf and pdf', function (): void {
    $producer = newPdfProducer('');

    expect($producer->supportedSourceFormats())->toBe(['application/pdf', 'pdf']);
});

test('PdfToMarkdownProducer emits the md format with core attribution', function (): void {
    $producer = newPdfProducer('');

    expect($producer->supportedDerivativeFormats())->toBe(['md']);
    expect($producer->pluginSlug())->toBe('spora-core');
    expect($producer->operationName())->toBe('pdf.to_markdown');
});

test('PdfToMarkdownProducer trims the parser output and emits text/markdown', function (): void {
    $producer = newPdfProducer("  # Heading\n\nBody text.\n\n");

    $output = $producer->produce(seedPdfFlowAsset(), 'md');

    expect($output->bytes)->toBe("# Heading\n\nBody text.");
    expect($output->mime)->toBe('text/markdown');
});

test('PdfToMarkdownProducer propagates a parser throw', function (): void {
    $producer = newPdfProducer(new RuntimeException('corrupt pdf'));
    $asset = seedPdfFlowAsset();

    expect(fn(): DerivativeOutput => $producer->produce($asset, 'md'))
        ->toThrow(RuntimeException::class, 'corrupt pdf');
});

test('PdfToMarkdownProducer rejects a format it cannot emit', function (): void {
    $producer = newPdfProducer('unused');
    $asset = seedPdfFlowAsset();

    expect(fn(): DerivativeOutput => $producer->produce($asset, 'pdf'))
        ->toThrow(InvalidArgumentException::class, 'only the "md" derivative format is supported');
});

test('MediaDerivativeProducerDiscovery is idempotent — adding the same class twice keeps the list size', function (): void {
    MediaDerivativeProducerDiscovery::reset();
    MediaDerivativeProducerDiscovery::add(PdfToMarkdownProducer::class);
    MediaDerivativeProducerDiscovery::add(PdfToMarkdownProducer::class);

    expect(count(MediaDerivativeProducerDiscovery::all()))->toBe(1);
});
