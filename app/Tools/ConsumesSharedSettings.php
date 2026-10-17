<?php

declare(strict_types=1);

namespace Spora\Tools;

/**
 * Implemented by a tool that falls back to a shared settings group.
 *
 * The membership is declared forward, on the tool, because that is the only
 * direction that cannot go stale: a reverse index (group → tools) has to be
 * edited when a tool is added, and the tool nobody remembered to add silently
 * stops inheriting the credential. Declaring it here means adding the tool is
 * the same act as adding the inheritance.
 *
 * {@see \Spora\Tools\SharedSettingsResolver} reads this without constructing
 * the tool, so a consuming tool may take constructor dependencies freely.
 */
interface ConsumesSharedSettings
{
    /**
     * The `#[SharedSettings(group: ...)]` id this tool falls back to.
     *
     * An id no loaded plugin declares resolves to no group and no fallback —
     * see {@see \Spora\Tools\SharedSettingsResolver::groupClassFor()}.
     */
    public function sharedSettingsGroup(): string;
}
