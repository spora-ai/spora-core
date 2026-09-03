<?php

declare(strict_types=1);

use Psr\Log\LoggerInterface;
use Spora\Agents\ToolDefinitionBuilder;
use Spora\Tools\AbstractTool;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolParameter;

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
    ): Spora\Tools\ValueObjects\ToolResult {
        return new Spora\Tools\ValueObjects\ToolResult(true, 'noop');
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
    ): Spora\Tools\ValueObjects\ToolResult {
        return new Spora\Tools\ValueObjects\ToolResult(true, 'noop');
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
