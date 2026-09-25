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
