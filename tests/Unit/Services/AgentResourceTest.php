<?php

declare(strict_types=1);

use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Services\AgentResource;
use Spora\Services\ToolIconResolver;

it('maps every wire-format field for an agent', function (): void {
    $userId = bootAuthLayer()->register('agent-resource@example.com', 'Password1!', 'AR');

    $agent = Agent::create([
        'user_id'              => $userId,
        'name'                 => 'Mapped Agent',
        'description'          => 'desc',
        'system_prompt'        => 'sp',
        'llm_driver_config_id' => null,
        'max_steps'            => 12,
        'is_active'            => true,
        'allow_followup'       => true,
        'retry_after_minutes'  => 3,
        'max_retries'          => 4,
        'is_pinned'            => true,
        'is_archived'          => false,
    ]);

    $array = AgentResource::toArray($agent);

    expect($array)
        ->toHaveKeys([
            'id', 'name', 'description', 'system_prompt',
            'llm_driver_config_id', 'max_steps',
            'is_active', 'allow_followup',
            'retry_after_minutes', 'max_retries',
            'is_pinned', 'is_archived',
            'created_at', 'tools',
        ])
        ->and($array['id'])->toBe((int) $agent->id)
        ->and($array['name'])->toBe('Mapped Agent')
        ->and($array['description'])->toBe('desc')
        ->and($array['system_prompt'])->toBe('sp')
        ->and($array['llm_driver_config_id'])->toBeNull()
        ->and($array['max_steps'])->toBe(12)
        ->and($array['is_active'])->toBeTrue()
        ->and($array['allow_followup'])->toBeTrue()
        ->and($array['retry_after_minutes'])->toBe(3)
        ->and($array['max_retries'])->toBe(4)
        ->and($array['is_pinned'])->toBeTrue()
        ->and($array['is_archived'])->toBeFalse()
        ->and($array['created_at'])->toBeString()
        ->and($array['tools'])->toBe([]);
});

it('defaults is_pinned and is_archived to false when the model columns are null', function (): void {
    // New Agent() without save() leaves the boolean fields null. The mapper
    // must coalesce both to false so clients never see null on a flag column.
    $agent = new Agent();
    $agent->name = 'Unset';
    $agent->max_steps = 10;
    $agent->allow_followup = false;

    $array = AgentResource::toArray($agent);

    expect($array['is_pinned'])->toBeFalse()
        ->and($array['is_archived'])->toBeFalse()
        ->and($array['created_at'])->toBeNull();
});

it('formats created_at as ATOM and emits an empty tools list when the relationship is empty', function (): void {
    $userId = bootAuthLayer()->register('agent-resource-atom@example.com', 'Password1!', 'AR');

    $agent = Agent::create([
        'user_id'   => $userId,
        'name'      => 'Atom Agent',
        'max_steps' => 10,
    ]);

    $array = AgentResource::toArray($agent);

    expect($array['created_at'])
        ->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[\+\-]\d{2}:\d{2}$/')
        ->and($array['tools'])->toBe([]);
});

it('omits the per-tool icon field when no ToolIconResolver is supplied (back-compat)', function (): void {
    // Callers without DI access (e.g. a custom resource renderer) can pass null for
    // the resolver. The wire payload still parses; the frontend's <Icon> falls back
    // to 'puzzle' on missing keys. This test pins that contract.
    $agent = new Agent();
    $agent->name = 'No Resolver';
    $agent->max_steps = 10;
    $agent->allow_followup = false;

    $array = AgentResource::toArray($agent);

    expect($array['tools'])->toBe([]);
});

it('resolves per-tool icon via the supplied ToolIconResolver', function (): void {
    $userId = bootAuthLayer()->register('agent-resource-icon@example.com', 'Password1!', 'AR');

    $agent = Agent::create([
        'user_id'   => $userId,
        'name'      => 'Icon Agent',
        'max_steps' => 10,
    ]);

    AgentTool::create([
        'agent_id'   => $agent->id,
        'tool_class' => 'Tests\\Fixtures\\Icons\\TestCalendarTool',
        'tool_name'  => 'Test Calendar',
    ]);

    $resolver = new class extends ToolIconResolver {
        public function __construct() {}

        public function resolve(string $toolClass): ?string
        {
            return match ($toolClass) {
                'Tests\\Fixtures\\Icons\\TestCalendarTool' => 'calendar',
                'Tests\\Fixtures\\TestTool' => 'mail',
                default => null,
            };
        }
    };

    $array = AgentResource::toArray($agent, null, $resolver);

    expect($array['tools'])->toHaveCount(1)
        ->and($array['tools'][0]['tool_class'])->toBe('Tests\\Fixtures\\Icons\\TestCalendarTool')
        ->and($array['tools'][0]['tool_name'])->toBe('Test Calendar')
        ->and($array['tools'][0]['icon'])->toBe('calendar');
});

it('emits icon: null when the resolver returns null (frontend falls back to puzzle)', function (): void {
    $userId = bootAuthLayer()->register('agent-resource-noicon@example.com', 'Password1!', 'AR');

    $agent = Agent::create([
        'user_id'   => $userId,
        'name'      => 'No-Icon Agent',
        'max_steps' => 10,
    ]);

    AgentTool::create([
        'agent_id'   => $agent->id,
        'tool_class' => 'Tests\\Fixtures\\TestTool',
        'tool_name'  => 'Test Tool',
    ]);

    $resolver = new class extends ToolIconResolver {
        public function __construct() {}

        public function resolve(string $toolClass): ?string
        {
            return null;
        }
    };

    $array = AgentResource::toArray($agent, null, $resolver);

    expect($array['tools'])->toHaveCount(1)
        ->and($array['tools'][0])->toHaveKey('icon')
        ->and($array['tools'][0]['icon'])->toBeNull();
});
