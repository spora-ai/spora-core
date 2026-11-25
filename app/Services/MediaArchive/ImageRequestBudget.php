<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

/**
 * The image bytes one request may carry.
 *
 * The per-image ceiling bounds one image and the row budget bounds one
 * row, but a request is built from every row of the history: a run with
 * many retained image-bearing tool results would otherwise add up to more
 * than the provider accepts, and the request then fails as a whole — on
 * every rebuilt history, because the rows are the same ones each time.
 *
 * The budget is shared by the two paths that put an image in a request,
 * so the total is the same whichever path the image came from.
 */
final class ImageRequestBudget
{
    private int $spent = 0;

    public function __construct(private readonly int $maxBytes) {}

    /**
     * Whether the encoded image fits what is left of the request.
     *
     * The encoded size is the one the provider measures, so it is the one
     * the budget counts.
     */
    public function accepts(int $encodedBytes): bool
    {
        if ($this->spent + $encodedBytes > $this->maxBytes) {
            return false;
        }

        $this->spent += $encodedBytes;

        return true;
    }

    /**
     * Start the budget again, for the next request built from the same rows.
     */
    public function reset(): void
    {
        $this->spent = 0;
    }
}
