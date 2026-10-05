<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Spora\Core\SecurityManager;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Http\MediaAllowedTypesController;
use Spora\Services\LLMConfigService;
use Spora\Services\MediaArchive\MediaAllowedTypesService;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;
use Symfony\Component\HttpFoundation\Request;
use Tests\Support\MediaArchiveTestSupport;

afterEach(function (): void {
    MediaDerivativeProducerDiscovery::reset();
});

/**
 * Plan §12 B2b — MediaAllowedTypesController endpoint tests.
 */
test('returns text + producer types without an agent_id query param', function (): void {
    $controller = buildAllowedEndpoint();
    $req = Request::create('/api/v1/media/allowed-types', 'GET');
    $resp = $controller->index($req);
    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode($resp->getContent(), true);
    expect($body['data']['mime_types'])->toContain('text/plain');
    expect($body['data']['mime_types'])->toContain('application/pdf');
    // No agent_id → direct operator upload (Media Archive plugin's
    // Upload dialog) — images are allowed by default. The
    // vision-LLM gate only kicks in when ?agent_id= is supplied.
    expect($body['data']['mime_types'])->toContain('image/jpeg');
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
    // The PDF producer is what puts `application/pdf` in the allowlist,
    // so it has to be registered for this endpoint to advertise it.
    MediaDerivativeProducerDiscovery::add(\Spora\Services\MediaArchive\Producers\PdfToMarkdownProducer::class);
    $derivatives = MediaArchiveTestSupport::buildDerivativeService(
        new \Spora\Services\AutoAssetStore(
            new \Spora\Services\DatabaseAssetStore(50 * 1024 * 1024),
            new \Spora\Services\LocalAssetStore(
                new \Spora\Core\Paths(BASE_PATH),
                new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
                50 * 1024 * 1024,
            ),
            1_048_576,
        ),
    );
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $llmService = new LLMConfigService($security, [OpenAICompatibleDriver::class]);
    $factory = new DriverFactory(new \Psr\Log\NullLogger(), $llmService, 60);
    $allowed = new MediaAllowedTypesService($derivatives, $factory);
    return new MediaAllowedTypesController($allowed);
}

function seedAllowedLlmConfig(int $id, int $userId, string $driverClass, string $model): void
{
    if (\Spora\Models\LLMDriverConfiguration::query()->find($id) !== null) {
        return;
    }
    \Spora\Models\LLMDriverConfiguration::query()->insert([
        'id' => $id,
        'principal_id' => createUserPrincipalPublic($userId),
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
        'principal_id' => createUserPrincipalPublic($userId),
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
