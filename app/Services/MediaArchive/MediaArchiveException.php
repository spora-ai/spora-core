<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use RuntimeException;

/**
 * Thrown when {@see MediaArchiveService} cannot persist a payload it has
 * already committed to keeping locally — typically because the configured
 * {@see AssetStore} refused the bytes (size cap, disk write failure, etc.).
 *
 * The URL branch can fall back to `storage_mode=external` for transport
 * failures, but once the service has decided to promote the bytes the
 * store rejection is fatal: the upstream policy decision was made and
 * a silent downgrade to external would lie about what the operator sees.
 */
final class MediaArchiveException extends RuntimeException {}
