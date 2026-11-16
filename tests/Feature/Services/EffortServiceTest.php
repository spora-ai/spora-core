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
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * The levels an agent's effective driver can express, and the refusal of a
 * level it cannot.
 *
 * `GET /api/v1/agents/{id}/effort` reports the levels, and the request paths
 * refuse everything outside that set — so a client that offers what the
 * endpoint reported is never refused.
 */
afterEach(fn() => \Spora\Core\Database::resetBootState());

test('the reported levels are the ones the driver declares', function (): void {
    [$service, $agent] = effortServiceFixture();

    $described = $service->describe($agent);

    $driver = new AnthropicCompatibleDriver(
        apiKey: 'test',
        model: 'claude-sonnet-5-5',
        baseUrl: 'https://example.invalid',
        httpClient: new MockHttpClient(),
    );

    expect(array_column($described['levels'], 'value'))
        ->toBe(array_keys($driver->effortLevels()))
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

test('a driver that declares no level refuses every level', function (): void {
    [$service, $agent] = effortServiceFixture(EffortlessDriver::class);

    try {
        $service->assertLevel('high', $agent);
    } catch (EffortValidationException $e) {
        expect($e->errorCode())->toBe('EFFORT_LEVEL_UNSUPPORTED')
            ->and($e->getMessage())->toContain('cannot express a reasoning effort for this model');

        return;
    }

    $this->fail('Expected an EffortValidationException.');
});

test('a model that takes no effort refuses every level', function (): void {
    [$service, $agent] = effortServiceFixture(AnthropicCompatibleDriver::class, 'claude-sonnet-4-5-20250929');

    try {
        $service->assertLevel('high', $agent);
    } catch (EffortValidationException $e) {
        expect($e->errorCode())->toBe('EFFORT_LEVEL_UNSUPPORTED')
            ->and($e->getMessage())->toContain('cannot express a reasoning effort for this model');

        return;
    }

    $this->fail('Expected an EffortValidationException.');
});

test('an agent with no LLM configuration is told that, and not that its driver cannot reason', function (): void {
    [$service, $agent] = effortServiceFixtureWithoutConfiguration();

    try {
        $service->assertLevel('high', $agent);
    } catch (EffortValidationException $e) {
        expect($e->errorCode())->toBe('EFFORT_LEVEL_UNSUPPORTED')
            ->and($e->getMessage())->toContain('no LLM configuration');

        return;
    }

    $this->fail('Expected an EffortValidationException.');
});

test('a driver that cannot be built is told that, and not that it cannot reason', function (): void {
    [$service, $agent] = effortServiceFixtureWithMissingDriver();

    try {
        $service->assertLevel('high', $agent);
    } catch (EffortValidationException $e) {
        expect($e->errorCode())->toBe('EFFORT_LEVEL_UNSUPPORTED')
            ->and($e->getMessage())->toContain('could not be resolved');

        return;
    }

    $this->fail('Expected an EffortValidationException.');
});

test('the reported default is one the driver would send', function (): void {
    [$service, $agent] = effortServiceFixture(AnthropicCompatibleDriver::class, 'claude-sonnet-5-5', 'high');

    expect($service->describe($agent)['default'])->toBe('high');
});

test('a default the model does not take is not reported', function (): void {
    // Opus 4.6 has no xhigh, so the driver would never send it.
    [$service, $agent] = effortServiceFixture(AnthropicCompatibleDriver::class, 'claude-opus-4-6', 'xhigh');

    $described = $service->describe($agent);

    expect($described['supported'])->toBeTrue()
        ->and($described['default'])->toBeNull();
});

test('a model that takes no effort reports no levels and no default', function (): void {
    [$service, $agent] = effortServiceFixture(AnthropicCompatibleDriver::class, 'claude-sonnet-4-5-20250929', 'high');

    $described = $service->describe($agent);

    expect($described['supported'])->toBeFalse()
        ->and($described['levels'])->toBe([])
        ->and($described['default'])->toBeNull();
});

test('the configuration default effort is read by one rule', function (): void {
    // The rule lives in {@see \Spora\Services\LLMConfigService}: the
    // endpoint and the write path both read it there.
    $authService = bootAuthLayer();
    $userId = bootAuth($authService);

    $config = LLMDriverConfiguration::create([
        'principal_id' => null,
        'name' => 'Default Effort Config',
        'driver_class' => AnthropicCompatibleDriver::class,
        'settings' => json_encode([
            'api_key' => 'test',
            'model' => 'claude-sonnet-5-5',
            'effort' => 'high',
        ]),
        'is_global' => true,
        'is_default' => true,
    ]);

    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $llmConfigService = new LLMConfigService($security, [AnthropicCompatibleDriver::class]);

    expect($llmConfigService->defaultEffort($config))->toBe('high');
});

/**
 * The configured list and the agent's own default.
 *
 * Two independent things are called "effort": the capability — which
 * levels the configuration offers — and the default — which level a run
 * uses when nobody names one. The report carries both.
 */
test('the reported levels are the configured list', function (): void {
    [$service, $agent] = effortServiceFixture(
        AnthropicCompatibleDriver::class,
        'claude-sonnet-5-5',
        null,
        ['effort_levels' => 'low, high'],
    );

    $described = $service->describe($agent);

    expect($described['supported'])->toBeTrue()
        ->and($described['levels'])->toBe([
            ['value' => 'low', 'label' => 'Low'],
            ['value' => 'high', 'label' => 'High'],
        ]);
});

test('the reported default is the agent\'s effective default', function (): void {
    [$service, $agent] = effortServiceFixture(
        AnthropicCompatibleDriver::class,
        'claude-sonnet-5-5',
        'medium',
        ['effort_levels' => 'low, high'],
        'high',
    );

    expect($service->describe($agent)['default'])->toBe('high');
});

test('the agent\'s own default wins over the configuration\'s', function (): void {
    [$service, $agent] = effortServiceFixture(
        AnthropicCompatibleDriver::class,
        'claude-sonnet-5-5',
        'medium',
        [],
        'high',
    );

    expect($service->defaultFor($agent))->toBe('high');
});

test('the configuration\'s default applies when the agent names none', function (): void {
    [$service, $agent] = effortServiceFixture(
        AnthropicCompatibleDriver::class,
        'claude-sonnet-5-5',
        'medium',
    );

    expect($service->defaultFor($agent))->toBe('medium');
});

test('neither an agent default nor a configuration default means the model default', function (): void {
    [$service, $agent] = effortServiceFixture();

    expect($service->defaultFor($agent))->toBeNull();
});

test('an agent default the driver does not offer falls back to the configuration\'s', function (): void {
    // The Anthropic driver offers no `none`, so an agent default left
    // behind by a configuration change would be dropped on the way to the
    // provider.
    [$service, $agent] = effortServiceFixture(
        AnthropicCompatibleDriver::class,
        'claude-sonnet-5-5',
        'medium',
        [],
        'none',
    );

    expect($service->defaultFor($agent))->toBe('medium');
});

test('the level set of the agent is the configuration\'s', function (): void {
    [$service, $agent] = effortServiceFixture(
        AnthropicCompatibleDriver::class,
        'claude-sonnet-5-5',
        null,
        ['effort_levels' => 'low, high'],
    );

    expect($service->levelSetFor($agent)->names())->toBe(['low', 'high'])
        ->and($service->levelSetFor($agent)->has('low'))->toBeTrue()
        ->and($service->levelSetFor($agent)->has('medium'))->toBeFalse();
});

test('a configuration whose levels are named with digits resolves them and its default', function (): void {
    // The levels are named "1" and "2", so the driver's map is keyed by
    // the int 1 and 2 — PHP turns a numeric-string key into an int.
    [$service, $agent] = effortServiceFixture(
        AnthropicCompatibleDriver::class,
        'claude-sonnet-5-5',
        null,
        ['effort_levels' => '1,2'],
        '1',
    );

    expect($service->levelSetFor($agent)->names())->toBe(['1', '2'])
        ->and($service->defaultFor($agent))->toBe('1')
        ->and(fn() => $service->assertLevel('1', $agent))->not->toThrow(EffortValidationException::class);
});

test('an agent default the level set does not name is refused, whitespace and all', function (): void {
    // A level name cannot carry whitespace, so " high " is not a level
    // name at all — and the value that is checked is the value that is
    // stored, or the stored default would silently lose to the
    // configuration's.
    [$service, $agent] = effortServiceFixture(
        AnthropicCompatibleDriver::class,
        'claude-sonnet-5-5',
        null,
        ['effort_levels' => 'low, high'],
    );

    try {
        $service->assertAgentDefault(' high ', $agent);
    } catch (EffortValidationException $e) {
        expect($e->errorCode())->toBe('EFFORT_LEVEL_UNSUPPORTED')
            ->and($e->getMessage())->toContain('Supported levels: low, high');

        return;
    }

    $this->fail('Expected an EffortValidationException.');
});

test('an agent default the level set names is accepted and is the default the chain returns', function (): void {
    [$service, $agent] = effortServiceFixture(
        AnthropicCompatibleDriver::class,
        'claude-sonnet-5-5',
        null,
        ['effort_levels' => 'low, high'],
        'high',
    );

    expect(fn() => $service->assertAgentDefault('high', $agent))->not->toThrow(EffortValidationException::class)
        ->and($service->defaultFor($agent))->toBe('high');
});

test('a level the configuration names is accepted', function (): void {
    [$service, $agent] = effortServiceFixture(
        AnthropicCompatibleDriver::class,
        'claude-sonnet-5-5',
        null,
        ['effort_levels' => 'low, high'],
    );

    expect(fn() => $service->assertLevel('low', $agent))->not->toThrow(EffortValidationException::class);
});

test('a level the configuration does not name is refused', function (): void {
    [$service, $agent] = effortServiceFixture(
        AnthropicCompatibleDriver::class,
        'claude-sonnet-5-5',
        null,
        ['effort_levels' => 'low, high'],
    );

    try {
        $service->assertLevel('medium', $agent);
    } catch (EffortValidationException $e) {
        expect($e->errorCode())->toBe('EFFORT_LEVEL_UNSUPPORTED')
            ->and($e->getMessage())->toContain('Supported levels: low, high');

        return;
    }

    $this->fail('Expected an EffortValidationException.');
});

/**
 * @param array<string, mixed> $extraSettings
 * @return array{0: EffortService, 1: Agent} the service and the agent it answers for
 */
function effortServiceFixture(
    string  $driverClass = AnthropicCompatibleDriver::class,
    string  $model = 'claude-sonnet-5-5',
    ?string $configuredEffort = null,
    array   $extraSettings = [],
    ?string $agentDefault = null,
): array {
    $authService = bootAuthLayer();
    $userId = bootAuth($authService);

    $settings = array_merge([
        'api_key' => 'test',
        'model' => $model,
        'base_url' => 'https://example.invalid',
    ], $extraSettings);

    if ($configuredEffort !== null) {
        $settings['effort'] = $configuredEffort;
    }

    $config = LLMDriverConfiguration::create([
        'principal_id' => null,
        'name' => 'Effort Service Config',
        'driver_class' => $driverClass,
        'settings' => json_encode($settings),
        'is_global' => true,
        'is_default' => true,
    ]);

    $agent = Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name' => 'Effort Service Agent',
        'llm_driver_config_id' => $config->id,
        'max_steps' => 10,
        'default_effort' => $agentDefault,
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
 * An agent whose configuration names a driver class nobody ships.
 *
 * @return array{0: EffortService, 1: Agent} the service and the agent it answers for
 */
function effortServiceFixtureWithMissingDriver(): array
{
    $authService = bootAuthLayer();
    $userId = bootAuth($authService);

    $config = LLMDriverConfiguration::create([
        'principal_id' => null,
        'name' => 'Effort Service Config Without Driver',
        'driver_class' => 'Spora\\Drivers\\RemovedDriver',
        'settings' => json_encode([
            'api_key' => 'test',
            'model' => 'gpt-4o',
            'base_url' => 'https://example.invalid',
        ]),
        'is_global' => true,
        'is_default' => true,
    ]);

    $agent = Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name' => 'Effort Service Agent Without Driver',
        'llm_driver_config_id' => $config->id,
        'max_steps' => 10,
        'is_active' => true,
    ]);

    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $llmConfigService = new LLMConfigService($security, [AnthropicCompatibleDriver::class]);

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
 * An agent with no effective configuration at all.
 *
 * @return array{0: EffortService, 1: Agent} the service and the agent it answers for
 */
function effortServiceFixtureWithoutConfiguration(): array
{
    $authService = bootAuthLayer();
    $userId = bootAuth($authService);

    $agent = Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name' => 'Effort Service Agent Without Configuration',
        'llm_driver_config_id' => null,
        'max_steps' => 10,
        'is_active' => true,
    ]);

    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $llmConfigService = new LLMConfigService($security, [AnthropicCompatibleDriver::class]);

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
 * A driver that declares no effort level — the shape of a driver with no
 * effort capability, and of a driver whose model takes none.
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
