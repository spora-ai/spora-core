<?php

declare(strict_types=1);

use Spora\Services\Exceptions\PromptExpansionTooLargeException;
use Spora\Services\PromptTemplateExpander;

it('substitutes a stored default for its placeholder', function (): void {
    $expander = new PromptTemplateExpander();

    expect($expander->expand('Hello {{name}}!', [
        ['key' => 'name', 'default_value' => 'World'],
    ]))->toBe('Hello World!');
});

it('keeps an unknown placeholder as written', function (): void {
    $expander = new PromptTemplateExpander();

    expect($expander->expand('Hello {{name}}!', []))->toBe('Hello {{name}}!');
});

it('refuses an expansion that repeats one default past the ceiling', function (): void {
    $expander = new PromptTemplateExpander();

    // Each occurrence copies the default in full, so the expanded string
    // grows as the product of the two. Without a ceiling this is a
    // multi-gigabyte allocation in the request process.
    $template = str_repeat('{{blob}}', 4_000);
    $default  = str_repeat('x', 200_000);

    expect(static fn() => $expander->expand($template, [
        ['key' => 'blob', 'default_value' => $default],
    ]))->toThrow(PromptExpansionTooLargeException::class);
});

it('refuses a template that is already over the ceiling', function (): void {
    $expander = new PromptTemplateExpander();

    $template = str_repeat('x', PromptTemplateExpander::MAX_EXPANDED_BYTES + 1);

    expect(static fn() => $expander->expand($template, []))
        ->toThrow(PromptExpansionTooLargeException::class);
});

it('accepts an expansion that stays under the ceiling', function (): void {
    $expander = new PromptTemplateExpander();

    $template = str_repeat('{{blob}}', 4);
    $default  = str_repeat('x', 1_000);

    expect(strlen($expander->expand($template, [
        ['key' => 'blob', 'default_value' => $default],
    ])))->toBe(4_000);
});
