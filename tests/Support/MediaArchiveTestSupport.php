<?php

declare(strict_types=1);

namespace Tests\Support;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Spora\Auth\AuthService;
use Spora\Services\AssetStore;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaArchiveUrlResolver;
use Spora\Services\MediaArchive\MediaConverterDiscovery;
use Spora\Services\MediaArchive\MediaConverterRegistry;
use Spora\Services\MediaArchive\MetadataExtractor;
use Spora\Services\MediaArchive\MimeSniffer;
use Spora\Services\MediaArchive\RemoteMediaFetcher;
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

        return new MediaArchiveService(
            $assetStore,
            $resolver,
            $sniffer,
            $metadata,
            self::buildConverterRegistry(),
            $logger,
        );
    }

    public static function buildConverterRegistry(): MediaConverterRegistry
    {
        // A minimal PSR-11 stub that materialises any class the
        // discovery list points at via `new $id()`. Tests that want
        // to exercise specific converters add them via MediaConverterDiscovery
        // BEFORE calling this helper. Optional constructor parameters
        // are left at their declared default; required parameters are
        // intentionally fatal — tests must use Mockery for those.
        $stub = new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed
            {
                if (!class_exists($id)) {
                    throw new \RuntimeException("Not registered: {$id}");
                }
                $reflection = new \ReflectionClass($id);
                $constructor = $reflection->getConstructor();
                if ($constructor === null) {
                    return $reflection->newInstance();
                }
                $args = [];
                foreach ($constructor->getParameters() as $param) {
                    if ($param->isDefaultValueAvailable()) {
                        $args[] = $param->getDefaultValue();
                        continue;
                    }
                    if (\Spora\Services\MediaArchive\Converters\PdfToMarkdownConverter::class === $id) {
                        $args[] = \Mockery::mock(\Iamgerwin\PdfToMarkdownParser\PdfToMarkdownParser::class);
                        continue;
                    }
                    throw new \RuntimeException("Cannot auto-construct {$id}: parameter {$param->getName()} has no default value.");
                }
                return $reflection->newInstanceArgs($args);
            }
            public function has(string $id): bool { return class_exists($id); }
        };
        // Core converters are available in the application container; mirror
        // that registration in the lightweight test container.
        MediaConverterDiscovery::add(\Spora\Services\MediaArchive\Converters\PdfToMarkdownConverter::class);
        MediaConverterDiscovery::add(\Spora\Services\MediaArchive\Converters\PlainTextPassthroughConverter::class);

        return new MediaConverterRegistry($stub);
    }

    public static function buildAuth(): AuthService
    {
        // Tests run without a real auth session; the controller's
        // canEdit() is only consulted in PATCH/refresh flows.
        return new class extends AuthService {
            public function __construct() { /* no-op */ }
            public function currentUserId(): int { return 1; }
            public function isAdmin(): bool { return true; }
        };
    }
}
