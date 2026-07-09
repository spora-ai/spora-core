<?php

declare(strict_types=1);

use Spora\Core\Extension\PluginPackageName;

describe('PluginPackageName::isValid', function (): void {
    it('accepts a canonical vendor/name', function (): void {
        expect(PluginPackageName::isValid('spora-ai/spora-plugin-tavily'))->toBeTrue();
    });

    it('accepts short forms', function (): void {
        expect(PluginPackageName::isValid('foo/bar'))->toBeTrue();
        expect(PluginPackageName::isValid('a/b'))->toBeTrue();
    });

    it('accepts the full separator-character set', function (): void {
        expect(PluginPackageName::isValid('vendor_name/plugin-name.foo'))->toBeTrue();
    });

    it('rejects an empty string', function (): void {
        expect(PluginPackageName::isValid(''))->toBeFalse();
    });

    it('rejects a name with no slash', function (): void {
        expect(PluginPackageName::isValid('spora-ai-spora-plugin-tavily'))->toBeFalse();
    });

    it('rejects uppercase letters (path segments are case-sensitive)', function (): void {
        expect(PluginPackageName::isValid('Spora-AI/spora-plugin-tavily'))->toBeFalse();
        expect(PluginPackageName::isValid('spora-ai/spora-plugin-Tavily'))->toBeFalse();
    });

    it('rejects leading or trailing whitespace in non-strict mode (caller must trim first)', function (): void {
        // Documents the helper's contract: it does not silently trim.
        // PluginsService::parseComposerName() trims before calling.
        expect(PluginPackageName::isValid(' spora-ai/spora-plugin-tavily'))->toBeFalse();
        expect(PluginPackageName::isValid('spora-ai/spora-plugin-tavily '))->toBeFalse();
    });

    it('rejects surrounding angle brackets or other shell metacharacters', function (): void {
        expect(PluginPackageName::isValid('foo/bar;rm -rf /'))->toBeFalse();
        expect(PluginPackageName::isValid('foo/bar$(whoami)'))->toBeFalse();
    });
});
