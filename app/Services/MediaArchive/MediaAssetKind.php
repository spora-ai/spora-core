<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

/**
 * The vocabulary of `media_assets.kind`.
 *
 * The mark is **advisory**: it is a filter hint for the operator UI, and
 * a mark in this vocabulary never refuses an asset. A mis-marked theme
 * still works, and the operator can change the mark at any time.
 *
 * The three values are a closed set. The upload endpoint refuses a value
 * outside it with a 400 — the column cannot hold it and the filter
 * cannot select it — and the listing builder drops a value it does not
 * know, so the listing stays unfiltered.
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
