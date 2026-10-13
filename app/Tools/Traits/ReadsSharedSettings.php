<?php

declare(strict_types=1);

namespace Spora\Tools\Traits;

use Spora\Tools\ConsumesSharedSettings;

/**
 * Supplies {@see ConsumesSharedSettings::sharedSettingsGroup()} from the
 * using class's own constant, so a plugin writes the group id once:
 *
 *   final class MinimaxChatTool implements ToolInterface, ConsumesSharedSettings
 *   {
 *       use ReadsSharedSettings;
 *
 *       public const SHARED_SETTINGS_GROUP = 'minimax';
 *   }
 *
 * Every tool in a plugin then names the same constant, and the id cannot drift
 * between them. The constant is read rather than declared so a class that
 * forgets it fails loudly at resolution instead of quietly resolving to no
 * group.
 */
trait ReadsSharedSettings
{
    public function sharedSettingsGroup(): string
    {
        return static::SHARED_SETTINGS_GROUP;
    }
}
