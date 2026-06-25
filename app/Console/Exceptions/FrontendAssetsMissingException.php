<?php

declare(strict_types=1);

namespace Spora\Console\Exceptions;

use RuntimeException;

/**
 * Thrown by InstallCommand when public/dist/index.html is missing.
 * Indicates that the operator skipped `composer install spora-ai/spora-frontend`
 * or the prebuilt UI package is corrupted / not installed.
 */
final class FrontendAssetsMissingException extends RuntimeException
{
}
