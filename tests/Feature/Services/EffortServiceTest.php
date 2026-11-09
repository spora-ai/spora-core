<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use Psr\Log\NullLogger;
use Spora\Core\SecurityManager;
use Spora\Drivers\AbstractCompatibleDriver;
use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\ValueObjects\LLMRequest;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Http\Exceptions\EffortValidationException;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Services\EffortService;
use Spora\Services\LLMConfigPreferences;
use Spora\Services\LLMConfigService;

/**
 * The levels an agent's effective driver can express, and the refusal of a
 * level it cannot.
 *
 * `GET /api/v1/agents/{id}/effort` reports the levels, and the request paths
 * refuse everything outside that set — so a client that offers what the
 * endpoint reported is never refused.
 */
afterEach(fn() => \Spora\Core\Database::resetBootState());

test('the declared levels are the ones the endpoint reports', function (): void {
    [$service, $agent] = effortServiceFixture();

    $described = $service->describe($agent);

    expect($service->declaredLevels($agent))
        ->toBe(array_column($described['levels'], 'value'))
        ->and($described['supported'])->toBeTrue();
});

test('a level the driver offers is accepted', function (): void {
    [$service, $agent] = effortServiceFixture();

    expect(fn() => $service->assertLevel('high', $agent))->not->toThrow(EffortValidationException::class);
});

test('the model default and an absent level are accepted', function (): void {
    [$service, $agent] = effortServiceFixture();

    expect(fn() => $service->assertLevel('', $agent))->not->toThrow(EffortValidationException::class)
        ->and(fn() => $service->assertLevel(null, $agent))->not->toThrow(EffortValidationException::class);
});

test('a level the driver does not offer is refused', function (): void {
    [$service, $agent] = effortServiceFixture();

    try {
        $service->assertLevel('none', $agent);
    } catch (EffortValidationException $e) {
        expect($e->errorCode())->toBe('EFFORT_LEVEL_UNSUPPORTED')
            ->and($e->getMessage())->toContain('Supported levels: low, medium, high, xhigh, max');

        return;
    }

    $this->fail('Expected an EffortValidationException.');
});

test('a driver that cannot express an effort refuses every level', function (): void {
    [$service, $agent] = effortServiceFixture(EffortlessDriver::class);

    expect($service->declaredLevels($agent))->toBe([]);

    try {
        $service->assertLevel('high', $agent);
    } catch (EffortValidationException $e) {
        expect($e->errorCode())->toBe('EFFORT_LEVEL_UNSUPPORTED')
            ->and($e->getMessage())->toContain('cannot express a reasoning effort');

        return;
    }

    $this->fail('Expected an EffortValidationException.');
});

/**
 * @return array{0: EffortService, 1: Agent} the service and the agent it answers for
 */
function effortServiceFixture(string $driverClass = AnthropicCompatibleDriver::class): array
{
    $authService = bootAuthLayer();
    $userId = bootAuth($authService);

    $config = LLMDriverConfiguration::create([
        'principal_id' => null,
        'name' => 'Effort Service Config',
        'driver_class' => $driverClass,
        'settings' => json_encode([
            'api_key' => 'test',
            'model' => 'claude-sonnet-5-5',
            'base_url' => 'https://example.invalid',
        ]),
        'is_global' => true,
        'is_default' => true,
    ]);

    $agent = Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name' => 'Effort Service Agent',
        'llm_driver_config_id' => $config->id,
        'max_steps' => 10,
        'is_active' => true,
    ]);

    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $llmConfigService = new LLMConfigService($security, [$driverClass]);

    return [
        new EffortService(
            new LLMConfigPreferences(),
            $llmConfigService,
            new DriverFactory(new NullLogger(), $llmConfigService, 60),
        ),
        $agent,
    ];
}

/**
 * A driver that cannot express a reasoning effort — the shape of every
 * driver that does not implement the capability.
 */
final class EffortlessDriver extends AbstractCompatibleDriver
{
    public static function getName(): string
    {
        return 'effortless_test';
    }

    public static function getDisplayName(): string
    {
        return 'Effortless Test';
    }

    public function getProviderName(): string
    {
        return 'effortless_test';
    }

    public function complete(LLMRequest $request): LLMResponse
    {
        return new LLMResponse('', [], 0, 0, '');
    }
}
