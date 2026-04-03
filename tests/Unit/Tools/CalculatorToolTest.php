<?php

declare(strict_types=1);

use Spora\Tools\CalculatorTool;

it('calculates a valid expression', function () {
    $tool = new CalculatorTool();
    $result = $tool->execute(['expression' => '10 + 20 * 2'], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Result of 10 + 20 * 2 = 50');
});

it('handles invalid math expressions gracefully', function () {
    $tool = new CalculatorTool();
    $result = $tool->execute(['expression' => '10 + abc'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Calculator error');
});

it('returns error on empty expression', function () {
    $tool = new CalculatorTool();
    $result = $tool->execute([], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Empty expression');
});

it('has the correct schema', function () {
    $tool = new CalculatorTool();
    $schema = $tool->getParametersSchema();

    expect($schema['type'])->toBe('object')
        ->and($schema['properties']['expression']['type'])->toBe('string')
        ->and($schema['required'])->toContain('expression');
});
