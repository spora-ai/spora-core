<?php

declare(strict_types=1);

namespace Spora\Services;

use RuntimeException;

/**
 * Thrown when an {@see AssetStore} cannot persist a payload — for example,
 * the asset directory could not be created or the file write failed. Lets
 * callers catch a single, intent-revealing exception type rather than a
 * generic {@see RuntimeException}, and avoids leaking unrelated runtime
 * failures (e.g. DB connection errors) into AssetStore error handling.
 */
/**
 * Base type for asset-store persistence failures. Concrete subclasses:
 *  - {@see AssetTooLargeException} — payload exceeds `asset_store.max_bytes`
 *  - direct throws of this class for mkdir / write failures inside LocalAssetStore
 *
 * Not `final` so concrete failure subclasses can extend it and callers can
 * catch a single base type.
 */
class AssetStorageException extends RuntimeException {}
