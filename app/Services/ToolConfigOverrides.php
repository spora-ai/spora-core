<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Tools\SharedSettingsResolver;

/**
 * The collaborators {@see ToolConfigService} may be given rather than having
 * it build its own, gathered into one object so the constructor stays inside
 * the parameter limit.
 *
 * Every field is nullable and the service supplies a working default for each,
 * so an all-null instance behaves exactly as the individual defaults always
 * did. Only the container and tests construct one.
 */
final class ToolConfigOverrides
{
    public function __construct(
        public readonly ?ToolConfigSchemaInspector $schema = null,
        public readonly ?PrincipalService $principalService = null,
        public readonly bool $groupCascadeEnabled = false,
        public readonly ?PrincipalResolver $principalResolver = null,
        public readonly ?SharedSettingsResolver $sharedSettings = null,
    ) {}
}
