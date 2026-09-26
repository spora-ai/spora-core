<?php

declare(strict_types=1);

use Spora\Tools\TimeTool;
use Spora\Tools\ToolSchemaPresenter;

/**
 * ToolSchemaPresenter — shared reflection over `#[Tool]` / `#[ToolOperation]`.
 *
 * Tested independently of any service so the metadata extraction is
 * pinned without booting the agent harness. The `summarize()` entry shape
 * is the contract AgentTool and ToolController both consume.
 */
describe('ToolSchemaPresenter', function (): void {
    test('summarizes a tool that has #[Tool] and #[ToolOperation]', function (): void {
        $summary = ToolSchemaPresenter::summarize(TimeTool::class);

        expect($summary['tool_class'])->toBe(TimeTool::class)
            ->and($summary['tool_name'])->toBe('time')
            ->and($summary['display_name'])->toBe('Time')
            ->and($summary['category'])->toBe('productivity')
            ->and($summary['icon'])->toBeNull() // No resolver passed.
            ->and($summary['description'])->toBeString()
            ->and($summary['description'])->not->toBe('')
            ->and($summary['recommends_skills'])->toBe([]) // TimeTool declares none.
            ->and($summary['operations'])->toBeArray();
    });

    test('returns a short-classname fallback when the class does not exist', function (): void {
        $summary = ToolSchemaPresenter::summarize('Spora\\Tools\\DoesNotExistTool');

        expect($summary['tool_class'])->toBe('Spora\\Tools\\DoesNotExistTool')
            ->and($summary['tool_name'])->toBe('DoesNotExistTool')
            ->and($summary['display_name'])->toBe('DoesNotExistTool')
            ->and($summary['description'])->toBe('')
            ->and($summary['category'])->toBe('general')
            ->and($summary['icon'])->toBeNull()
            ->and($summary['recommends_skills'])->toBe([])
            ->and($summary['operations'])->toBe([]);
    });

    test('honours an explicit icon override passed in by the caller', function (): void {
        $summary = ToolSchemaPresenter::summarize(TimeTool::class, 'clock');

        expect($summary['icon'])->toBe('clock');
    });

    test('exposes recommendsSkills as the wire-format recommends_skills list', function (): void {
        // Synthetic tool class inline so the test pins the attribute → presenter
        // wiring without coupling to a real tool's product behaviour.
        $summary = ToolSchemaPresenter::summarize(SummaryFixtureSkillTool::class);

        expect($summary['recommends_skills'])->toBe(['time-arithmetic']);
    });

    test('falls back to the first sentence of description when operatorDescription is omitted', function (): void {
        // OperatorFixtureFallbackTool declares one op without operatorDescription.
        // The presenter should split its description on `. ` and return the head.
        $summary = ToolSchemaPresenter::summarize(OperatorFixtureFallbackTool::class);
        $ops = array_column($summary['operations'], null, 'name');

        expect($ops['has_fallback']['description'])
            ->toBe('Multi-sentence prose. The second sentence is for the LLM only.')
            ->and($ops['has_fallback']['operator_description'])
            ->toBe('Multi-sentence prose');
    });

    test('honours an explicit operatorDescription attribute', function (): void {
        $summary = ToolSchemaPresenter::summarize(OperatorFixtureExplicitTool::class);
        $ops = array_column($summary['operations'], null, 'name');

        expect($ops['has_explicit']['description'])
            ->toBe('Long LLM-facing prose with caveats. Operators do not need this.')
            ->and($ops['has_explicit']['operator_description'])
            ->toBe('One-line summary for the operator UI.');
    });

    test('returns the whole description when it has no sentence boundary', function (): void {
        $summary = ToolSchemaPresenter::summarize(OperatorFixtureSingleSentenceTool::class);
        $ops = array_column($summary['operations'], null, 'name');

        expect($ops['one_sentence']['operator_description'])
            ->toBe('Standalone sentence without a terminator break');
    });

    test('returns an empty operator_description when both inputs are blank', function (): void {
        $summary = ToolSchemaPresenter::summarize(OperatorFixtureBlankTool::class);
        $ops = array_column($summary['operations'], null, 'name');

        expect($ops['blank']['operator_description'])->toBe('');
    });
});

#[Spora\Tools\Attributes\Tool(
    name: 'summary_fixture_skill',
    description: 'Synthetic tool for the ToolSchemaPresenter fixture test.',
    recommendsSkills: ['time-arithmetic'],
)]
final class SummaryFixtureSkillTool
{
    public function name(): string
    {
        return 'summary_fixture_skill';
    }
}

#[Spora\Tools\Attributes\Tool(name: 'operator_fixture_fallback', description: 'Fixture covering the operator_description fallback path.')]
#[Spora\Tools\Attributes\ToolOperation(
    name: 'has_fallback',
    description: 'Multi-sentence prose. The second sentence is for the LLM only.',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
final class OperatorFixtureFallbackTool
{
    use Spora\Tools\Traits\HasOperations;

    public function name(): string
    {
        return 'operator_fixture_fallback';
    }
}

#[Spora\Tools\Attributes\Tool(name: 'operator_fixture_explicit', description: 'Fixture covering an explicit operatorDescription override.')]
#[Spora\Tools\Attributes\ToolOperation(
    name: 'has_explicit',
    description: 'Long LLM-facing prose with caveats. Operators do not need this.',
    operatorDescription: 'One-line summary for the operator UI.',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
final class OperatorFixtureExplicitTool
{
    use Spora\Tools\Traits\HasOperations;

    public function name(): string
    {
        return 'operator_fixture_explicit';
    }
}

#[Spora\Tools\Attributes\Tool(name: 'operator_fixture_single_sentence', description: 'Fixture covering a description without a sentence boundary.')]
#[Spora\Tools\Attributes\ToolOperation(
    name: 'one_sentence',
    description: 'Standalone sentence without a terminator break',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
final class OperatorFixtureSingleSentenceTool
{
    use Spora\Tools\Traits\HasOperations;

    public function name(): string
    {
        return 'operator_fixture_single_sentence';
    }
}

#[Spora\Tools\Attributes\Tool(name: 'operator_fixture_blank', description: 'Fixture covering an op without either description.')]
#[Spora\Tools\Attributes\ToolOperation(
    name: 'blank',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
final class OperatorFixtureBlankTool
{
    use Spora\Tools\Traits\HasOperations;

    public function name(): string
    {
        return 'operator_fixture_blank';
    }
}
