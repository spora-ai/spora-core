<?php

declare(strict_types=1);

namespace Tests\Support;

use Mockery;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use RuntimeException;
use Spora\Auth\AuthService;
use Spora\Services\AssetStore;
use Spora\Services\MediaArchive\MediaArchiveIngestPipeline;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaArchiveUrlResolver;
use Spora\Services\MediaArchive\MediaAssetReader;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;
use Spora\Services\MediaArchive\MediaDerivativeService;
use Spora\Services\MediaArchive\MediaIngestDecoder;
use Spora\Services\MediaArchive\MetadataExtractor;
use Spora\Services\MediaArchive\MimeSniffer;
use Spora\Services\MediaArchive\PdfMarkdownExtractor;
use Spora\Services\MediaArchive\Producers\PdfToMarkdownProducer;
use Spora\Services\MediaArchive\RemoteMediaFetcher;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builder helper for {@see MediaArchiveService} in tests.
 *
 * Each test that needs a service used to inline the 7-arg constructor
 * call. After the URL-branch extraction that moved `promoteExternal` and
 * `maxPromoteBytes` into a new {@see MediaArchiveUrlResolver}, every
 * site would also need to construct the resolver first. This helper
 * folds that boilerplate down to a single call.
 */
final class MediaArchiveTestSupport
{
    public static function buildService(
        AssetStore $assetStore,
        ?HttpClientInterface $http = null,
        ?LoggerInterface $logger = null,
        bool $promoteExternal = true,
        int $maxPromoteBytes = 100 * 1024 * 1024,
        bool $ffprobeEnabled = false,
        ?RemoteMediaFetcher $fetcher = null,
        ?MimeSniffer $sniffer = null,
        ?MetadataExtractor $metadata = null,
        ?MediaDerivativeService $derivatives = null,
    ): MediaArchiveService {
        $logger ??= new NullLogger();
        $sniffer ??= new MimeSniffer();
        $metadata ??= new MetadataExtractor($logger, $ffprobeEnabled);
        $fetcher ??= new RemoteMediaFetcher(
            $http ?? new MockHttpClient([]),
            $logger,
            30,
            $maxPromoteBytes,
        );

        $resolver = new MediaArchiveUrlResolver(
            $fetcher,
            $sniffer,
            $logger,
            $promoteExternal,
            $maxPromoteBytes,
        );

        $derivatives ??= self::buildDerivativeService($assetStore, $logger);

        $pipeline = new MediaArchiveIngestPipeline(
            new MediaIngestDecoder(),
            $resolver,
            $sniffer,
            $metadata,
            $assetStore,
            $derivatives,
            new PrincipalService(new PrincipalResolver()),
        );

        return new MediaArchiveService($pipeline, $derivatives);
    }

    /**
     * A {@see MediaDerivativeService} wired to a stub container that
     * materialises the core producers with no-arg-safe construction.
     *
     * Core producers self-register with the static discovery list first,
     * mirroring what {@see \Spora\Core\ContainerDefinitions::all()} does
     * at boot. `PdfToMarkdownProducer` needs a `PdfMarkdownExtractor`, so
     * the stub answers that one explicitly with a Mockery parser rather
     * than trying to reflect a required string-free ctor argument.
     */
    /**
     * A throwaway `auto` store (data_url below 1 MiB, local above) rooted
     * at a fresh temp dir, for the handful of fixtures that need a store
     * but do not otherwise care which one.
     */
    public static function testAssetStore(): AssetStore
    {
        $tmp = sys_get_temp_dir() . '/spora-deriv-store-' . bin2hex(random_bytes(4));
        mkdir($tmp, 0755, recursive: true);
        putenv("SPORA_STORAGE_DIR={$tmp}");
        $_ENV['SPORA_STORAGE_DIR']    = $tmp;
        $_SERVER['SPORA_STORAGE_DIR'] = $tmp;

        return new \Spora\Services\AutoAssetStore(
            new \Spora\Services\DatabaseAssetStore(50 * 1024 * 1024),
            new \Spora\Services\LocalAssetStore(
                new \Spora\Core\Paths(BASE_PATH),
                new \Spora\Core\SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
                50 * 1024 * 1024,
            ),
            1_048_576,
        );
    }

    /**
     * Reuses the caller's {@see AssetStore} so a derivative lands in the
     * same storage mode as the asset it was derived from — which is what
     * production does, and what the `data_url` / `local` assertions in
     * the derivative tests rely on.
     */
    public static function buildDerivativeService(AssetStore $assetStore, ?LoggerInterface $logger = null): MediaDerivativeService
    {
        $container = self::buildProducerContainer();
        MediaDerivativeProducerDiscovery::add(\Spora\Services\MediaArchive\Producers\ImageDerivativeProducer::class);
        MediaDerivativeProducerDiscovery::add(PdfToMarkdownProducer::class);

        return new MediaDerivativeService(
            $assetStore,
            new PrincipalService(new PrincipalResolver()),
            $container,
            $logger ?? new NullLogger(),
        );
    }

    /**
     * Materialises the core producers for {@see MediaDerivativeService},
     * standing in for the real DI container.
     *
     * Anything with a required constructor argument is answered
     * explicitly, because the reflect-and-defaults approach the old
     * converter stub used cannot work for a class like
     * {@see PdfToMarkdownProducer} that needs a live parser and a reader.
     * A producer a test has not configured therefore either gets a
     * no-arg construction or a `RuntimeException` naming the class —
     * never a silent null.
     */
    public static function buildProducerContainer(): ContainerInterface
    {
        $security = new \Spora\Core\SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $reader   = new MediaAssetReader(
            new \Spora\Services\DatabaseAssetStore(50 * 1024 * 1024),
            new \Spora\Services\LocalAssetStore(
                new \Spora\Core\Paths(BASE_PATH),
                $security,
                50 * 1024 * 1024,
            ),
        );

        return new class ($reader) implements ContainerInterface {
            public function __construct(private readonly MediaAssetReader $reader) {}

            public function get(string $id): mixed
            {
                $extractor = new PdfMarkdownExtractor(self::mockPdfParser());

                return match ($id) {
                    PdfMarkdownExtractor::class,
                    \Iamgerwin\PdfToMarkdownParser\PdfToMarkdownParser::class => $extractor,
                    MediaAssetReader::class => $this->reader,
                    PdfToMarkdownProducer::class => new PdfToMarkdownProducer($extractor, $this->reader),
                    default => self::construct($id),
                };
            }

            public function has(string $id): bool
            {
                return class_exists($id);
            }

            /**
             * A parser mock with no `shouldReceive` setup returns null from
             * `parseContent()`, which `trim()` coerces to ''. That is the
             * right default for a test double: extraction yields nothing
             * and the caller sees "no derivative text" rather than a
             * fixture nobody asked for.
             */
            private static function mockPdfParser(): \Iamgerwin\PdfToMarkdownParser\PdfToMarkdownParser
            {
                return Mockery::mock(\Iamgerwin\PdfToMarkdownParser\PdfToMarkdownParser::class);
            }

            private static function construct(string $id): object
            {
                if (!class_exists($id)) {
                    throw new RuntimeException("Not registered: {$id}");
                }
                $reflection = new ReflectionClass($id);
                $constructor = $reflection->getConstructor();
                if ($constructor === null) {
                    return $reflection->newInstance();
                }
                $args = [];
                foreach ($constructor->getParameters() as $param) {
                    if (!$param->isDefaultValueAvailable()) {
                        throw new RuntimeException(
                            "Cannot auto-construct {$id}: parameter {$param->getName()} has no default value.",
                        );
                    }
                    $args[] = $param->getDefaultValue();
                }
                return $reflection->newInstanceArgs($args);
            }
        };
    }

    public static function buildAuth(): AuthService
    {
        return new class extends AuthService {
            public function __construct()
            { /* no-op */
            }
            public function currentUserId(): int
            {
                return 1;
            }
            public function isAdmin(): bool
            {
                return true;
            }
        };
    }
}
