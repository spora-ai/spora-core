<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Spora\Core\SecurityManager;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Http\MediaAllowedTypesController;
use Spora\Services\LLMConfigService;
use Spora\Services\MediaArchive\MediaAllowedTypesService;
use Spora\Services\MediaArchive\MediaConverterDiscovery;
use Symfony\Component\HttpFoundation\Request;
use Tests\Support\MediaArchiveTestSupport;

afterEach(function (): void {
    MediaConverterDiscovery::reset();
});

/**
 * Plan §12 B2b — MediaAllowedTypesController endpoint tests.
 */
test('returns text + converter types without an agent_id query param', function (): void {
    $controller = buildAllowedEndpoint();
    $req = Request::create('/api/v1/media/allowed-types', 'GET');
    $resp = $controller->index($req);
    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode($resp->getContent(), true);
    expect($body['data']['mime_types'])->toContain('text/plain');
    expect($body['data']['mime_types'])->toContain('application/pdf');
    foreach ($body['data']['mime_types'] as $m) {
        expect(str_starts_with($m, 'image/'))->toBeFalse();
    }
});

test('with ?agent_id adds image types when the agent\'s LLM is vision-capable', function (): void {
    $authService = bootAuthLayer();
    $userId = bootAuth($authService);
    seedAllowedLlmConfig(1, $userId, OpenAICompatibleDriver::class, 'gpt-4o');
    seedAllowedAgent(42, $userId);
    $controller = buildAllowedEndpoint();
    $req = Request::create('/api/v1/media/allowed-types?agent_id=42', 'GET');
    $resp = $controller->index($req);
    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode($resp->getContent(), true);
    expect($body['data']['mime_types'])->toContain('image/png');
    expect($body['data']['mime_types'])->toContain('image/jpeg');
});

test('with ?agent_id does not add image types when the agent\'s LLM is text-only', function (): void {
    $authService = bootAuthLayer();
    $userId = bootAuth($authService);
    seedAllowedLlmConfig(1, $userId, OpenAICompatibleDriver::class, 'gpt-3.5-turbo');
    seedAllowedAgent(42, $userId);
    $controller = buildAllowedEndpoint();
    $req = Request::create('/api/v1/media/allowed-types?agent_id=42', 'GET');
    $resp = $controller->index($req);
    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode($resp->getContent(), true);
    foreach ($body['data']['mime_types'] as $m) {
        expect(str_starts_with($m, 'image/'))->toBeFalse();
    }
});

function buildAllowedEndpoint(): MediaAllowedTypesController
{
    $registry = MediaArchiveTestSupport::buildConverterRegistry();
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $llmService = new LLMConfigService($security, [OpenAICompatibleDriver::class]);
    $factory = new DriverFactory(new \Psr\Log\NullLogger(), $llmService, 60);
    $allowed = new MediaAllowedTypesService($registry, $factory);
    return new MediaAllowedTypesController($allowed);
}

function seedAllowedLlmConfig(int $id, int $userId, string $driverClass, string $model): void
{
    if (\Spora\Models\LLMDriverConfiguration::query()->find($id) !== null) {
        return;
    }
    \Spora\Models\LLMDriverConfiguration::query()->insert([
        'id' => $id,
        'user_id' => $userId,
        'name' => "cfg-{$id}",
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

function seedAllowedAgent(int $id, int $userId): void
{
    if (\Spora\Models\Agent::query()->find($id) !== null) {
        return;
    }
    \Spora\Models\Agent::query()->insert([
        'id' => $id,
        'user_id' => $userId,
        'name' => "agent-{$id}",
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
