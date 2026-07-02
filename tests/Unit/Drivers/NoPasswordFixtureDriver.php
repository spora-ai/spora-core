<?php

declare(strict_types=1);

namespace Tests\Unit\Drivers;

use Spora\Tools\Attributes\ToolSetting;

/**
 * Internal fixture: a driver class with NO password-typed ToolSettings.
 * Used to verify getPasswordKeys() returns an empty list for password-free classes.
 */
#[ToolSetting(key: 'greeting', label: 'Greeting', type: 'text', description: 'A friendly greeting.', required: false, default: 'hello')]
final class NoPasswordFixtureDriver
{
    public static function getName(): string
    {
        return 'no_password_fixture';
    }
    public static function getDisplayName(): string
    {
        return 'No Password Fixture';
    }
}
