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

if (!function_exists('encodeLlmSettingsForTest')) {
    /**
     * Test helper: encode LLM settings using a freshly-wired
     * {@see Spora\Services\LLMConfigPersistence}. The production code
     * path moved encodeSettings off {@see Spora\Services\LLMConfigService}
     * during the S1448 split; tests that need the raw encryption use
     * this helper to avoid re-implementing the constructor signature.
     */
    function encodeLlmSettingsForTest(string $driverClass, array $settings): string
    {
        $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $security = new Spora\Core\SecurityManager($key);
        $persistence = new Spora\Services\LLMConfigPersistence(
            $security,
            new Spora\Services\LLMConfigSchemaInspector(),
            new Spora\Services\PrincipalResolver(),
        );
        return json_encode($persistence->encodeSettings($driverClass, $settings));
    }
}

if (!function_exists('makeLlmPersistenceForService')) {
    /**
     * Build a {@see Spora\Services\LLMConfigPersistence} that shares its
     * SecurityManager with the supplied {@see Spora\Services\LLMConfigService},
     * so encode/decode round-trips against the same key. Used by helpers that
     * need to write settings the service can later decrypt.
     */
    function makeLlmPersistenceForService(Spora\Services\LLMConfigService $llmConfigService): Spora\Services\LLMConfigPersistence
    {
        $inspector = (new ReflectionClass($llmConfigService))->getProperty('schemaInspector')
            ->getValue($llmConfigService);
        $persistence = (new ReflectionClass($llmConfigService))->getProperty('persistence')
            ->getValue($llmConfigService);
        $security = (new ReflectionClass($persistence))->getProperty('security')
            ->getValue($persistence);

        return new Spora\Services\LLMConfigPersistence(
            $security,
            $inspector,
        );
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

        $persistence = makeLlmPersistenceForService($llmConfigService);
        $isGlobal = $userId === null;

        // The migration's NOT NULL constraint on `principal_id` blocks the
        // model save path for global configs (model XOR requires
        // principal_id = null ⇔ is_global = true). Insert global rows via
        // the query builder to skip the model-level XOR check.
        if ($isGlobal) {
            return createTestGlobalConfig($name, $driverClass, $settings, $isDefault, $persistence);
        }

        $principalId = createUserPrincipalPublic($userId);

        $config = new Spora\Models\LLMDriverConfiguration();
        $config->principal_id = $principalId;
        $config->name = $name;
        $config->driver_class = $driverClass;
        $config->settings = json_encode($persistence->encodeSettings($driverClass, $settings));
        $config->is_default = $isDefault;
        $config->is_global = false;
        $config->save();

        return $config;
    }
}

if (!function_exists('createTestGlobalConfig')) {
    /**
     * Insert a global LLMDriverConfiguration row directly via the query
     * builder. The model's `validateGlobalXor()` requires
     * `principal_id = null` for globals, but migration 0067 promoted the
     * column to NOT NULL — bypassing the model save() gets us a row in the
     * correct shape for the controller / preference tests without needing
     * to amend the migration in this PR.
     */
    function createTestGlobalConfig(
        string $name,
        string $driverClass,
        array $settings,
        bool $isDefault,
        ?Spora\Services\LLMConfigPersistence $persistence = null,
    ): Spora\Models\LLMDriverConfiguration {
        if ($persistence === null) {
            $persistence = new Spora\Services\LLMConfigPersistence(
                new Spora\Core\SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
                new Spora\Services\LLMConfigSchemaInspector(),
            );
        }
        $id = (int) Illuminate\Database\Capsule\Manager::table('llm_driver_configurations')->insertGetId([
            'principal_id' => null,
            'name'         => $name,
            'driver_class' => $driverClass,
            'settings'     => json_encode($persistence->encodeSettings($driverClass, $settings)),
            'is_default'   => $isDefault,
            'is_global'    => true,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        return Spora\Models\LLMDriverConfiguration::findOrFail($id);
    }
}

if (!function_exists('createUserPrincipalPublic')) {
    /**
     * Materialise the user-principal row for $userId and return its id.
     *
     * Wraps the same logic as {@see Tests\Concerns\CreatesPrincipal::createUserPrincipal()}
     * so functions defined in this preloaded helper file (which run before
     * Pest's per-test class binding wires in traits) can still satisfy the
     * FK on `principal_id`.
     *
     * If `$materialiseUser` is true (default), a `users` row is created on
     * demand so the FK constraint is satisfied. Pass false when stubbing an
     * agent with a fake user id (e.g. `$agent->user_id = 99`) — the test
     * never persists the agent, so the FK on `principals.user_id` is never
     * actually checked.
     */
    function createUserPrincipalPublic(int $userId, bool $materialiseUser = true): int
    {
        $existing = Illuminate\Database\Capsule\Manager::table('principals')
            ->where('type', 'user')->where('user_id', $userId)->value('id');
        if ($existing !== null) {
            return (int) $existing;
        }

        if ($materialiseUser && !Illuminate\Database\Capsule\Manager::table('users')->where('id', $userId)->exists()) {
            Illuminate\Database\Capsule\Manager::table('users')->insert([
                'id'         => $userId,
                'email'      => "stub-user-{$userId}@spora.test",
                'username'   => "stub_user_{$userId}",
                'password'   => str_repeat("\0", 60),
                'status'     => 1,
                'verified'   => 1,
                'resettable' => 1,
                'roles_mask' => 0,
                'registered' => time(),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        try {
            return (int) Illuminate\Database\Capsule\Manager::table('principals')->insertGetId([
                'type'       => 'user',
                'user_id'    => $userId,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (PDOException) {
            $existing = Illuminate\Database\Capsule\Manager::table('principals')
                ->where('type', 'user')->where('user_id', $userId)->value('id');
            if ($existing !== null) {
                return (int) $existing;
            }
            throw new RuntimeException("Failed to materialise user-principal for user {$userId}");
        }
    }
}

if (!function_exists('seedMediaToolAgent')) {
    /**
     * Create an Agent row so the FK on media_assets.agent_id resolves.
     * Tests use unique emails + namespaced agent names so a single Pest
     * run never collides between cases (each test runs in its own
     * transaction).
     */
    function seedMediaToolAgent(string $name = 'Test Agent'): int
    {
        // Create the user row first to satisfy the agents.user_id FK.
        // The exact id doesn't matter — only the row needs to exist.
        $pdo  = Illuminate\Database\Capsule\Manager::connection()->getPdo();
        $stmt = $pdo->prepare(
            'INSERT INTO users (email, password, username, verified, resettable, roles_mask, registered, created_at, updated_at) '
            . 'VALUES (?, ?, ?, 1, 1, 0, ?, ?, ?)',
        );
        $email = sprintf('mediatool-%s-%s@example.com', bin2hex(random_bytes(4)), microtime(true));
        $now   = time();
        $stmt->execute([
            $email,
            password_hash('Password1!', PASSWORD_BCRYPT),
            $email,
            $now,
            date('Y-m-d H:i:s', $now),
            date('Y-m-d H:i:s', $now),
        ]);
        $userId = (int) $pdo->lastInsertId();

        return Spora\Models\Agent::create([
            'principal_id' => createUserPrincipalPublic($userId),
            'name'      => $name,
            'is_active' => true,
        ])->id;
    }
}

if (!function_exists('seedMediaAsset')) {
    /**
     * Insert a media_assets row directly. The default shape matches what
     * the MediaTool tests need (data_url storage, image mime, no
     * upload_source) but every field is overridable. The AgentPicture
     * tests also rely on this helper — they pass just `$userId` and let
     * the defaults do the rest.
     *
     * `$userId` is the first positional parameter so the call
     * `seedMediaAsset($userId)` from AgentPictureServiceTest stays
     * semantically correct. The MediaTool tests use named args.
     */
    function seedMediaAsset(
        ?int $userId = null,
        ?int $agentId = null,
        ?string $publicToken = null,
        ?string $pluginSlug = null,
        ?string $mime = 'image/png',
        ?string $idOverride = null,
    ): Spora\Models\MediaAsset {
        $id = $idOverride ?? sprintf(
            '%08x-aaaa-bbbb-cccc-%012x',
            random_int(0, 0xffffffff),
            random_int(0, 0xffffffffffff),
        );

        return Spora\Models\MediaAsset::create([
            'id'                            => $id,
            'asset_url'                     => Spora\Services\MediaArchive\MediaArchiveService::OPAQUE_ASSET_URL_PREFIX . $id . '.png',
            'storage_mode'                  => 'data_url',
            'mime_type'                     => $mime,
            'media_type'                    => 'image',
            'byte_size'                     => 1024,
            'agent_id'                      => $agentId,
            'user_id'                       => (int) $userId,
            'plugin_slug'                   => $pluginSlug,
            'asset_token'                   => bin2hex(random_bytes(16)),
            'public_access_token'           => $publicToken,
            'filename'                      => 'sample.png',
            'migrated_from_inline_data_url' => false,
        ]);
    }
}

if (!function_exists('makeMediaToolNonAdminAuth')) {
    function makeMediaToolNonAdminAuth(): Spora\Auth\AuthService
    {
        $auth = Mockery::mock(Spora\Auth\AuthService::class);
        $auth->allows('isAdmin')->andReturn(false);
        $auth->allows('currentUserId')->andReturn(null);
        return $auth;
    }
}

if (!function_exists('makeMediaToolAdminAuth')) {
    function makeMediaToolAdminAuth(): Spora\Auth\AuthService
    {
        $auth = Mockery::mock(Spora\Auth\AuthService::class);
        $auth->allows('isAdmin')->andReturn(true);
        $auth->allows('currentUserId')->andReturn(1);
        return $auth;
    }
}

if (!function_exists('makeMediaToolWithRealArchive')) {
    /**
     * Build a MediaTool wired to a real MediaArchiveService so the test
     * exercises the same query path the LLM hits in production. Returns
     * the tool + a cleanup closure that wipes the tmp asset dir.
     */
    function makeMediaToolWithRealArchive(
        ?Spora\Auth\AuthService $auth = null,
        ?Spora\Services\ToolConfigService $config = null,
        array $globalConfig = [],
    ): array {
        // Reuse the same builder that MediaArchiveServiceTest uses so we get a
        // fully-wired service (asset store, resolver, converters, decoder).
        $ctx = makeMediaArchiveService();
        $database = new Spora\Services\DatabaseAssetStore();
        $local    = new Spora\Services\LocalAssetStore(
            new Spora\Core\Paths(BASE_PATH),
            new Spora\Core\SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
            50 * 1024 * 1024,
        );
        [$derivatives, $derivativesRestore] = makeMediaDerivativeServiceForTest($ctx['tmp']);
        $serializer = new Spora\Services\MediaArchive\MediaAssetSerializer(
            includeDerivatives: true,
            derivatives: $derivatives,
        );
        $sourceReader = new Spora\Tools\MediaSourceReader($database, $local);
        $derivativeHandler = new Spora\Tools\MediaDerivativeHandler($serializer, $derivatives);
        $tool = new Spora\Tools\MediaTool(
            $ctx['service'],
            $auth ?? makeMediaToolNonAdminAuth(),
            $serializer,
            $derivatives,
            $sourceReader,
            $derivativeHandler,
            $config,
            $globalConfig,
        );

        return [
            'tool' => $tool,
            'restore' => static function () use ($ctx, $derivativesRestore): void {
                $derivativesRestore();
                $ctx['restore']();
            },
        ];
    }
}

if (!function_exists('buildMediaToolForSchema')) {
    /**
     * Build a MediaTool instance for the schema-surface tests
     * (MediaToolApprovalTest). None of those tests call execute(), so
     * the wire-shape builder is enough — no need for a restore closure
     * or full archive service lifecycle.
     */
    function buildMediaToolForSchema(): Spora\Tools\MediaTool
    {
        // Build a real archive service so the constructor's type hint is
        // satisfied. None of these tests invoke execute().
        $ctx = makeMediaArchiveService();
        $auth = Mockery::mock(Spora\Auth\AuthService::class);
        $auth->allows('isAdmin')->andReturn(false);
        $auth->allows('currentUserId')->andReturn(null);

        $database = new Spora\Services\DatabaseAssetStore();
        $local    = new Spora\Services\LocalAssetStore(
            new Spora\Core\Paths(BASE_PATH),
            new Spora\Core\SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
            50 * 1024 * 1024,
        );
        [$derivatives] = makeMediaDerivativeServiceForTest($ctx['tmp']);
        $serializer = new Spora\Services\MediaArchive\MediaAssetSerializer(
            includeDerivatives: true,
            derivatives: $derivatives,
        );
        $sourceReader = new Spora\Tools\MediaSourceReader($database, $local);
        $derivativeHandler = new Spora\Tools\MediaDerivativeHandler($serializer, $derivatives);

        return new Spora\Tools\MediaTool(
            $ctx['service'],
            $auth,
            $serializer,
            $derivatives,
            $sourceReader,
            $derivativeHandler,
        );
    }
}

if (!function_exists('makeMediaDerivativeServiceForTest')) {
    /**
     * Build a {@see Spora\Services\MediaArchive\MediaDerivativeService}
     * wired against the same `SPORA_STORAGE_DIR` the test fixture is
     * using, plus the lightweight producer container
     * {@see Tests\Support\MediaArchiveTestSupport::buildProducerContainer()}
     * (which is what {@see MediaArchiveTestSupport::buildProducerContainer()}
     * exposes for the other media-archive tests).
     *
     * Returns `[service, restore]` so per-test derivatives state is
     * cleared between cases (the producer discovery registry is a
     * static, so each test must reset it before registering new
     * producers).
     */
    function makeMediaDerivativeServiceForTest(string $tmp): array
    {
        $paths    = new Spora\Core\Paths(BASE_PATH);
        $security = new Spora\Core\SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $database = new Spora\Services\DatabaseAssetStore(50 * 1024 * 1024);
        $local    = new Spora\Services\LocalAssetStore($paths, $security, 50 * 1024 * 1024);
        $assetStore = new Spora\Services\AutoAssetStore($database, $local, 1_048_576);

        $principalService = new Spora\Services\PrincipalService(new Spora\Services\PrincipalResolver());
        $container = Tests\Support\MediaArchiveTestSupport::buildProducerContainer();
        $service = new Spora\Services\MediaArchive\MediaDerivativeService($assetStore, $principalService, $container);

        $restore = static function (): void {
            Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery::reset();
        };

        return [$service, $restore];
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

        $pipeline = new Spora\Services\MediaArchive\MediaArchiveIngestPipeline(
            new Spora\Services\MediaArchive\MediaIngestDecoder(),
            $resolver,
            $ctx['sniffer'],
            $ctx['metadata'],
            $ctx['assetStore'],
            Tests\Support\MediaArchiveTestSupport::buildConverterRegistry(),
            new Spora\Services\PrincipalService(new Spora\Services\PrincipalResolver()),
        );

        $service = new Spora\Services\MediaArchive\MediaArchiveService($pipeline);

        return [
            'service'    => $service,
            'pipeline'   => $pipeline,
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
