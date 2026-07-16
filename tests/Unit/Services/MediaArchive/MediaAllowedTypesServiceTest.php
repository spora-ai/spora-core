<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MediaArchive;

use Mockery;
use Psr\Log\NullLogger;
use Spora\Core\SecurityManager;
use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\LLMDriverInterface;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\User;
use Spora\Services\LLMConfigService;
use Spora\Services\MediaArchive\Converters\PdfToMarkdownConverter;
use Spora\Services\MediaArchive\MediaAllowedTypesService;
use Spora\Services\MediaArchive\MediaConverterDiscovery;
use Spora\Services\MediaArchive\MediaConverterRegistry;
use Symfony\Component\HttpClient\MockHttpClient;
use Tests\Support\MediaArchiveTestSupport;

/**
 * Plan §12 B2b — pinning MediaAllowedTypesService behaviour.
 */
afterEach(function (): void {
    MediaConverterDiscovery::reset();
});

test('allowedMimeTypes returns the static text allowlist without an agent', function (): void {
    [$service] = buildAllowedTypesService();
    $mimes = $service->allowedMimeTypes();
    expect($mimes)->toContain('text/plain');
    expect($mimes)->toContain('text/markdown');
    expect($mimes)->toContain('application/json');
});

test('allowedMimeTypes unions in converter-supplied MIME types', function (): void {
    MediaConverterDiscovery::add(PdfToMarkdownConverter::class);
    [$service] = buildAllowedTypesService();
    $mimes = $service->allowedMimeTypes();
    expect($mimes)->toContain('application/pdf');
});

test('allowedMimeTypes without an agent does NOT include image/*', function (): void {
    [$service] = buildAllowedTypesService();
    $mimes = $service->allowedMimeTypes();
    foreach ($mimes as $m) {
        expect(str_starts_with($m, 'image/'))->toBeFalse();
    }
});

test('allowedMimeTypes adds image/* when the agent\'s LLM is vision-capable', function (): void {
    $authService = bootAuthLayer();
    $userId = bootAuth($authService);
    seedLlmConfig(1, $userId, OpenAICompatibleDriver::class, 'gpt-4o');
    seedAgent(42, $userId);
    [$service] = buildAllowedTypesService();
    $mimes = $service->allowedMimeTypes(42);
    expect($mimes)->toContain('image/png');
    expect($mimes)->toContain('image/jpeg');
});

test('allowedMimeTypes without vision LLM still excludes image/*', function (): void {
    $authService = bootAuthLayer();
    $userId = bootAuth($authService);
    seedLlmConfig(1, $userId, OpenAICompatibleDriver::class, 'gpt-3.5-turbo');
    seedAgent(42, $userId);
    [$service] = buildAllowedTypesService();
    $mimes = $service->allowedMimeTypes(42);
    foreach ($mimes as $m) {
        expect(str_starts_with($m, 'image/'))->toBeFalse();
    }
});

test('isAllowed reports text MIME as allowed and binary executable as not', function (): void {
    [$service] = buildAllowedTypesService();
    expect($service->isAllowed('text/plain', null))->toBeTrue();
    expect($service->isAllowed('application/x-msdownload', null))->toBeFalse();
});

/**
 * @return array{0: MediaAllowedTypesService, 1: MediaConverterRegistry}
 */
function buildAllowedTypesService(): array
{
    $registry = MediaArchiveTestSupport::buildConverterRegistry();
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $llmService = new LLMConfigService($security, [
        OpenAICompatibleDriver::class,
        AnthropicCompatibleDriver::class,
    ]);
    $factory = new DriverFactory(new NullLogger(), $llmService, 60);
    return [new MediaAllowedTypesService($registry, $factory), $registry];
}

function seedLlmConfig(int $id, int $userId, string $driverClass, string $model): void
{
    if (LLMDriverConfiguration::query()->find($id) !== null) {
        return;
    }
    LLMDriverConfiguration::query()->insert([
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

function seedAgent(int $id, int $userId): void
{
    if (Agent::query()->find($id) !== null) {
        return;
    }
    Agent::query()->insert([
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