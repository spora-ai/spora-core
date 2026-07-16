<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Psr\Log\NullLogger;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Http\TaskController;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\MediaAsset;
use Spora\Models\Task;
use Spora\Services\AutoAssetStore;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\LLMConfigService;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaConverterDiscovery;
use Spora\Services\MediaArchive\MediaIngestRequest;
use Spora\Services\MediaArchive\TaskMediaCapabilityService;
use Symfony\Component\HttpFoundation\Request;
use Tests\Support\MediaArchiveTestSupport;
use Tests\Unit\Http\StubTaskService;

/**
 * B2 (review): Image-capability pre-flight in TaskController.
 *
 * Plan §8.3 / §12 require a 400 when media_ids contains an image and the
 * agent's LLM does not support image input. These tests pin the
 * behaviour for `store()` and `continue()`.
 */
afterEach(function (): void {
    MediaConverterDiscovery::reset();
});

test('store returns 400 when an image is attached to a non-vision agent', function (): void {
    [$controller] = buildCapabilityController('gpt-3.5-turbo', OpenAICompatibleDriver::class);
    $asset = ingestImageAsset('agent-gpt35');
    $request = jsonRequest('POST', '/api/v1/tasks', [
        'prompt'    => 'describe this',
        'agent_id'  => 10,
        'media_ids' => [$asset->id],
    ]);
    $resp = $controller->store($request);
    expect($resp->getStatusCode())->toBe(400);
    $body = json_decode($resp->getContent(), true);
    expect($body['error']['code'])->toBe('MEDIA_CAPABILITY_MISMATCH');
});

test('store returns 201 when an image is attached to a vision-capable agent', function (): void {
    [$controller] = buildCapabilityController('claude-3-5-sonnet-20241022', AnthropicCompatibleDriver::class);
    $asset = ingestImageAsset('agent-vision');
    $request = jsonRequest('POST', '/api/v1/tasks', [
        'prompt'    => 'describe this',
        'agent_id'  => 10,
        'media_ids' => [$asset->id],
    ]);
    $resp = $controller->store($request);
    expect($resp->getStatusCode())->toBe(201);
});

test('store returns 201 when a text attachment is used with a non-vision agent', function (): void {
    [$controller] = buildCapabilityController('gpt-3.5-turbo', OpenAICompatibleDriver::class);
    $asset = ingestTextAsset('agent-text');
    $request = jsonRequest('POST', '/api/v1/tasks', [
        'prompt'    => 'summarise this',
        'agent_id'  => 10,
        'media_ids' => [$asset->id],
    ]);
    $resp = $controller->store($request);
    expect($resp->getStatusCode())->toBe(201);
});

test('store returns 201 when no media_ids are attached even on a non-vision agent', function (): void {
    [$controller] = buildCapabilityController('gpt-3.5-turbo', OpenAICompatibleDriver::class);
    $request = jsonRequest('POST', '/api/v1/tasks', [
        'prompt'   => 'hello',
        'agent_id' => 10,
    ]);
    $resp = $controller->store($request);
    expect($resp->getStatusCode())->toBe(201);
});

test('continue returns 400 when an image is attached to a non-vision agent', function (): void {
    [$controller, $stub] = buildCapabilityController('gpt-3.5-turbo', OpenAICompatibleDriver::class);
    $asset = ingestImageAsset('agent-continue');
    $request = Request::create(
        '/api/v1/tasks/1/continue',
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        json_encode(['prompt' => 'follow-up', 'media_ids' => [$asset->id]]),
    );
    $request->attributes->set('taskId', 1);
    $resp = $controller->continue($request);
    expect($resp->getStatusCode())->toBe(400);
    $body = json_decode($resp->getContent(), true);
    expect($body['error']['code'])->toBe('MEDIA_CAPABILITY_MISMATCH');
});

/**
 * @return array{0: TaskController, 1: StubTaskService}
 */
function buildCapabilityController(string $model, string $driverClass): array
{
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $llmService = new LLMConfigService($security, [$driverClass]);
    $factory = new DriverFactory(new NullLogger(), $llmService, 60);

    $authService = bootAuthLayer();
    $userId = bootAuth($authService);

    if (LLMDriverConfiguration::query()->find(1) === null) {
        LLMDriverConfiguration::query()->insert([
            'id' => 1,
            'user_id' => $userId,
            'name' => 'capability-test',
            'driver_class' => $driverClass,
            'settings' => json_encode([
                'api_key' => '',
                'model' => $model,
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
            'user_id' => $userId,
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
    if (Task::query()->find(1) === null) {
        Task::query()->insert([
            'id' => 1,
            'user_id' => $userId,
            'agent_id' => 10,
            'status' => 'COMPLETED',
            'user_prompt' => 'previous',
            'final_response' => null,
            'step_count' => 0,
            'max_steps' => 5,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    $stub = new StubTaskService();
    return [new TaskController($authService, $stub, new TaskMediaCapabilityService($factory)), $stub];
}

/**
 * @return array{0: MediaArchiveService, 1: string, 2: string}
 */
function buildCapabilityAssetStore(): array
{
    $tmp = sys_get_temp_dir() . '/spora-capability-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;
    $paths    = new Paths(BASE_PATH);
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $database = new DatabaseAssetStore(50 * 1024 * 1024);
    $local    = new LocalAssetStore($paths, $security, 50 * 1024 * 1024);
    $assetStore = new AutoAssetStore($database, $local, 1_048_576);
    return [MediaArchiveTestSupport::buildService($assetStore), $tmp, $tmp];
}

function ingestImageAsset(string $name): MediaAsset
{
    [$service] = buildCapabilityAssetStore();
    // Smallest legal PNG (1x1 transparent pixel)
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=',
        strict: true,
    );
    return $service->ingest(new MediaIngestRequest(
        bytes: $png,
        mime: 'image/png',
        filename: "{$name}.png",
        userId: 1,
        uploadSource: 'upload',
    ));
}

function ingestTextAsset(string $name): MediaAsset
{
    [$service] = buildCapabilityAssetStore();
    return $service->ingest(new MediaIngestRequest(
        bytes: 'hello world',
        mime: 'text/plain',
        filename: "{$name}.txt",
        userId: 1,
        uploadSource: 'upload',
    ));
}
