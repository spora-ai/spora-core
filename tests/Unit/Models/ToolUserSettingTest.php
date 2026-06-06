<?php

declare(strict_types=1);

use Spora\Models\ToolUserSetting;

const TOOL_USER_SETTING_TEST_PASSWORD = 'Password1!';

it('uses the tool_user_settings table', function (): void {
    $setting = new ToolUserSetting();

    expect($setting->getTable())->toBe('tool_user_settings');
});

it('allows mass assignment of user_id, tool_class, settings', function (): void {
    $userId = bootAuthLayer()->register('usersetting@example.com', TOOL_USER_SETTING_TEST_PASSWORD, 'US');

    $setting = ToolUserSetting::create([
        'user_id'    => $userId,
        'tool_class' => 'Spora\Tools\StubOutputTool',
        'settings'   => 'encrypted-blob',
    ]);

    expect($setting->getAttribute('user_id'))->toBe($userId)
        ->and($setting->getAttribute('tool_class'))->toBe('Spora\Tools\StubOutputTool')
        ->and($setting->getAttributes())->toHaveKey('settings');
});

it('throws LogicException when settings attribute is accessed directly', function (): void {
    $setting = new ToolUserSetting();

    expect(fn() => $setting->settings)->toThrow(LogicException::class);
});
