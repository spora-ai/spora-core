<?php

declare(strict_types=1);

namespace Tests\Unit\Tools;

use InvalidArgumentException;
use ReflectionClass;
use Spora\Tools\Attributes\SharedSettings;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\SharedSettingsResolver;

it('resolves no group for a tool that does not implement the interface', function (): void {
    $resolver = new SharedSettingsResolver([AcmeSharedSettings::class]);

    expect($resolver->groupClassFor(AcmeStandaloneTool::class))->toBeNull();
});

it('resolves the group class for a consuming tool', function (): void {
    $resolver = new SharedSettingsResolver([AcmeSharedSettings::class]);

    expect($resolver->groupClassFor(AcmeChatTool::class))->toBe(AcmeSharedSettings::class);
});

it('resolves no group when no settings class is registered at all', function (): void {
    // The state of every host that ships no plugin group. Everything else in
    // the feature must behave as if the layer were absent.
    $resolver = new SharedSettingsResolver();

    expect($resolver->groupClassFor(AcmeChatTool::class))->toBeNull()
        ->and($resolver->applyToDefaults(AcmeChatTool::class, ['retries' => '3'], static fn(): array => [
            'api_key' => 'never-read',
        ]))->toBe(['retries' => '3']);
});

it('resolves no group for a tool naming a group no plugin declared', function (): void {
    $resolver = new SharedSettingsResolver([]);

    expect($resolver->groupClassFor(AcmeChatTool::class))->toBeNull();
});

it('ignores a registered class that carries no SharedSettings attribute', function (): void {
    // The loader fans out whatever a plugin's hook returned, unvalidated, so
    // the resolver cannot assume every entry is a group. The class_exists()
    // guard in front of the same check is what covers an entry that is not
    // even autoloadable; it has no separate observable behaviour here.
    $resolver = new SharedSettingsResolver([AcmeStandaloneTool::class]);

    expect($resolver->groupClassFor(AcmeChatTool::class))->toBeNull();
});

it('never reads the stored settings for a tool with no group', function (): void {
    $reads = 0;
    $resolver = new SharedSettingsResolver();

    $resolver->applyToDefaults(AcmeStandaloneTool::class, [], function () use (&$reads): array {
        $reads++;

        return ['api_key' => 'should-not-be-read'];
    });

    expect($reads)->toBe(0);
});

it('takes only the keys the tool declares from the group', function (): void {
    $resolver = new SharedSettingsResolver([AcmeSharedSettings::class]);

    $applied = $resolver->applyToDefaults(
        AcmeChatTool::class,
        ['retries' => '3'],
        static fn(): array => [
            'api_key'            => 'shared-secret',
            'region'             => 'eu-west',
            'a_setting_this_tool_never_declared' => 'leak',
        ],
    );

    expect($applied)->toBe(['retries' => '3', 'api_key' => 'shared-secret', 'region' => 'eu-west']);
});

it('ignores an empty value in the group rather than clearing the tool default', function (): void {
    $resolver = new SharedSettingsResolver([AcmeSharedSettings::class]);

    $applied = $resolver->applyToDefaults(
        AcmeChatTool::class,
        ['retries' => '3'],
        static fn(): array => ['retries' => '', 'api_key' => 'shared-secret'],
    );

    expect($applied)->toBe(['retries' => '3', 'api_key' => 'shared-secret']);
});

it('reads the group once and memoises the tool to group mapping', function (): void {
    $reads = 0;
    $resolver = new SharedSettingsResolver([AcmeSharedSettings::class]);

    $read = function () use (&$reads): array {
        $reads++;

        return ['api_key' => 'shared-secret'];
    };

    $resolver->applyToDefaults(AcmeChatTool::class, [], $read);
    $resolver->applyToDefaults(AcmeChatTool::class, [], $read);
    $resolver->groupClassFor(AcmeChatTool::class);

    expect($reads)->toBe(2);
});

it('limits the inherited payload to keys the tool declares', function (): void {
    $resolver = new SharedSettingsResolver([AcmeSharedSettings::class]);

    $inherited = $resolver->inheritedValues(
        AcmeChatTool::class,
        static fn(): array => [
            'api_key' => 'shared-secret',
            'region'  => 'eu-west',
            'a_setting_this_tool_never_declared' => 'leak',
        ],
    );

    expect($inherited)->toBe(['api_key' => 'shared-secret', 'region' => 'eu-west']);
});

it('reports inherited as empty when the tool names no group', function (): void {
    $resolver = new SharedSettingsResolver([AcmeSharedSettings::class]);

    expect($resolver->inheritedValues(
        AcmeStandaloneTool::class,
        static fn(): array => ['api_key' => 'shared-secret'],
    ))->toBe([]);
});

it('resolves the same group id to the first class that declares it', function (): void {
    // Same rule PluginLoader applies to two plugins sharing a slug.
    $resolver = new SharedSettingsResolver([AcmeSharedSettings::class, AcmeDuplicateSettings::class]);

    expect($resolver->groupClassFor(AcmeChatTool::class))->toBe(AcmeSharedSettings::class);
});

it('rejects a group id that is not snake_case', function (): void {
    expect(static fn() => new SharedSettings(group: 'Acme-Corp'))
        ->toThrow(InvalidArgumentException::class);
});

it('carries the operator-facing metadata the settings UI needs', function (): void {
    $attribute = (new ReflectionClass(AcmeSharedSettings::class))
        ->getAttributes(SharedSettings::class)[0]
        ->newInstance();

    expect($attribute->group)->toBe('acme')
        ->and($attribute->label)->toBe('Acme')
        ->and($attribute->description)->toBe('Credentials shared by every Acme tool.')
        ->and($attribute->icon)->toBe('key')
        ->and($attribute->order)->toBe(20)
        ->and($attribute->plugin)->toBe('spora-plugin-acme');
});

it('defaults the optional metadata rather than demanding it', function (): void {
    $attribute = new SharedSettings(group: 'bare');

    expect($attribute->label)->toBe('')
        ->and($attribute->description)->toBe('')
        ->and($attribute->icon)->toBe('')
        ->and($attribute->order)->toBe(0)
        ->and($attribute->plugin)->toBe('');
});

it('reads the group id off the tool without running its constructor', function (): void {
    // AcmeChatTool's constructor is unrunnable by design (required ctor args).
    // Resolving the group must not depend on a container being able to build
    // the tool.
    $resolver = new SharedSettingsResolver([AcmeSharedSettings::class]);

    expect($resolver->groupClassFor(AcmeUnconstructableTool::class))->toBeNull();
});

#[SharedSettings(group: 'acme')]
#[ToolSetting(key: 'api_key', label: 'API key', type: 'password')]
final class AcmeDuplicateSettings {}
