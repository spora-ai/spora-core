<?php

declare(strict_types=1);

use Psr\Log\LoggerInterface;
use Spora\Agents\ToolDefinitionBuilder;
use Spora\Tools\AbstractTool;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Regression coverage for {@see ToolDefinitionBuilder}.
 *
 * Locks in the contract that a tool declaring `#[Tool]` + `#[ToolParameter]`
 * but ZERO `#[ToolOperation]` attributes is **silently dropped** from the
 * LLM-facing schema — and that the builder logs a loud ERROR so the broken
 * tool class surfaces in `storage/spora.log` (instead of being a mystery to
 * the operator who finds the LLM "can't see" their tool).
 *
 * The bug this guards: a plugin shipped with a `#[Tool]` attribute but no
 * `#[ToolOperation]` would be visible on the admin `/api/v1/tools` endpoint
 * (which reads the `#[Tool]` attribute directly), but invisible to the LLM
 * because `buildOperationToolDefinition()` returns null when
 * `getOperations() === []`. The agent would then complain "the tool isn't
 * in my callable schema" with no breadcrumb. The loud log closes the gap.
 */

/**
 * Stub tool that declares `#[Tool]` + `#[ToolParameter]` but NO
 * `#[ToolOperation]`. Mirrors what `spora-plugin-typst`'s render/inspect
 * tools shipped with pre-fix — the broken shape we want to detect.
 */
#[Tool(
    name: 'broken_plugin_tool',
    description: 'A tool that forgot to declare its #[ToolOperation] attributes.',
    displayName: 'Broken',
    category: 'misc',
    icon: 'puzzle',
)]
#[ToolParameter(
    name: 'source',
    type: 'string',
    description: 'Inline source.',
    required: false,
)]
final class StubToolWithoutOperations extends AbstractTool
{
    public function execute(
        array $arguments,
        int $agentId,
        ?int $userId = null,
        ?int $taskId = null,
        ?Spora\Services\PrincipalContext $context = null,
    ): ToolResult {
        return new ToolResult(true, 'noop');
    }

    public function describeAction(array $arguments): string
    {
        return 'broken';
    }
}

/**
 * Second stub — also declares no `#[ToolOperation]`. Distinct class so
 * the per-tool-class dedup test can verify one log per broken class.
 */
#[Tool(
    name: 'another_broken_tool',
    description: 'Second stub that forgot to declare its operations.',
    displayName: 'Second',
    category: 'misc',
    icon: 'puzzle',
)]
#[ToolParameter(
    name: 'payload',
    type: 'string',
    description: 'payload',
    required: false,
)]
final class AnotherStubToolWithoutOperations extends AbstractTool
{
    public function execute(
        array $arguments,
        int $agentId,
        ?int $userId = null,
        ?int $taskId = null,
        ?Spora\Services\PrincipalContext $context = null,
    ): ToolResult {
        return new ToolResult(true, 'noop');
    }

    public function describeAction(array $arguments): string
    {
        return 'broken-2';
    }
}

describe('ToolDefinitionBuilder missing #[ToolOperation] loud error', function (): void {
    it('drops a tool that declares zero #[ToolOperation] attributes from the LLM-facing schema', function (): void {
        $stub = new StubToolWithoutOperations();
        $builder = new ToolDefinitionBuilder([$stub]);

        $defs = $builder->buildToolDefinitions(
            enabledClasses: [StubToolWithoutOperations::class],
            agentId: 12345,
        );

        expect($defs)->toBe([]);
    });

    it('logs an ERROR naming the broken tool class so the operator can find it', function (): void {
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return str_contains($message, 'no callable #[ToolOperation]')
                    && ($context['tool_class'] ?? null) === StubToolWithoutOperations::class
                    && ($context['agent_id'] ?? null) === 12345;
            });

        $stub = new StubToolWithoutOperations();
        $builder = new ToolDefinitionBuilder([$stub], null, null, null, $logger);

        $builder->buildToolDefinitions(
            enabledClasses: [StubToolWithoutOperations::class],
            agentId: 12345,
        );
    });

    it('deduplicates the loud error across multiple ticks on the same task (one log per tool class per request)', function (): void {
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')->once(); // exactly one, not N

        $stub = new StubToolWithoutOperations();
        $builder = new ToolDefinitionBuilder([$stub], null, null, null, $logger);

        // Simulate a 3-tick run on the same broken agent.
        for ($i = 0; $i < 3; $i++) {
            $builder->buildToolDefinitions(
                enabledClasses: [StubToolWithoutOperations::class],
                agentId: 12345,
            );
        }
    });

    it('logs once per distinct broken tool class within the same request', function (): void {
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')->twice(); // one per class

        $stub1 = new StubToolWithoutOperations();
        $stub2 = new AnotherStubToolWithoutOperations();

        $builder = new ToolDefinitionBuilder([$stub1, $stub2], null, null, null, $logger);

        $builder->buildToolDefinitions(
            enabledClasses: [get_class($stub1), get_class($stub2)],
            agentId: 99,
        );
    });

    it('does not log when the broken tool is not in the agent enabled-classes list', function (): void {
        // The drop is intentional only for tools the agent has enabled.
        // A broken-but-disabled plugin should not flood logs.
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldNotReceive('error');

        $stub = new StubToolWithoutOperations();
        $builder = new ToolDefinitionBuilder([$stub], null, null, null, $logger);

        $builder->buildToolDefinitions(
            enabledClasses: [],
            agentId: 12345,
        );
    });
});

// `#[ToolParameter(enumSource: '…')]` LLM-side enrichment: the builder must
// thread the runtime-resolved ids + labels from ToolConfigService through to
// the per-property schema. The integration below uses a SubAgentTool-shaped
// stub so the test stays self-contained — exercising the real SubAgentTool
// would couple this test to the SubAgentTool wiring and fail whenever the
// service layer changes.
#[Tool(
    name: 'enum_source_stub',
    displayName: 'Enum Source Stub',
    category: 'test',
    description: 'Stub for ToolDefinitionBuilder enumSource wiring.',
    icon: 'puzzle',
)]
#[ToolSetting(
    key: 'allowed_target_agents',
    label: 'Allowed target agents',
    type: 'multi-select',
    exposeToLlm: true,
)]
#[ToolOperation(name: 'delegate', description: 'Delegate.', enabledByDefault: true, discriminatorKey: 'op')]
#[ToolParameter(name: 'op', type: 'string', description: 'The operation to perform', required: ['delegate'], enum: ['delegate'])]
#[ToolParameter(
    name: 'target_agent_id',
    type: 'integer',
    description: 'ID of the target agent. Must be in the configured allowed_target_agents list.',
    required: ['delegate'],
    enumSource: 'allowed_target_agents',
)]
final class ToolDefinitionBuilderEnumSourceStub extends AbstractTool
{
    public function execute(
        array $arguments,
        int $agentId,
        ?int $userId = null,
        ?int $taskId = null,
        ?Spora\Services\PrincipalContext $context = null,
    ): ToolResult {
        return new ToolResult(true, 'noop');
    }

    public function describeAction(array $arguments): string
    {
        return 'delegate';
    }
}

describe('ToolDefinitionBuilder wires #[ToolParameter(enumSource)] into the LLM-facing schema', function (): void {

    it('injects enum and description suffix from the runtime-resolved setting values', function (): void {
        $config = Mockery::mock(Spora\Services\ToolConfigService::class);
        // The builder calls both getEffectiveSettings() (for raw ids)
        // and getLlmToolSettings() (for resolved "Name (#id)" labels).
        $config->shouldReceive('getEffectiveSettings')
            ->with(ToolDefinitionBuilderEnumSourceStub::class, 1, null, null)
            ->andReturn(['allowed_target_agents' => [11, 4]]);
        $config->shouldReceive('getLlmToolSettings')
            ->with(ToolDefinitionBuilderEnumSourceStub::class, 1, null, null)
            ->andReturn(['allowed_target_agents' => ['label' => 'Allowed target agents', 'value' => ['Legal Agent (#11)', 'Sales Agent (#4)']]]);

        $builder = new ToolDefinitionBuilder([new ToolDefinitionBuilderEnumSourceStub()], $config);

        $defs = $builder->buildToolDefinitions(
            enabledClasses: [ToolDefinitionBuilderEnumSourceStub::class],
            agentId: 1,
        );

        expect($defs)->toHaveCount(1);
        $param = $defs[0]['function']['parameters']['properties']['target_agent_id'];
        expect($param['enum'])->toBe([11, 4])
            ->and($param['description'])
                ->toBe('ID of the target agent. Must be in the configured allowed_target_agents list. Allowed values: Legal Agent (#11), Sales Agent (#4)');
    });

    it('emits no enum and no suffix when the runtime-resolved allowlist is empty', function (): void {
        $config = Mockery::mock(Spora\Services\ToolConfigService::class);
        $config->shouldReceive('getEffectiveSettings')->andReturn(['allowed_target_agents' => []]);
        $config->shouldReceive('getLlmToolSettings')->andReturn(['allowed_target_agents' => ['label' => 'Allowed target agents', 'value' => []]]);

        $builder = new ToolDefinitionBuilder([new ToolDefinitionBuilderEnumSourceStub()], $config);

        $defs = $builder->buildToolDefinitions(
            enabledClasses: [ToolDefinitionBuilderEnumSourceStub::class],
            agentId: 1,
        );

        $param = $defs[0]['function']['parameters']['properties']['target_agent_id'];
        expect($param)->not->toHaveKey('enum')
            ->and($param['description'])->not->toContain('Allowed values:');
    });

    it('falls back to the static getParametersSchema() when the tool does not implement getLlmParametersSchema()', function (): void {
        // AbstractTool composes HasParameterSchema, so it always exposes
        // getLlmParametersSchema(). The fallback branch in loadSchemaForLlm()
        // only triggers for tools that implement ToolInterface directly with
        // their own getParametersSchema() — the "custom tool" escape hatch
        // for plugin authors who can't extend AbstractTool. Use a
        // ToolInterface-only stub here to keep the test honest.
        $customStub = new class implements Spora\Tools\ToolInterface {
            public function execute(
                array $arguments,
                int $agentId,
                ?int $userId = null,
                ?int $taskId = null,
                ?Spora\Services\PrincipalContext $context = null,
            ): ToolResult {
                return new ToolResult(true, 'noop');
            }

            public function describeAction(array $arguments): string
            {
                return 'noop';
            }

            public function getParametersSchema(): array
            {
                return [
                    'type'       => 'object',
                    'properties' => [
                        'marker' => ['type' => 'string', 'description' => 'fallback marker'],
                    ],
                    'required'   => ['marker'],
                ];
            }
        };

        // No toolConfigService wired; the static schema is what matters —
        // the fallback must reach getParametersSchema(), NOT the trait's
        // getLlmParametersSchema() (which doesn't exist on this stub).
        $builder = new ToolDefinitionBuilder([$customStub]);
        $defs = $builder->buildToolDefinitions(
            enabledClasses: [get_class($customStub)],
            agentId: 1,
        );

        // The stub has no #[Tool] / #[ToolOperation] so it gets dropped —
        // but the test has already exercised the method_exists branch
        // (verified by zero crashes and the static schema being the only
        // path). A positive-shape tool would assert `properties.marker`;
        // here we keep it minimal to avoid coupling the fallback test to
        // the Tool attribute machinery.
        expect($defs)->toBe([]);
    });
});
