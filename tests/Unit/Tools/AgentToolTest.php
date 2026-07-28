<?php

declare(strict_types=1);

use Mockery\MockInterface;
use Spora\AgentTemplates\AgentTemplateImporter;
use Spora\AgentTemplates\AgentTemplateValidator;
use Spora\Models\Agent;
use Spora\Services\AgentServiceInterface;
use Spora\Services\AgentToolSettingsServiceInterface;
use Spora\Tools\AgentTool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Schema\OperationSchemaFilter;
use Spora\Tools\Schema\ToolParameterSchemaBuilder;

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

/**
 * Variant of makeAgentTool() that also stubs the optional PluginLoader and
 * ToolIconResolver collaborators. Used by get_available_tools tests that
 * exercise the plugin-qualified call_name and icon paths.
 *
 * PluginLoader is `final` and therefore cannot be mocked with Mockery, so
 * we hand back a real loader with a stub plugin injected via reflection —
 * the same pattern used by PluginLoaderTest (see
 * tests/Unit/Plugins/PluginLoaderTest.php:395-400).
 *
 * @return array{0: AgentTool, 1: AgentServiceInterface, 2: AgentToolSettingsServiceInterface, 3: Spora\Plugins\PluginLoader, 4: Spora\Services\ToolIconResolver}
 */
function makeAgentToolWithPlugins(): array
{
    $importer = new AgentTemplateImporter(
        new Spora\Services\ToolConfigService(
            new Spora\Core\SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
            new Psr\Log\NullLogger(),
        ),
        new Spora\Plugins\PluginLoader([], null),
        new Spora\Core\Paths(BASE_PATH),
    );
    $validator = new AgentTemplateValidator();
    /** @var AgentServiceInterface&MockInterface $service */
    $service = Mockery::mock(AgentServiceInterface::class);
    /** @var AgentToolSettingsServiceInterface&MockInterface $toolSettings */
    $toolSettings = Mockery::mock(AgentToolSettingsServiceInterface::class);
    // Real PluginLoader; tests inject stubs via reflection.
    $pluginLoader = new Spora\Plugins\PluginLoader([], null);
    /** @var Spora\Services\ToolIconResolver&MockInterface $iconResolver */
    $iconResolver = Mockery::mock(Spora\Services\ToolIconResolver::class);

    return [
        new AgentTool($service, $toolSettings, $importer, $validator, $pluginLoader, $iconResolver),
        $service,
        $toolSettings,
        $pluginLoader,
        $iconResolver,
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
        // `description` survives the strip so the service still gets called.
        $service->shouldReceive('updateAgentByAgentId')
            ->once()
            ->with(7, ['description' => 'x'])
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

    test('returns failure when the only field in the patch is notes', function (): void {
        // If the LLM only sends notes, the strip leaves the patch empty
        // and we surface a clear failure rather than silently reporting
        // success with no DB write. Operators can use write_notes for that.
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        $service->shouldNotReceive('updateAgentByAgentId');

        $result = $tool->execute(
            [
                'action' => 'write_agent_configuration',
                'agent'  => ['notes' => 'sneaky'],
            ],
            7,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('Use write_notes to mutate notes');
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
        // Agent existence is checked first, so the LLM sees "Agent not
        // found." rather than a content-shape complaint when both are wrong.
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        $service->allows('getAgentByAgentId')->andReturn(null);

        $result = $tool->execute(['action' => 'write_notes'], 7);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('Agent not found.');
    });

    test('rejects missing content when the agent exists', function (): void {
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        $agent = new Agent();
        $agent->id = 7;
        $agent->user_id = 99;
        $agent->name = 'Alpha';
        $agent->notes = null;
        $service->allows('getAgentByAgentId')->andReturn($agent);

        $result = $tool->execute(['action' => 'write_notes'], 7, 99);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('content is required');
    });

    test('rejects invalid mode', function (): void {
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        $agent = new Agent();
        $agent->id = 7;
        $agent->user_id = 99;
        $agent->name = 'Alpha';
        $agent->notes = null;
        $service->allows('getAgentByAgentId')->andReturn($agent);

        $result = $tool->execute(
            ['action' => 'write_notes', 'content' => 'x', 'mode' => 'nuke'],
            7,
            99,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('invalid mode');
    });

    test('rejects empty content with no-op return', function (): void {
        // Empty content on append/prepend must not pile up separator
        // characters across repeated LLM calls.
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        $agent           = new Agent();
        $agent->id       = 7;
        $agent->user_id  = 99;
        $agent->name     = 'Alpha';
        $agent->notes    = 'preserved';
        $service->allows('getAgentByAgentId')->andReturn($agent);
        // updateAgentByAgentId must NOT be called when content is empty.
        $service->shouldNotReceive('updateAgentByAgentId');

        $result = $tool->execute(
            ['action' => 'write_notes', 'content' => '', 'mode' => 'append'],
            7,
            99,
        );

        expect($result->success)->toBeTrue()
            ->and($result->data['mode'])->toBe('append')
            ->and($result->data['notes'])->toBe('preserved');
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

    test('prepends when mode=prepend is passed', function (): void {
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
            ->with(7, ['notes' => "new content\n\nexisting"])
            ->andReturn($agent);

        $result = $tool->execute(
            ['action' => 'write_notes', 'content' => 'new content', 'mode' => 'prepend'],
            7,
            99,
        );

        expect($result->success)->toBeTrue();
        /** @var array<string, mixed> $data */
        $data = $result->data;
        expect($data['mode'])->toBe('prepend')
            ->and($data['notes'])->toBe("new content\n\nexisting");
    });

    test('write_notes_overwrite replaces wholesale and returns mode=overwrite', function (): void {
        // The destructive overwrite path is a separate operation that
        // requires operator approval when enabled. Verify the body of
        // write_notes_overwrite: it discards the LLM's `mode` arg and
        // overwrites the agent's notes wholesale.
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
            [
                'action'  => 'write_notes_overwrite',
                'content' => 'replacement',
                'mode'    => 'append', // ignored — write_notes_overwrite forces overwrite
            ],
            7,
            99,
        );

        expect($result->success)->toBeTrue();
        /** @var array<string, mixed> $data */
        $data = $result->data;
        expect($data['mode'])->toBe('overwrite')
            ->and($data['notes'])->toBe('replacement');
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
    test('enriches per-agent status with presenter metadata and returns a versioned JSON payload as content', function (): void {
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
        $toolSettings->allows('getToolsOperations')->andReturn([]);

        $result = $tool->execute(['action' => 'get_available_tools'], 7, 99);

        expect($result->success)->toBeTrue();
        // `$data` keeps the legacy shape for the operator UI / audit log.
        /** @var list<array<string, mixed>> $rows */
        $rows = $result->data;
        $first = $rows[0];
        expect($first['tool_class'])->toBe('Spora\\Tools\\CalculatorTool')
            ->and($first['tool_name'])->toBe('calculator')
            ->and($first['is_enabled'])->toBeFalse()
            ->and($first['needs_configuration'])->toBeFalse();

        // `$content` is the new LLM-facing contract — a compact versioned
        // JSON payload, not a one-liner summary.
        $payload = json_decode($result->content, true, 512, JSON_THROW_ON_ERROR);
        expect($payload['version'])->toBe(1)
            ->and($payload['count'])->toBe(1);
        $tool = $payload['tools'][0];
        expect($tool['tool_class'])->toBe('Spora\\Tools\\CalculatorTool')
            ->and($tool['tool_name'])->toBe('calculator')
            ->and($tool['call_name'])->toBe('calculator')
            ->and($tool['description'])->toBeString()
            ->and($tool['enabled'])->toBeFalse()
            ->and($tool['ready_to_enable'])->toBeTrue()
            ->and($tool['missing_required'])->toBe([])
            ->and($tool['source']['kind'])->toBe('core')
            ->and($tool['source']['slug'])->toBeNull();
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
        $toolSettings->allows('getToolsOperations')->andReturn([]);

        $result = $tool->execute(['action' => 'get_available_tools'], 7, 99);

        /** @var list<array<string, mixed>> $rows */
        $rows = $result->data;
        expect($rows[0]['needs_configuration'])->toBeTrue()
            ->and($rows[0]['missing_required'])->toBe(['allowed_hosts']);

        $payload = json_decode($result->content, true, 512, JSON_THROW_ON_ERROR);
        expect($payload['tools'][0]['ready_to_enable'])->toBeFalse()
            ->and($payload['tools'][0]['missing_required'])->toBe(['allowed_hosts']);
    });

    test('qualifies call_name with the plugin slug for plugin-owned tools', function (): void {
        [$tool, $service, $toolSettings, $pluginLoader, $iconResolver] = makeAgentToolWithPlugins();
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        /** @var MockInterface $iconResolver */
        $agent           = new Agent();
        $agent->id       = 7;
        $agent->user_id  = 99;
        $agent->name     = 'Alpha';
        $service->allows('getAgentByAgentId')->andReturn($agent);
        // We use a real Spora core class as the target so the
        // `class-string<ToolInterface>` return type is satisfied, and the
        // plugin mapping is supplied by a stub plugin injected via
        // reflection (PluginLoader is final and cannot be mocked).
        $toolSettings->allows('getAllToolsStatus')->andReturn([
            [
                'tool_class'       => Spora\Tools\TimeTool::class,
                'tool_name'        => 'time',
                'is_enabled'       => false,
                'can_enable'       => true,
                'missing_required' => [],
            ],
        ]);
        $toolSettings->allows('getToolsOperations')->andReturn([]);

        $tavilyPlugin = new class extends Spora\Plugins\AbstractPlugin {
            public function getName(): string
            {
                return 'Tavily';
            }
            /**
             * @return list<class-string<Spora\Tools\ToolInterface>>
             */
            public function tools(): array
            {
                return [Spora\Tools\TimeTool::class];
            }
        };
        $ref = new ReflectionClass($pluginLoader);
        $pluginsProp = $ref->getProperty('plugins');
        $pluginsProp->setValue($pluginLoader, ['tavily' => $tavilyPlugin]);
        $manifestsProp = $ref->getProperty('pluginManifests');
        $manifestsProp->setValue($pluginLoader, ['tavily' => ['name' => 'Tavily', 'icon' => 'search']]);

        $iconResolver->allows('resolve')->andReturn('search');

        $result = $tool->execute(['action' => 'get_available_tools'], 7, 99);

        $payload = json_decode($result->content, true, 512, JSON_THROW_ON_ERROR);
        $row = $payload['tools'][0];
        expect($row['call_name'])->toBe('tavily:time')
            ->and($row['source']['kind'])->toBe('plugin')
            ->and($row['source']['slug'])->toBe('tavily')
            ->and($row['source']['name'])->toBe('Tavily');
    });

    test('renders per-operation enabled + requires_approval from effective state', function (): void {
        // Use AgentTool as the target: it ships 7 #[ToolOperation] entries
        // with a mix of enabledByDefault / requiresApprovalByDefault flags,
        // and `getToolsOperations` is easy to drive from a mock. The
        // presenter enumerates operations in declaration order, so we
        // expect `read_agent_configuration` first and `write_agent_configuration`
        // second in the response.
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        $agent           = new Agent();
        $agent->id       = 7;
        $agent->user_id  = 99;
        $agent->name     = 'Alpha';
        $service->allows('getAgentByAgentId')->andReturn($agent);
        $toolSettings->allows('getAllToolsStatus')->andReturn([
            [
                'tool_class'       => AgentTool::class,
                'tool_name'        => 'agent',
                'is_enabled'       => true,
                'can_enable'       => true,
                'missing_required' => [],
            ],
        ]);
        $toolSettings->allows('getToolsOperations')->andReturn([
            [
                'tool_class'                  => AgentTool::class,
                'operation'                   => 'read_agent_configuration',
                'effective_enabled'           => true,
                'effective_requires_approval' => false,
            ],
            [
                'tool_class'                  => AgentTool::class,
                'operation'                   => 'write_agent_configuration',
                'effective_enabled'           => false,
                'effective_requires_approval' => true,
            ],
        ]);

        $result = $tool->execute(['action' => 'get_available_tools'], 7, 99);

        $payload = json_decode($result->content, true, 512, JSON_THROW_ON_ERROR);
        $opsByName = [];
        foreach ($payload['tools'][0]['operations'] as $op) {
            if (in_array($op['name'], ['read_agent_configuration', 'write_agent_configuration'], true)) {
                $opsByName[$op['name']] = $op;
            }
        }
        expect($opsByName['read_agent_configuration']['enabled'])->toBeTrue()
            ->and($opsByName['read_agent_configuration']['requires_approval'])->toBeFalse();
        expect($opsByName['write_agent_configuration']['enabled'])->toBeFalse()
            ->and($opsByName['write_agent_configuration']['requires_approval'])->toBeTrue();
    });

    test('falls back to operation defaults when no per-agent override exists', function (): void {
        // `getToolsOperations` returns rows only for *enabled* tools, so a
        // not-yet-enabled tool will not appear in the effective map at all.
        // The presenter should fall back to the operation's
        // enabledByDefault / requiresApprovalByDefault from #[ToolOperation].
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        $agent           = new Agent();
        $agent->id       = 7;
        $agent->user_id  = 99;
        $agent->name     = 'Alpha';
        $service->allows('getAgentByAgentId')->andReturn($agent);
        $toolSettings->allows('getAllToolsStatus')->andReturn([
            [
                'tool_class'       => AgentTool::class,
                'tool_name'        => 'agent',
                'is_enabled'       => false,
                'can_enable'       => true,
                'missing_required' => [],
            ],
        ]);
        $toolSettings->allows('getToolsOperations')->andReturn([]);

        $result = $tool->execute(['action' => 'get_available_tools'], 7, 99);

        $payload = json_decode($result->content, true, 512, JSON_THROW_ON_ERROR);
        $opsByName = [];
        foreach ($payload['tools'][0]['operations'] as $op) {
            $opsByName[$op['name']] = $op;
        }
        // read_agent_configuration: enabledByDefault=true, requiresApprovalByDefault=false
        expect($opsByName['read_agent_configuration']['enabled'])->toBeTrue()
            ->and($opsByName['read_agent_configuration']['requires_approval'])->toBeFalse();
        // write_agent_configuration: enabledByDefault=false, requiresApprovalByDefault=true
        expect($opsByName['write_agent_configuration']['enabled'])->toBeFalse()
            ->and($opsByName['write_agent_configuration']['requires_approval'])->toBeTrue();
    });

    test('returns failure when the agent does not exist', function (): void {
        [$tool, $service] = makeAgentTool();
        /** @var MockInterface $service */
        $service->allows('getAgentByAgentId')->andReturn(null);

        $result = $tool->execute(['action' => 'get_available_tools'], 7, 99);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('not found');
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

        // Shape is missing the required top-level keys (id, name, version)
        // so the validator fails before any DB write.
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
                    'agent'   => [
                        'description' => 'created via AgentTool',
                        'notes'       => 'runbook step 1',
                    ],
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
            ->and($agentRow['notes'])->toBe('runbook step 1')
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

        expect($tool->describeAction(['action' => 'write_notes', 'mode' => 'prepend']))
            ->toBe('Write notes on this agent (mode: prepend).');
    });

    test('renders the destructive path for write_notes_overwrite', function (): void {
        [$tool] = makeAgentTool();

        expect($tool->describeAction(['action' => 'write_notes_overwrite']))
            ->toBe("Replace the agent's notes wholesale (destructive).");
    });

    test('renders the entry count for configure_tools', function (): void {
        [$tool] = makeAgentTool();

        expect($tool->describeAction(['action' => 'configure_tools']))
            ->toBe("Configure this agent's toolset (0 entries).");

        expect($tool->describeAction([
            'action' => 'configure_tools',
            'tools'  => [
                ['tool_class' => 'A', 'enabled' => true],
                ['tool_class' => 'B', 'enabled' => false],
            ],
        ]))->toBe("Configure this agent's toolset (2 entries).");
    });

    test('renders the identifier for read_agent', function (): void {
        [$tool] = makeAgentTool();

        expect($tool->describeAction(['action' => 'read_agent']))
            ->toBe('Read agent state (no identifier).');
        expect($tool->describeAction(['action' => 'read_agent', 'template_id' => 'core/x']))
            ->toBe('Read agent state (template_id: core/x).');
        expect($tool->describeAction(['action' => 'read_agent', 'agent_id' => 42]))
            ->toBe('Read agent state (agent_id: 42).');
    });
});

describe('AgentTool::execute — configure_tools', function (): void {
    test('rejects when userId is null', function (): void {
        [$tool] = makeAgentTool();

        $result = $tool->execute(
            ['action' => 'configure_tools', 'tools' => []],
            7,
            null,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('authenticated user');
    });

    test('rejects a non-array `tools` argument', function (): void {
        [$tool] = makeAgentTool();

        $result = $tool->execute(
            ['action' => 'configure_tools', 'tools' => 'not-an-array'],
            7,
            99,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('must be an array');
    });

    test('rejects a tool entry missing tool_class', function (): void {
        [$tool] = makeAgentTool();

        $result = $tool->execute(
            ['action' => 'configure_tools', 'tools' => [['enabled' => true]]],
            7,
            99,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('missing `tool_class`');
    });

    test('rejects a malformed operations entry', function (): void {
        [$tool] = makeAgentTool();

        $result = $tool->execute(
            [
                'action' => 'configure_tools',
                'tools'  => [[
                    'tool_class' => 'Spora\\Tools\\TimeTool',
                    'enabled'    => true,
                    'operations' => [['enabled' => true]],
                ]],
            ],
            7,
            99,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('operations[0][0]');
    });

    test('enables a tool on the calling agent and returns the readback', function (): void {
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        $agent        = new Agent();
        $agent->id    = 7;
        $agent->name  = 'Alpha';
        $agent->user_id = 99;
        $service->allows('getAgentByAgentId')->andReturn($agent);
        $toolSettings->shouldReceive('enableTool')
            ->once()
            ->with(7, 99, 'Spora\\Tools\\TimeTool')
            ->andReturn(['tool' => ['tool_class' => 'Spora\\Tools\\TimeTool', 'tool_name' => 'time']]);
        $toolSettings->shouldNotReceive('disableTool');
        // getAvailableTools readback:
        $toolSettings->allows('getAllToolsStatus')->andReturn([
            [
                'tool_class'       => 'Spora\\Tools\\TimeTool',
                'tool_name'        => 'time',
                'is_enabled'       => true,
                'can_enable'       => true,
                'missing_required' => [],
            ],
        ]);
        $toolSettings->allows('getToolsOperations')->andReturn([]);

        $result = $tool->execute(
            [
                'action' => 'configure_tools',
                'tools'  => [['tool_class' => 'Spora\\Tools\\TimeTool', 'enabled' => true, 'operations' => []]],
            ],
            7,
            99,
        );

        expect($result->success)->toBeTrue();
    });

    test('with enabled false removes the tool', function (): void {
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        $agent        = new Agent();
        $agent->id    = 7;
        $agent->name  = 'Alpha';
        $agent->user_id = 99;
        $service->allows('getAgentByAgentId')->andReturn($agent);
        $toolSettings->shouldReceive('disableTool')
            ->once()
            ->with(7, 99, 'Spora\\Tools\\TimeTool');
        $toolSettings->shouldNotReceive('enableTool');
        $toolSettings->allows('getAllToolsStatus')->andReturn([]);
        $toolSettings->allows('getToolsOperations')->andReturn([]);

        $result = $tool->execute(
            [
                'action' => 'configure_tools',
                'tools'  => [['tool_class' => 'Spora\\Tools\\TimeTool', 'enabled' => false]],
            ],
            7,
            99,
        );

        expect($result->success)->toBeTrue();
    });

    test('sets per-operation auto_approve overrides via patchOperationOverride', function (): void {
        [$tool, $service, $toolSettings] = makeAgentTool();
        /** @var MockInterface $toolSettings */
        /** @var MockInterface $service */
        $agent        = new Agent();
        $agent->id    = 7;
        $agent->name  = 'Alpha';
        $agent->user_id = 99;
        $service->allows('getAgentByAgentId')->andReturn($agent);
        $toolSettings->allows('enableTool')->andReturn(['tool' => ['tool_class' => 'X', 'tool_name' => 'x']]);
        // auto_approve=true → default_requires_approval=0
        $toolSettings->shouldReceive('patchOperationOverride')
            ->once()
            ->with(7, 99, 'Spora\\Tools\\TimeTool', 'now', [
                'enabled'                   => 1,
                'default_requires_approval' => 0,
            ])
            ->andReturn([]);
        $toolSettings->allows('getAllToolsStatus')->andReturn([]);
        $toolSettings->allows('getToolsOperations')->andReturn([]);

        $result = $tool->execute(
            [
                'action' => 'configure_tools',
                'tools'  => [[
                    'tool_class' => 'Spora\\Tools\\TimeTool',
                    'enabled'    => true,
                    'operations' => [['name' => 'now', 'auto_approve' => true]],
                ]],
            ],
            7,
            99,
        );

        expect($result->success)->toBeTrue();
    });
});

describe('AgentTool::execute — read_agent', function (): void {
    test('rejects when userId is null', function (): void {
        [$tool] = makeAgentTool();

        $result = $tool->execute(
            ['action' => 'read_agent', 'agent_id' => 7],
            7,
            null,
        );

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('authenticated user');
    });

    test('rejects when neither template_id nor agent_id is provided', function (): void {
        [$tool] = makeAgentTool();

        $result = $tool->execute(['action' => 'read_agent'], 7, 99);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('either `template_id` or `agent_id` is required');
    });

    test('returns the full config by agent_id', function (): void {
        // Real DB so the Agent::query() lookup in resolveReadAgentTarget
        // has something to find. Boot auth + create the agent row.
        $auth    = bootAuthLayer();
        $ownerId = bootAuth($auth, 'read-agent-owner@example.com');

        [$tool] = makeAgentTool();

        $agentId = (int) Illuminate\Database\Capsule\Manager::table('agents')->insertGetId([
            'user_id'              => $ownerId,
            'name'                 => 'Alpha',
            'description'          => null,
            'system_prompt'        => null,
            'notes'                => null,
            'max_steps'            => 10,
            'allow_followup'       => 1,
            'retry_after_minutes'  => 0,
            'max_retries'          => 0,
            'is_active'            => 1,
            'created_at'           => date('Y-m-d H:i:s'),
            'updated_at'           => date('Y-m-d H:i:s'),
        ]);

        $result = $tool->execute(['action' => 'read_agent', 'agent_id' => $agentId], 7, $ownerId);

        expect($result->success)->toBeTrue();
        /** @var array<string, mixed> $data */
        $data = $result->data;
        expect($data['id'])->toBe($agentId)
            ->and($data['name'])->toBe('Alpha')
            ->and($data)->toHaveKey('enabled_tools');
    });

    test('does not return another user\'s agent', function (): void {
        $auth     = bootAuthLayer();
        $ownerId  = bootAuth($auth, 'read-agent-cross-owner@example.com');
        $otherId  = bootAuth($auth, 'read-agent-cross-other@example.com');

        [$tool] = makeAgentTool();

        $agentId = (int) Illuminate\Database\Capsule\Manager::table('agents')->insertGetId([
            'user_id'              => $ownerId,
            'name'                 => 'Owned',
            'description'          => null,
            'system_prompt'        => null,
            'notes'                => null,
            'max_steps'            => 10,
            'allow_followup'       => 1,
            'retry_after_minutes'  => 0,
            'max_retries'          => 0,
            'is_active'            => 1,
            'created_at'           => date('Y-m-d H:i:s'),
            'updated_at'           => date('Y-m-d H:i:s'),
        ]);

        // The cross-user read returns "Agent not found or not owned by this user."
        // — never the underlying agent's payload.
        $result = $tool->execute(['action' => 'read_agent', 'agent_id' => $agentId], 7, $otherId);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('not found or not owned');
    });
});

test('create_agent validation error references the agent-creation skill', function (): void {
    // The create_agent failure path appends a single-line pointer at the
    // agent-creation skill so the LLM knows where to find the schema on the
    // next attempt instead of retrying the same broken payload.
    [$tool] = makeAgentTool();

    // Deliberately broken: `version` is an int (must be a non-empty string),
    // and `name` is placed inside `agent{}` (top-level only).
    $result = $tool->execute(
        [
            'action'  => 'create_agent',
            'payload' => [
                'id'      => 'broken-agent',
                'name'    => 'Broken Agent',
                'version' => 1,
                'agent'   => ['name' => 'wrong-location'],
                'tools'   => [],
            ],
        ],
        7,
        99,
    );

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('payload failed validation')
        ->and($result->content)->toContain('agent-creation')
        ->and($result->content)->toContain('skill action: read');
});

test('AgentTool operation descriptions mention the agent-creation skill on relevant operations', function (): void {
    // The skill pointer is only useful if it reaches the LLM-facing schema.
    // Reflect over the class-level #[ToolOperation] attributes and assert
    // each operation that the skill covers carries a pointer to it.
    $reflection = new ReflectionClass(AgentTool::class);
    $byName = [];
    foreach ($reflection->getAttributes(ToolOperation::class) as $attr) {
        /** @var ToolOperation $op */
        $op = $attr->newInstance();
        $byName[$op->name] = $op->description;
    }

    expect($byName)->toHaveKey('write_agent_configuration')
        ->and($byName)->toHaveKey('create_agent')
        ->and($byName)->toHaveKey('get_available_tools')
        ->and($byName['write_agent_configuration'])->toContain('agent-creation')
        ->and($byName['create_agent'])->toContain('agent-creation')
        ->and($byName['get_available_tools'])->toContain('agent-creation');
});

test('AgentTool schema narrows action-required params per operation', function (): void {
    // The four #[ToolParameter] declarations each carry a `required: [...]`
    // list of operation names. OperationSchemaFilter narrows the schema's
    // `required[]` to the operations the agent can actually invoke, and
    // strips the side channel before the schema reaches the LLM.
    $schema = ToolParameterSchemaBuilder::build(AgentTool::class);

    // create_agent only → payload is required, agent is not.
    $createOnly = OperationSchemaFilter::filter($schema, ['create_agent'], 'action');
    expect($createOnly['required'])->toContain('payload')
        ->and($createOnly['required'])->not->toContain('agent')
        ->and($createOnly)->not->toHaveKey('__required_when');

    // write_agent_configuration only → agent is required, payload is not.
    $writeOnly = OperationSchemaFilter::filter($schema, ['write_agent_configuration'], 'action');
    expect($writeOnly['required'])->toContain('agent')
        ->and($writeOnly['required'])->not->toContain('payload');
});

test('write_agent_configuration silently drops unknown keys (confirmed via read_agent_configuration)', function (): void {
    // Two-part pin: (1) the AgentTool layer forwards `notes` stripping
    // (`unset($patch['notes'])` in writeConfiguration), and (2) the
    // resulting read_agent_configuration response is the canonical
    // EDITABLE_AGENT_FIELDS allowlist — unknown keys never surface back.
    // Covers the "notes vs. config" gotcha called out in the
    // agent-creation skill's `write_agent_configuration` workflow section.
    [$tool, $service, $toolSettings] = makeAgentTool();
    /** @var MockInterface $toolSettings */
    /** @var MockInterface $service */

    // AgentTool strips `notes` defensively; `name` and `system_prompt`
    // pass through; `foo` and `enable_tools` flow through to the service
    // (which is what actually drops them against the allowlist).
    $agent = new Agent();
    $agent->id = 7;
    $agent->user_id = 99;
    $agent->name = 'New Name';
    $agent->notes = 'pre-existing notes';
    $agent->system_prompt = 'updated';

    $service->shouldReceive('updateAgentByAgentId')
        ->once()
        ->with(
            7,
            Mockery::on(static function (array $patch): bool {
                // `notes` is stripped by the tool; the rest passes through.
                // The AgentService then enforces the EDITABLE_AGENT_FIELDS
                // allowlist, so the unknown keys are dropped downstream.
                return !array_key_exists('notes', $patch)
                    && ($patch['name'] ?? null) === 'New Name'
                    && ($patch['system_prompt'] ?? null) === 'updated';
            }),
        )
        ->andReturn($agent);
    $service->allows('getAgentByAgentId')->andReturn($agent);

    $writeResult = $tool->execute(
        [
            'action' => 'write_agent_configuration',
            'agent'  => [
                'name'         => 'New Name',
                'system_prompt' => 'updated',
                'foo'          => 'bar',
                'enable_tools' => ['time'],
                'notes'        => 'should be stripped',
            ],
        ],
        7,
        99,
    );

    expect($writeResult->success)->toBeTrue();

    // Confirm via read_agent_configuration: AgentResource only emits the
    // canonical allowlist, so unknown keys never appear in the LLM's
    // confirmation loop. The `notes` strip also held — the field on the
    // returned resource is the pre-existing value, not the sneaky patch.
    $readResult = $tool->execute(['action' => 'read_agent_configuration'], 7, 99);
    expect($readResult->success)->toBeTrue();
    /** @var array<string, mixed> $readData */
    $readData = $readResult->data;
    expect($readData)->not->toHaveKey('foo')
        ->and($readData)->not->toHaveKey('enable_tools')
        ->and($readData['name'])->toBe('New Name')
        ->and($readData['system_prompt'])->toBe('updated')
        ->and($readData['notes'])->toBe('pre-existing notes');
});
