<?php

declare(strict_types=1);

namespace Spora\Services;

/**
 * Thrown when a caller asks {@see AssetStore::store()} to persist more
 * bytes than the configured `asset_store.max_bytes` ceiling. Surfaces as
 * a clear, actionable error to the tool caller rather than an OOM or a
 * silently truncated payload.
 */
final class AssetTooLargeException extends AssetStorageException {}
