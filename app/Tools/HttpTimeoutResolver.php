<?php

declare(strict_types=1);

namespace Spora\Tools;

use Spora\Core\Config;

/**
 * The one rule for a tool's outbound HTTP timeout.
 *
 * `SPORA_TOOL_HTTP_TIMEOUT` is operator-editable in Global Settings, so the
 * copies this replaces — which read `$_ENV` and `getenv()` directly — bypassed
 * the value the operator actually set. They also leaned on `?:` to turn a tool
 * setting of `0` into the global one by accident rather than by decision;
 * `0` and negatives are unset here on purpose.
 */
final class HttpTimeoutResolver
{
    public function __construct(private readonly Config $config) {}

    /**
     * @param int $fallbackSeconds Used only when neither the tool's own setting
     *                            nor the global setting is a positive number.
     */
    public function resolve(mixed $toolSettingValue, int $fallbackSeconds = 30): int
    {
        $fromTool = is_numeric($toolSettingValue) ? (int) $toolSettingValue : 0;
        if ($fromTool > 0) {
            return $fromTool;
        }

        $fromGlobal = $this->config->getInt('SPORA_TOOL_HTTP_TIMEOUT');

        return $fromGlobal !== null && $fromGlobal > 0 ? $fromGlobal : $fallbackSeconds;
    }
}