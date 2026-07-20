<?php

/*
 * Cross-file test helpers used by multiple Feature test files.
 *
 * Pest's parallel runner loads each test file in isolation, so functions
 * defined inside test files are not visible to tests in other files when
 * they run in different workers. Functions that are referenced from a
 * test file OTHER than the one that defines them live here and are
 * pre-loaded via composer.json's autoload-dev.files so they are global
 * before any worker starts.
 */

declare(strict_types=1);

if (!function_exists('makeAdmin')) {
    function makeAdmin(Spora\Auth\AuthService $authService, int $userId): void
    {
        $authService->grantRole($userId, Delight\Auth\Role::ADMIN);
    }
}

if (!function_exists('createTestConfig')) {
    function createTestConfig(
        string $name,
        string $driverClass,
        array $settings,
        bool $isDefault = false,
        ?int $userId = null,
        ?Spora\Services\LLMConfigService $llmConfigService = null,
    ): Spora\Models\LLMDriverConfiguration {
        if ($llmConfigService === null) {
            $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
            $security = new Spora\Core\SecurityManager($key);
            $llmConfigService = new Spora\Services\LLMConfigService($security, [
                Spora\Drivers\OpenAICompatibleDriver::class,
                Spora\Drivers\AnthropicCompatibleDriver::class,
            ]);
        }

        $config = new Spora\Models\LLMDriverConfiguration();
        $config->user_id = $userId ?? ($_SESSION[Delight\Auth\Auth::SESSION_FIELD_USER_ID] ?? 1);
        $config->name = $name;
        $config->driver_class = $driverClass;
        $config->settings = json_encode($llmConfigService->encodeSettings($driverClass, $settings));
        $config->is_default = $isDefault;
        $config->save();

        return $config;
    }
}

if (!function_exists('makeMediaArchiveService')) {
    /**
     * Build a MediaArchiveService with optional injected dependencies.
     *
     * Self-contained: does NOT depend on tests/Feature/MediaArchive/
     * MediaArchiveServiceTest.php being loaded, so it works under Pest's
     * parallel runner when only this test file is loaded into a worker.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    function makeMediaArchiveService(array $overrides = []): array
    {
        $tmp = sys_get_temp_dir() . '/spora-media-archive-' . bin2hex(random_bytes(4));
        mkdir($tmp, 0755, true);
        putenv("SPORA_STORAGE_DIR={$tmp}");
        $_ENV['SPORA_STORAGE_DIR']    = $tmp;
        $_SERVER['SPORA_STORAGE_DIR'] = $tmp;

        $paths     = new Spora\Core\Paths(BASE_PATH);
        $security  = new Spora\Core\SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $sniffer   = new Spora\Services\MediaArchive\MimeSniffer();
        $dataUrl   = new Spora\Services\DataUrlAssetStore(50 * 1024 * 1024);
        $local     = new Spora\Services\LocalAssetStore($paths, $security, 50 * 1024 * 1024);
        $assetStore = new Spora\Services\AutoAssetStore($dataUrl, $local, 1_048_576);
        $metadata  = new Spora\Services\MediaArchive\MetadataExtractor(new Psr\Log\NullLogger(), false);
        $logger    = new Psr\Log\NullLogger();

        $restore = static function () use ($tmp): void {
            putenv('SPORA_STORAGE_DIR');
            unset($_ENV['SPORA_STORAGE_DIR'], $_SERVER['SPORA_STORAGE_DIR']);
            if (is_dir($tmp)) {
                $iter = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST,
                );
                foreach ($iter as $f) {
                    $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
                }
                @rmdir($tmp);
            }
        };

        $ctx = [
            'assetStore' => $assetStore,
            'sniffer'    => $sniffer,
            'metadata'   => $metadata,
            'logger'     => $logger,
            'tmp'        => $tmp,
            'restore'    => $restore,
        ];

        $fetcher = $overrides['fetcher'] ?? new Spora\Services\MediaArchive\RemoteMediaFetcher(
            new Symfony\Component\HttpClient\MockHttpClient([]),
            $ctx['logger'],
            30,
            100 * 1024 * 1024,
        );

        $resolver = new Spora\Services\MediaArchive\MediaArchiveUrlResolver(
            $fetcher,
            $ctx['sniffer'],
            $ctx['logger'],
            (bool) ($overrides['promoteExternal'] ?? true),
            (int) ($overrides['maxPromoteBytes'] ?? 100 * 1024 * 1024),
        );

        $service = new Spora\Services\MediaArchive\MediaArchiveService(
            $ctx['assetStore'],
            $resolver,
            $ctx['sniffer'],
            $ctx['metadata'],
            Tests\Support\MediaArchiveTestSupport::buildConverterRegistry(),
            new Spora\Services\MediaArchive\MediaIngestDecoder(),
        );

        return [
            'service'    => $service,
            'assetStore' => $ctx['assetStore'],
            'sniffer'    => $ctx['sniffer'],
            'metadata'   => $ctx['metadata'],
            'logger'     => $ctx['logger'],
            'fetcher'    => $fetcher,
            'tmp'        => $ctx['tmp'],
            'restore'    => $ctx['restore'],
        ];
    }
}
