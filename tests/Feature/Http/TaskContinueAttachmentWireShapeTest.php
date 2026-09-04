<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Psr\Log\NullLogger;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Http\ContinueTaskDispatcher;
use Spora\Http\DecisionsRequestValidator;
use Spora\Http\TaskController;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\MediaAsset;
use Spora\Services\AutoAssetStore;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\LLMConfigService;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\MediaConverterDiscovery;
use Spora\Services\MediaArchive\MediaIngestRequest;
use Spora\Services\MediaArchive\TaskMediaCapabilityService;
use Symfony\Component\HttpFoundation\Request;
use Tests\Support\MediaArchiveTestSupport;
use Tests\Unit\Http\StubTaskService;

/**
 * Plan §4 — pins that `POST /api/v1/tasks/{taskId}/continue` accepts
 * `media_ids` on a vision-capable agent and returns 200.
 *
 * The DB-side `role=attachment` row + ownership check are exercised by
 * the orchestrator suite; this test pins the controller + capability-
 * pre-flight layer. The wire shape itself is asserted in
 * `tests/Unit/Services/TaskHistorySerializerTest.php`.
 */
afterEach(function (): void {
    MediaConverterDiscovery::reset();
});

test('continue accepts media_ids on a vision-capable agent and returns 200', function (): void {
    $controller = buildWireShapeController();
    $asset = ingestWireShapeImageAsset();

    $request = Request::create(
        '/api/v1/tasks/1/continue',
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        json_encode(['prompt' => 'describe this', 'media_ids' => [$asset->id]]),
    );
    $request->attributes->set('taskId', 1);

    $resp = $controller->continue($request);

    expect($resp->getStatusCode())->toBe(200);
});

function buildWireShapeController(): TaskController
{
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $llmService = new LLMConfigService($security, [OpenAICompatibleDriver::class]);
    $factory = new \Spora\Drivers\DriverFactory(new NullLogger(), $llmService, 60);

    $authService = bootAuthLayer();
    $userId = bootAuth($authService);

    if (LLMDriverConfiguration::query()->find(1) === null) {
        LLMDriverConfiguration::query()->insert([
            'id' => 1,
            'principal_id' => createUserPrincipalPublic($userId),
            'name' => 'wire-shape-test',
            'driver_class' => OpenAICompatibleDriver::class,
            'settings' => json_encode([
                'api_key' => '',
                'model' => 'gpt-4o-mini',
                'base_url' => 'https://example.invalid/v1',
                'timeout' => '60',
            ]),
            'is_default' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
    if (Agent::query()->find(10) === null) {
        Agent::query()->insert([
            'id' => 10,
            'principal_id' => createUserPrincipalPublic($userId),
            'name' => 'agent-10',
            'description' => '',
            'system_prompt' => '',
            'llm_driver_config_id' => 1,
            'max_steps' => 5,
            'is_active' => 1,
            'allow_followup' => 1,
            'retry_after_minutes' => 0,
            'max_retries' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    $stub = new StubTaskService();
    $mediaCapability = new TaskMediaCapabilityService($factory);
    return new TaskController(
        $authService,
        $stub,
        $mediaCapability,
        new ContinueTaskDispatcher($stub, $mediaCapability),
        new DecisionsRequestValidator($stub),
    );
}

function ingestWireShapeImageAsset(): MediaAsset
{
    $tmp = sys_get_temp_dir() . '/spora-wireshape-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;
    $paths    = new Paths(BASE_PATH);
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $database = new DatabaseAssetStore(50 * 1024 * 1024);
    $local    = new LocalAssetStore($paths, $security, 50 * 1024 * 1024);
    $assetStore = new AutoAssetStore($database, $local, 1_048_576);
    $service = MediaArchiveTestSupport::buildService($assetStore);
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=',
        strict: true,
    );
    return $service->ingest(new MediaIngestRequest(
        bytes: $png,
        mime: 'image/png',
        filename: 'wireshape.png',
        userId: 1,
        uploadSource: 'upload',
    ));
}
