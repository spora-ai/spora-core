<?php

declare(strict_types=1);

use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Http\Exceptions\EffortValidationException;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Services\AgentService;

/**
 * The agent's default is judged against the configuration the agent
 * resolves to *after* the write.
 *
 * The agent's own configuration pointer wins over the principal's
 * fallback, so the fallback is not the yardstick: a default the selected
 * configuration offers is accepted even when the fallback offers only
 * something else, and a default the selected configuration cannot use is
 * refused even when the configuration the agent is leaving does offer it.
 */
afterEach(fn() => Spora\Core\Database::resetBootState());

/**
 * @param array<string, mixed> $settings
 */
function agentEffortConfig(string $name, array $settings, bool $isDefault = false): LLMDriverConfiguration
{
    return LLMDriverConfiguration::create([
        'principal_id' => null,
        'name'         => $name,
        'driver_class' => OpenAICompatibleDriver::class,
        'settings'     => json_encode(array_merge([
            'api_key'  => 'test',
            'model'    => 'gpt-5.5',
            'base_url' => 'https://example.invalid',
        ], $settings)),
        'is_global'  => true,
        'is_default' => $isDefault,
    ]);
}

/**
 * @return array{0: AgentService, 1: int} the service and the owning user
 */
function agentEffortService(): array
{
    $authService = bootAuthLayer();
    static $seq = 0;
    $seq++;
    $userId = bootAuth($authService, "agent-effort-config-{$seq}@example.com", 'Password1!', 'Agent Effort Config');
    createUserPrincipalPublic($userId);

    return [new AgentService(null, null, null, null, makeEffortService()), $userId];
}

it('accepts a default the selected configuration offers, even when the fallback does not', function (): void {
    [$service, $userId] = agentEffortService();

    agentEffortConfig('Fallback', ['effort_levels' => 'low'], true);
    $selected = agentEffortConfig('Selected', ['effort_levels' => 'low, high']);

    $agent = $service->createAgent($userId, [
        'name'                 => 'Agent',
        'llm_driver_config_id' => $selected->id,
        'default_effort'       => 'high',
    ]);

    expect($agent->default_effort)->toBe('high')
        ->and((int) $agent->llm_driver_config_id)->toBe((int) $selected->id);
});

it('refuses an update that re-points the agent at a configuration that does not offer the saved default', function (): void {
    [$service, $userId] = agentEffortService();

    $narrow = agentEffortConfig('Narrow', ['effort_levels' => 'low'], true);
    $wide   = agentEffortConfig('Wide', ['effort_levels' => 'low, high']);

    $agent = $service->createAgent($userId, [
        'name'                 => 'Agent',
        'llm_driver_config_id' => $wide->id,
        'default_effort'       => 'high',
    ]);

    expect(fn() => $service->updateAgent((int) $agent->id, $userId, [
        'llm_driver_config_id' => $narrow->id,
    ]))->toThrow(EffortValidationException::class, 'Supported levels: low');

    $stored = Agent::findOrFail($agent->id);
    expect((int) $stored->llm_driver_config_id)->toBe((int) $wide->id)
        ->and($stored->default_effort)->toBe('high');
});

it('refuses a default the selected configuration cannot use, even when the configuration it replaces offers it', function (): void {
    [$service, $userId] = agentEffortService();

    $narrow = agentEffortConfig('Narrow', ['effort_levels' => 'low'], true);
    $wide   = agentEffortConfig('Wide', ['effort_levels' => 'low, high']);

    $agent = $service->createAgent($userId, [
        'name'                 => 'Agent',
        'llm_driver_config_id' => $wide->id,
        'default_effort'       => 'high',
    ]);

    expect(fn() => $service->updateAgent((int) $agent->id, $userId, [
        'llm_driver_config_id' => $narrow->id,
        'default_effort'       => 'high',
    ]))->toThrow(EffortValidationException::class, 'Supported levels: low');

    $stored = Agent::findOrFail($agent->id);
    expect((int) $stored->llm_driver_config_id)->toBe((int) $wide->id)
        ->and($stored->default_effort)->toBe('high');
});

it('accepts an update that names a default the selected configuration offers', function (): void {
    [$service, $userId] = agentEffortService();

    $narrow = agentEffortConfig('Narrow', ['effort_levels' => 'low'], true);
    $wide   = agentEffortConfig('Wide', ['effort_levels' => 'low, high']);

    $agent = $service->createAgent($userId, [
        'name'                 => 'Agent',
        'llm_driver_config_id' => $narrow->id,
        'default_effort'       => 'low',
    ]);

    $updated = $service->updateAgent((int) $agent->id, $userId, [
        'llm_driver_config_id' => $wide->id,
        'default_effort'       => 'high',
    ]);

    expect($updated->default_effort)->toBe('high')
        ->and((int) $updated->llm_driver_config_id)->toBe((int) $wide->id);
});
