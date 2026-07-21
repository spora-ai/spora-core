<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MediaArchive;

use Psr\Log\NullLogger;
use Spora\Core\SecurityManager;
use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Services\LLMConfigService;
use Spora\Services\MediaArchive\Converters\PdfToMarkdownConverter;
use Spora\Services\MediaArchive\MediaAllowedTypesService;
use Spora\Services\MediaArchive\MediaConverterDiscovery;
use Spora\Services\MediaArchive\MediaConverterRegistry;
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
function buildAllowedTypesService(?array $imageExtensions = null): array
{
    $registry = MediaArchiveTestSupport::buildConverterRegistry();
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $llmService = new LLMConfigService($security, [
        OpenAICompatibleDriver::class,
        AnthropicCompatibleDriver::class,
    ]);
    $factory = new DriverFactory(new NullLogger(), $llmService, 60);
    return [
        new MediaAllowedTypesService($registry, $factory, $imageExtensions),
        $registry,
    ];
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


test('allowedMimeTypes defaults to png/jpeg/webp when no list is configured (vision agent)', function (): void {
    $authService = bootAuthLayer();
    $userId = bootAuth($authService);
    seedLlmConfig(1, $userId, OpenAICompatibleDriver::class, 'gpt-4o');
    seedAgent(42, $userId);
    [$service] = buildAllowedTypesService();

    $mimes = $service->allowedMimeTypes(42);

    expect($mimes)->toContain('image/png');
    expect($mimes)->toContain('image/jpeg');
    expect($mimes)->toContain('image/webp');
    // GIF and SVG are excluded from the default; vision models don't
    // universally read them and SVG needs sanitization.
    expect($mimes)->not->toContain('image/gif');
    expect($mimes)->not->toContain('image/svg+xml');
});

test('allowedMimeTypes respects configured image extensions (gif opt-in)', function (): void {
    $authService = bootAuthLayer();
    $userId = bootAuth($authService);
    seedLlmConfig(1, $userId, OpenAICompatibleDriver::class, 'gpt-4o');
    seedAgent(42, $userId);
    [$service] = buildAllowedTypesService(['gif']);

    $mimes = $service->allowedMimeTypes(42);

    expect($mimes)->toContain('image/gif');
    // The default triple stays only if the operator explicitly opted in
    // to those as well. The ctor list is authoritative.
    expect($mimes)->not->toContain('image/png');
    expect($mimes)->not->toContain('image/jpeg');
    expect($mimes)->not->toContain('image/webp');
});

test('allowedMimeTypes excludes svg even when configured', function (): void {
    $authService = bootAuthLayer();
    $userId = bootAuth($authService);
    seedLlmConfig(1, $userId, OpenAICompatibleDriver::class, 'gpt-4o');
    seedAgent(42, $userId);
    [$service] = buildAllowedTypesService(['png', 'svg']);

    $mimes = $service->allowedMimeTypes(42);

    expect($mimes)->toContain('image/png');
    expect($mimes)->not->toContain('image/svg+xml');
});

test('allowedMimeTypes with empty configured list returns no images even for vision agents', function (): void {
    $authService = bootAuthLayer();
    $userId = bootAuth($authService);
    seedLlmConfig(1, $userId, OpenAICompatibleDriver::class, 'gpt-4o');
    seedAgent(42, $userId);
    [$service] = buildAllowedTypesService([]);

    $mimes = $service->allowedMimeTypes(42);

    foreach ($mimes as $m) {
        expect(str_starts_with($m, 'image/'))->toBeFalse();
    }
    expect($service->imageExtensions())->toBe([]);
});

test('allowedMimeTypes normalises jpg to jpeg', function (): void {
    $authService = bootAuthLayer();
    $userId = bootAuth($authService);
    seedLlmConfig(1, $userId, OpenAICompatibleDriver::class, 'gpt-4o');
    seedAgent(42, $userId);
    [$service] = buildAllowedTypesService(['jpg', 'jpeg']);

    $mimes = $service->allowedMimeTypes(42);

    // jpg → jpeg normalisation collapses duplicates; only image/jpeg
    // shows up.
    $jpegCount = 0;
    foreach ($mimes as $m) {
        if ($m === 'image/jpeg') {
            $jpegCount++;
        }
    }
    expect($jpegCount)->toBe(1);
});

test('normalizeImageExtensions handles casing, dots, and duplicates', function (): void {
    expect(MediaAllowedTypesService::normalizeImageExtensions(['PNG', '.jpg', 'WebP', 'PNG']))
        ->toBe(['png', 'jpeg', 'webp']);
});

test('normalizeImageExtensions returns the built-in default when input is null', function (): void {
    expect(MediaAllowedTypesService::normalizeImageExtensions(null))
        ->toBe(['png', 'jpeg', 'webp']);
});

test('normalizeImageExtensions preserves an empty list', function (): void {
    expect(MediaAllowedTypesService::normalizeImageExtensions([]))->toBe([]);
});
