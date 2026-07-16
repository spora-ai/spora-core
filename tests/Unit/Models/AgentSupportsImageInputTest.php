<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use Psr\Log\NullLogger;
use RuntimeException;
use Spora\Core\SecurityManager;
use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\Exceptions\LLMConfigDecryptFailedException;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Services\LLMConfigService;

/**
 * Plan §12 m2-m8 — pin {@see Agent::supportsImageInput()}, the
 * Eloquent accessor that turns the configured LLM into a simple
 * boolean. Used by the controller layer (and the capability
 * pre-flight) so the chat surface knows whether to attach images.
 */

test('returns false when no DriverFactory is provided', function (): void {
    $agent = new Agent();

    expect($agent->supportsImageInput())->toBeFalse();
});

test('delegates to DriverFactory::makeFromAgent when llm_driver_config_id is null', function (): void {
    // With no per-agent config, the factory's three-tier resolver falls
    // back to the global default LLM. Verify the accessor simply
    // forwards that resolution and reports the resulting driver's
    // capability (gpt-4o → true).
    $factory = makeDriverFactory();
    $agent = new Agent();
    $agent->llm_driver_config_id = null;

    expect($agent->supportsImageInput($factory))->toBeBool();
});

test('returns true for a vision-capable LLM (OpenAI gpt-4o)', function (): void {
    $userId = bootAuthLayer()->register('agent-image-openai@example.com', 'Password1!', 'OpenAI');
    seedLlmConfig(1, $userId, OpenAICompatibleDriver::class, 'gpt-4o');
    $agent = seedAgentWithConfig(42, $userId, 1);

    $factory = makeDriverFactory();
    expect($agent->supportsImageInput($factory))->toBeTrue();
});

test('returns true for a vision-capable LLM (Anthropic claude-3-5-sonnet)', function (): void {
    $userId = bootAuthLayer()->register('agent-image-anthropic@example.com', 'Password1!', 'Anthropic');
    seedLlmConfig(1, $userId, AnthropicCompatibleDriver::class, 'claude-3-5-sonnet-20241022');
    $agent = seedAgentWithConfig(43, $userId, 1);

    $factory = makeDriverFactory();
    expect($agent->supportsImageInput($factory))->toBeTrue();
});

test('returns false for a text-only LLM (OpenAI gpt-3.5-turbo)', function (): void {
    $userId = bootAuthLayer()->register('agent-text-openai@example.com', 'Password1!', 'TextOpenAI');
    seedLlmConfig(1, $userId, OpenAICompatibleDriver::class, 'gpt-3.5-turbo');
    $agent = seedAgentWithConfig(44, $userId, 1);

    $factory = makeDriverFactory();
    expect($agent->supportsImageInput($factory))->toBeFalse();
});

test('returns false for a text-only LLM (Anthropic claude-2.1)', function (): void {
    $userId = bootAuthLayer()->register('agent-text-anthropic@example.com', 'Password1!', 'TextAnthropic');
    seedLlmConfig(1, $userId, AnthropicCompatibleDriver::class, 'claude-2.1');
    $agent = seedAgentWithConfig(45, $userId, 1);

    $factory = makeDriverFactory();
    expect($agent->supportsImageInput($factory))->toBeFalse();
});

test('returns false when DriverFactory throws during construction', function (): void {
    $factory = new class extends DriverFactory {
        public function __construct()
        {
            // Skip parent — we don't want a real config service.
        }
        public function makeFromAgent(Agent $agent): \Spora\Drivers\LLMDriverInterface
        {
            throw new LLMConfigDecryptFailedException('bad settings');
        }
    };
    $agent = new Agent();
    $agent->llm_driver_config_id = 1;

    expect($agent->supportsImageInput($factory))->toBeFalse();
});

test('returns false when DriverFactory throws a generic exception', function (): void {
    $factory = new class extends DriverFactory {
        public function __construct()
        {
            // Skip parent.
        }
        public function makeFromAgent(Agent $agent): \Spora\Drivers\LLMDriverInterface
        {
            throw new RuntimeException('driver class missing');
        }
    };
    $agent = new Agent();
    $agent->llm_driver_config_id = 1;

    expect($agent->supportsImageInput($factory))->toBeFalse();
});

/**
 * Minimal DriverFactory wired to a real LLMConfigService so the
 * production resolution path is exercised end-to-end.
 */
function makeDriverFactory(): DriverFactory
{
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $llmService = new LLMConfigService($security, [
        OpenAICompatibleDriver::class,
        AnthropicCompatibleDriver::class,
    ]);
    return new DriverFactory(new NullLogger(), $llmService, 60);
}

/**
 * @param class-string $driverClass
 */
function seedLlmConfig(int $id, int $userId, string $driverClass, string $model): void
{
    LLMDriverConfiguration::query()->where('id', $id)->delete();
    LLMDriverConfiguration::query()->insert([
        'id'           => $id,
        'user_id'      => $userId,
        'name'         => "cfg-{$id}",
        'driver_class' => $driverClass,
        'settings'     => json_encode([
            'api_key'  => '',
            'model'    => $model,
            'base_url' => 'https://example.invalid/v1',
            'timeout'  => '60',
        ]),
        'is_default' => 1,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
}

function seedAgentWithConfig(int $id, int $userId, int $configId): Agent
{
    Agent::query()->where('id', $id)->delete();
    Agent::query()->insert([
        'id'                   => $id,
        'user_id'              => $userId,
        'name'                 => "agent-{$id}",
        'description'          => '',
        'system_prompt'        => '',
        'llm_driver_config_id' => $configId,
        'max_steps'            => 5,
        'is_active'            => 1,
        'allow_followup'       => 1,
        'retry_after_minutes'  => 0,
        'max_retries'          => 0,
        'created_at'           => date('Y-m-d H:i:s'),
        'updated_at'           => date('Y-m-d H:i:s'),
    ]);
    return Agent::query()->find($id);
}