<?php

declare(strict_types=1);

namespace Spora\Tools;

use ChrisKonnertz\StringCalc\StringCalc;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\ValueObjects\ToolResult;
use Throwable;

#[Tool(
    name: 'calculator',
    description: 'Evaluates a mathematical expression safely. Use this to calculate exact results. Supports standard arithmetic operators (+, -, *, /) and parentheses.',
)]
#[ToolParameter(
    name: 'expression',
    type: 'string',
    description: 'The mathematical expression to evaluate (e.g. "100 * 2.5 + 50").',
    required: true,
)]
final class CalculatorTool implements InputToolInterface
{
    public function execute(array $arguments, int $agentId): ToolResult
    {
        $expression = (string) ($arguments['expression'] ?? '');

        if (trim($expression) === '') {
            return new ToolResult(false, 'Error: Empty expression provided.');
        }

        try {
            $stringCalc = new StringCalc();
            $result     = $stringCalc->calculate($expression);

            return new ToolResult(true, "Result of {$expression} = {$result}");
        } catch (Throwable $e) {
            return new ToolResult(false, 'Calculator error: ' . $e->getMessage());
        }
    }

    public function getParametersSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'expression' => [
                    'type'        => 'string',
                    'description' => 'The mathematical expression to evaluate (e.g. "100 * 2.5 + 50").',
                ],
            ],
            'required' => ['expression'],
        ];
    }
}
