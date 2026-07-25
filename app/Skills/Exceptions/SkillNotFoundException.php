<?php

declare(strict_types=1);

namespace Spora\Skills\Exceptions;

use RuntimeException;

/**
 * Raised when a requested skill (or skill file) cannot be located.
 *
 * Distinct from generic RuntimeException so {@see \Spora\Http\SkillController}
 * can map it to a 404 SKILL_NOT_FOUND without catching on type.
 */
final class SkillNotFoundException extends RuntimeException {}
