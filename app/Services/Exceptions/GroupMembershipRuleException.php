<?php

declare(strict_types=1);

namespace Spora\Services\Exceptions;

use RuntimeException;

/**
 * Thrown when a group-membership operation (remove the last owner, demote
 * past a tier boundary) would orphan the group from a role tier. Maps to
 * 409 at the controller boundary.
 */
final class GroupMembershipRuleException extends RuntimeException {}
