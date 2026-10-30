<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

/**
 * The vocabulary of `media_assets.kind`.
 *
 * The mark is **advisory**: it is a filter hint for the operator UI, and
 * nothing in the read or write path refuses an asset because of it. A
 * mis-marked theme still works, and the operator can change the mark at
 * any time.
 *
 * The three values are therefore a closed set only for the *filter*: an
 * unknown value is dropped by the listing builder and leaves the listing
 * unfiltered.
 */
final class MediaAssetKind
{
    /** The default mark — a plain asset. */
    public const ASSET = 'asset';

    /** A template the operator offers as a starting point. */
    public const TEMPLATE = 'template';

    /** An example the operator keeps for reference. */
    public const EXAMPLE = 'example';

    /** @var list<string> */
    public const ALL = [
        self::ASSET,
        self::TEMPLATE,
        self::EXAMPLE,
    ];
}
