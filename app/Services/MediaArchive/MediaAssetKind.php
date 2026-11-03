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
 * The three values are a closed set at the HTTP edge: the upload endpoint
 * refuses a value outside it with a 400, and the listing builder drops a
 * value it does not know, so the listing stays unfiltered. The column
 * itself holds any short string — the service stores a mark outside this
 * set as given — so the refusal is about a row no filter could select.
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
