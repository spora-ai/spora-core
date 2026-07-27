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
            ->and($summary['operations'])->toBeArray();
    });

    test('returns a short-classname fallback when the class does not exist', function (): void {
        $summary = ToolSchemaPresenter::summarize('Spora\\Tools\\DoesNotExistTool');

        expect($summary['tool_class'])->toBe('Spora\\Tools\\DoesNotExistTool')
            ->and($summary['tool_name'])->toBe('DoesNotExistTool')
            ->and($summary['display_name'])->toBe('DoesNotExistTool')
            ->and($summary['category'])->toBe('general')
            ->and($summary['icon'])->toBeNull()
            ->and($summary['operations'])->toBe([]);
    });

    test('honours an explicit icon override passed in by the caller', function (): void {
        $summary = ToolSchemaPresenter::summarize(TimeTool::class, 'clock');

        expect($summary['icon'])->toBe('clock');
    });
});
