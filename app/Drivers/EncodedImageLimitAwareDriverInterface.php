<?php

declare(strict_types=1);

namespace Spora\Drivers;

/**
 * The optional capability a driver implements when it knows the ceiling
 * its provider puts on one base64-encoded image.
 *
 * Deliberately separate from {@see LLMDriverInterface}: that one is a
 * published contract, and adding a method to it would break every
 * implementor — including out-of-tree ones.
 */
interface EncodedImageLimitAwareDriverInterface
{
    /**
     * The largest base64-encoded image this endpoint accepts, in bytes.
     *
     * The provider measures the encoded payload, not the file: base64
     * spends four bytes on every three, so the image that fits is three
     * quarters of the number stated here.
     */
    public function maxEncodedImageBytes(): int;
}
