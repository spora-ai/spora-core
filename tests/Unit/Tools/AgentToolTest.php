<?php

declare(strict_types=1);

use Mockery\MockInterface;
use Spora\AgentTemplates\AgentTemplateImporter;
use Spora\AgentTemplates\AgentTemplateValidator;
use Spora\Models\Agent;
use Spora\Services\AgentServiceInterface;
use Spora\Services\AgentToolSettingsServiceInterface;
use Spora\Tools\AgentTool;

/**
 * @return array{0: AgentTool, 1: AgentServiceInterface, 2: AgentToolSettingsServiceInterface}
 */
function makeAgentTool(): array
{
    // AgentTemplateImporter + AgentTemplateValidator are final and cannot
    // be mocked directly; use real instances. Validator is parameter-less,
    // importer needs a real ToolConfigService + PluginLoader + Paths.
    $importer = new AgentTemplateImporter(
        new Spora\Services\ToolConfigService(
            new Spora\Core\SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
            new Psr\Log\NullLogger(),
        ),
        new Spora\Plugins\PluginLoader([], null),
        new Spora\Core\Paths(BASE_PATH),
    );
    $validator = new AgentTemplateValidator();
    /** @var AgentServiceInterface&MockInterface $agentService */
    $agentService = Mockery::mock(AgentServiceInterface::class);
    /** @var AgentToolSettingsServiceInterface&MockInterface $toolSettings */
    $toolSettings = Mockery::mock(AgentToolSettingsServiceInterface::class);

    return [
        new AgentTool($agentService, $toolSettings, $importer, $validator),
        $agentService,
        $toolSettings,
    ];
}

function stubAgent(int $id = 1, string $name = 'Test Agent', ?string $notes = null): Agent
{
    $agent          = new Agent();
    $agent->id      = $id;
    $agent->name    = $name;
    $agent->notes   = $notes;
    $agent->user_id = 99;
    return $agent;
}

describe('AgentTool::execute — read_agent_configuration', function (): void {
    test('returns the agent resource plus enabled_tools', function (): void {
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        $agent        = new Agent();
        $agent->id    = 7;
        $agent->name  = 'Alpha';
        $agent->notes = null;
        $service->allows('getAgentByAgentId')->andReturn($agent);
        $toolSettings->allows("getAllToolsStatus")->andReturn([
            ['tool_class' => 'Foo', 'tool_name' => 'foo', 'is_enabled' => true, 'can_enable' => true, 'missing_required' => []],
        ]);

        $result = $tool->execute(['action' => 'read_agent_configuration'], 7, 99);

        expect($result->success)->toBeTrue();
        /** @var array<string, mixed> $data */
        $data = $result->data;
        expect($data['id'])->toBe(7)
            ->and($data['name'])->toBe('Alpha')
            ->and($data['enabled_tools'])->toBe([]);
    });

    test('returns failure when Agent::find returns null', function (): void {
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        $service->allows('getAgentByAgentId')->andReturn(null);

        $result = $tool->execute(['action' => 'read_agent_configuration'], 999);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('not found');
    });
});

describe('AgentTool::execute — write_agent_configuration', function (): void {
    test('forwards patch through AgentServiceInterface::updateAgentByAgentId', function (): void {
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        $service->shouldReceive('updateAgentByAgentId')
            ->once()
            ->andReturn(stubAgent(7, 'Alpha', null));

        $result = $tool->execute(
            ['action' => 'write_agent_configuration', 'agent' => ['description' => 'updated']],
            7,
        );

        expect($result->success)->toBeTrue();
        /** @var array<string, mixed> $data */
        $data = $result->data;
        expect($data['name'])->toBe('Alpha');
    });

    test('silently drops `notes` from the patch (notes are write_notes-only)', function (): void {
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        $service->shouldReceive('updateAgentByAgentId')
            ->once()
            ->andReturn(stubAgent(7));

        $result = $tool->execute(
            [
                'action' => 'write_agent_configuration',
                'agent'  => ['description' => 'x', 'notes' => 'sneaky'],
            ],
            7,
        );

        expect($result->success)->toBeTrue();
    });

    test('returns failure when the agent object is missing', function (): void {
        [$tool] = makeAgentTool();

        $result = $tool->execute(['action' => 'write_agent_configuration'], 7);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('agent object is required');
    });
});

describe('AgentTool::execute — write_notes', function (): void {
    test('rejects missing content', function (): void {
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        $service->allows('getAgentByAgentId')->andReturn(null);

        $result = $tool->execute(['action' => 'write_notes'], 7);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('content is required');
    });

    test('rejects invalid mode', function (): void {
        [$tool] = makeAgentTool();

        $result = $tool->execute(
            ['action' => 'write_notes', 'content' => 'x', 'mode' => 'nuke'],
            7,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('invalid mode');
    });

    test('appends by default and persists via updateAgentByAgentId', function (): void {
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        $agent           = new Agent();
        $agent->id       = 7;
        $agent->user_id  = 99;
        $agent->name     = 'Alpha';
        $agent->notes    = 'pre-existing';
        $service->allows('getAgentByAgentId')->andReturn($agent);
        $service->shouldReceive('updateAgentByAgentId')
            ->once()
            ->with(7, ['notes' => "pre-existing\n\nnew content"])
            ->andReturn($agent);

        $result = $tool->execute(
            ['action' => 'write_notes', 'content' => 'new content'],
            7,
            99,
        );

        expect($result->success)->toBeTrue();
        /** @var array<string, mixed> $data */
        $data = $result->data;
        expect($data['mode'])->toBe('append')
            ->and($data['notes'])->toBe("pre-existing\n\nnew content")
            ->and($data['length'])->toBe(mb_strlen("pre-existing\n\nnew content"));
    });

    test('uses the literal mode when provided', function (): void {
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        $agent           = new Agent();
        $agent->id       = 7;
        $agent->user_id  = 99;
        $agent->name     = 'Alpha';
        $agent->notes    = 'existing';
        $service->allows('getAgentByAgentId')->andReturn($agent);
        $service->shouldReceive('updateAgentByAgentId')
            ->once()
            ->with(7, ['notes' => 'replacement'])
            ->andReturn($agent);

        $result = $tool->execute(
            ['action' => 'write_notes', 'content' => 'replacement', 'mode' => 'overwrite'],
            7,
            99,
        );

        expect($result->success)->toBeTrue();
        /** @var array<string, mixed> $data */
        $data = $result->data;
        expect($data['mode'])->toBe('overwrite');
    });

    test('returns failure when Agent::find returns null', function (): void {
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        $service->allows('getAgentByAgentId')->andReturn(null);

        $result = $tool->execute(
            ['action' => 'write_notes', 'content' => 'x'],
            7,
            99,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('Agent not found.');
    });
});

describe('AgentTool::execute — read_notes', function (): void {
    test('returns notes and length when the agent exists', function (): void {
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        $agent           = new Agent();
        $agent->id       = 7;
        $agent->user_id  = 99;
        $agent->name     = 'Alpha';
        $agent->notes    = '# runbook';
        $service->allows('getAgentByAgentId')->andReturn($agent);

        $result = $tool->execute(['action' => 'read_notes'], 7, 99);

        expect($result->success)->toBeTrue();
        /** @var array<string, mixed> $data */
        $data = $result->data;
        expect($data['notes'])->toBe('# runbook')
            ->and($data['length'])->toBe(9);
    });

    test('returns failure when Agent::find returns null', function (): void {
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        $service->allows('getAgentByAgentId')->andReturn(null);

        $result = $tool->execute(['action' => 'read_notes'], 7);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('Agent not found.');
    });
});

describe('AgentTool::execute — write_agent_configuration — happy path', function (): void {
    test('forwards patch and returns the updated resource', function (): void {
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        $agent           = new Agent();
        $agent->id       = 7;
        $agent->user_id  = 99;
        $agent->name     = 'Alpha';
        $agent->notes    = null;
        $service->allows('getAgentByAgentId')->andReturn($agent);
        $service->shouldReceive('updateAgentByAgentId')
            ->once()
            ->andReturn(stubAgent(7, 'Renamed'));

        $result = $tool->execute(
            ['action' => 'write_agent_configuration', 'agent' => ['name' => 'Renamed']],
            7,
            99,
        );

        expect($result->success)->toBeTrue();
        /** @var array<string, mixed> $data */
        $data = $result->data;
        expect($data['name'])->toBe('Renamed');
    });

    test('returns failure when the agent disappears mid-write', function (): void {
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        // updateAgentByAgentId returns null when the agent no longer exists,
        // which the tool surfaces as the standard AGENT_NOT_FOUND failure.
        $service->allows('updateAgentByAgentId')->andReturn(null);

        $result = $tool->execute(
            ['action' => 'write_agent_configuration', 'agent' => ['name' => 'x']],
            7,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('Agent not found.');
    });
});

describe('AgentTool::execute — get_available_tools', function (): void {
    test('enriches per-agent status with presenter metadata', function (): void {
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        $agent           = new Agent();
        $agent->id       = 7;
        $agent->user_id  = 99;
        $agent->name     = 'Alpha';
        $service->allows('getAgentByAgentId')->andReturn($agent);
        $toolSettings->allows("getAllToolsStatus")->andReturn([
            [
                'tool_class'       => 'Spora\\Tools\\CalculatorTool',
                'tool_name'        => 'calculator',
                'is_enabled'       => false,
                'can_enable'       => true,
                'missing_required' => [],
            ],
        ]);

        $result = $tool->execute(['action' => 'get_available_tools'], 7, 99);

        expect($result->success)->toBeTrue();
        /** @var list<array<string, mixed>> $rows */
        $rows = $result->data;
        $first = $rows[0];
        expect($first['tool_class'])->toBe('Spora\\Tools\\CalculatorTool')
            ->and($first['tool_name'])->toBe('calculator')
            ->and($first['is_enabled'])->toBeFalse()
            ->and($first['needs_configuration'])->toBeFalse();
    });

    test('flags needs_configuration when can_enable is false', function (): void {
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        $agent           = new Agent();
        $agent->id       = 7;
        $agent->user_id  = 99;
        $agent->name     = 'Alpha';
        $service->allows('getAgentByAgentId')->andReturn($agent);
        $toolSettings->allows("getAllToolsStatus")->andReturn([
            [
                'tool_class'       => 'Spora\\Tools\\ReadUrlTool',
                'tool_name'        => 'read_url',
                'is_enabled'       => false,
                'can_enable'       => false,
                'missing_required' => ['allowed_hosts'],
            ],
        ]);

        $result = $tool->execute(['action' => 'get_available_tools'], 7, 99);

        /** @var list<array<string, mixed>> $rows */
        $rows = $result->data;
        expect($rows[0]['needs_configuration'])->toBeTrue()
            ->and($rows[0]['missing_required'])->toBe(['allowed_hosts']);
    });
});

describe('AgentTool::execute — create_agent', function (): void {
    test('rejects when userId is null', function (): void {
        [$tool] = makeAgentTool();

        $result = $tool->execute(
            ['action' => 'create_agent', 'payload' => ['id' => 'x']],
            7,
            null,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('authenticated user');
    });

    test('rejects missing payload', function (): void {
        [$tool] = makeAgentTool();

        $result = $tool->execute(['action' => 'create_agent'], 7, 99);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('payload');
    });

    test('rejects invalid payload via validator', function (): void {
        [$tool] = makeAgentTool();

        // Top-level payload is present (so we reach the validator), but the
        // shape is missing required top-level keys (id, name, version).
        $out = $tool->execute(
            ['action' => 'create_agent', 'payload' => ['agent' => [], 'tools' => []]],
            7,
            99,
        );

        expect($out->success)->toBeFalse()
            ->and($out->content)->toContain('payload failed validation');
    });

    test('happy path: validates and imports a complete payload', function (): void {
        // Booting auth so the importer can resolve the agent's user_id FK
        // against the in-memory SQLite db seeded by Pest's beforeEach.
        $auth   = bootAuthLayer();
        $userId = bootAuth($auth, 'creator@example.com');
        [$tool] = makeAgentTool();

        $out = $tool->execute(
            [
                'action'  => 'create_agent',
                'payload' => [
                    'id'      => 'new-agent',
                    'name'    => 'New Agent',
                    'version' => '1.0.0',
                    'agent'   => ['description' => 'created via AgentTool'],
                    'tools'   => [],
                ],
            ],
            7,
            $userId,
        );

        expect($out->success)->toBeTrue();
        /** @var array<string, mixed> $data */
        $data = $out->data;
        /** @var array<string, mixed> $agentRow */
        $agentRow = $data['agent'];
        expect($agentRow['name'])->toBe('New Agent')
            ->and($agentRow['description'])->toBe('created via AgentTool')
            ->and($data['tools_enabled'])->toBe([]);
    });
});

describe('AgentTool::execute — unknown action', function (): void {
    test('returns failure', function (): void {
        [$tool] = makeAgentTool();

        $result = $tool->execute(['action' => 'teleport'], 7);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('Invalid action');
    });
});

describe('AgentTool::describeAction', function (): void {
    test('renders the mode for write_notes', function (): void {
        [$tool] = makeAgentTool();

        expect($tool->describeAction(['action' => 'write_notes']))
            ->toBe('Write notes on this agent (mode: append).');

        expect($tool->describeAction(['action' => 'write_notes', 'mode' => 'overwrite']))
            ->toBe('Write notes on this agent (mode: overwrite).');
    });
});
