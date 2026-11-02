<?php

declare(strict_types=1);

namespace Spora\Services\ApiKeys;

use RuntimeException;

/**
 * A group that cannot own an agent, because it has no principal.
 *
 * A group always gets a principal when it is created, so this is a broken
 * row rather than a state a caller can reach — but the API path has to say
 * so rather than create an ownerless agent.
 */
final class MissingGroupPrincipalException extends RuntimeException {}
