<?php

declare(strict_types=1);

use Spora\AgentTemplates\AgentTemplate;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Http\Exceptions\EffortValidationException;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;

/**
 * The default a template carries is checked before it is written.
 *
 * The template's `agent.default_effort` lands on the same column the
 * agent service writes, so it gets the same check: a level the
 * configuration cannot express would be stored, ignored at runtime, and
 * the run would happen at a depth nobody asked for.
 */
afterEach(fn() => Spora\Core\Database::resetBootState());

/**
 * The configuration the imported agent resolves to: the global default,
 * offering `low` and nothing else.
 */
function templateEffortConfig(): LLMDriverConfiguration
{
    return LLMDriverConfiguration::create([
        'principal_id' => null,
        'name'         => 'Template Effort Config',
        'driver_class' => OpenAICompatibleDriver::class,
        'settings'     => json_encode([
            'api_key'      => 'test',
            'model'        => 'gpt-5.5',
            'base_url'     => 'https://example.invalid',
            'effort_levels' => 'low',
        ]),
        'is_global'  => true,
        'is_default' => true,
    ]);
}

/**
 * @return array{0: int, 1: int} the user and their principal
 */
function templateEffortOwner(): array
{
    static $seq = 0;
    $seq++;
    $userId = bootAuth(bootAuthLayer(), "template-effort-{$seq}@example.com", 'Password1!', 'Template Effort');

    return [$userId, createUserPrincipalPublic($userId)];
}

it('refuses an import whose default the target configuration does not offer', function (): void {
    [$userId] = templateEffortOwner();
    templateEffortConfig();

    $payload = [
        'id'   => 'deep',
        'name' => 'Deep',
        'version' => '1.0.0',
        'agent' => ['system_prompt' => 'x', 'default_effort' => 'high'],
        'required_plugins' => [],
    ];

    expect(fn() => makeImporter()->importPayload($userId, $payload))
        ->toThrow(EffortValidationException::class, 'Supported levels: low');

    expect(Agent::where('name', 'Deep')->count())->toBe(0);
});

it('refuses an update whose default the agent\'s configuration does not offer, and leaves the stored default alone', function (): void {
    [$userId, $principalId] = templateEffortOwner();
    $config = templateEffortConfig();

    $agent = Agent::create([
        'principal_id'         => $principalId,
        'name'                 => 'Agent',
        'llm_driver_config_id' => $config->id,
        'default_effort'       => 'low',
        'max_steps'            => 10,
        'is_active'            => true,
    ]);

    $template = new AgentTemplate(
        raw: [
            'id'   => 'deep',
            'name' => 'Deep',
            'version' => '1.0.0',
            'agent' => ['default_effort' => 'high'],
        ],
        source: 'uploaded',
    );

    expect(fn() => makeImporter()->applyToAgent((int) $agent->id, $template))
        ->toThrow(EffortValidationException::class, 'Supported levels: low');

    expect(Agent::findOrFail($agent->id)->default_effort)->toBe('low');
});

it('imports a default the target configuration offers', function (): void {
    [$userId] = templateEffortOwner();
    templateEffortConfig();

    $payload = [
        'id'   => 'shallow',
        'name' => 'Shallow',
        'version' => '1.0.0',
        'agent' => ['system_prompt' => 'x', 'default_effort' => 'low'],
        'required_plugins' => [],
    ];

    $result = makeImporter()->importPayload($userId, $payload);

    expect($result->agent->default_effort)->toBe('low');
});
