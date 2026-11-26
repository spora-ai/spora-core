<?php

declare(strict_types=1);

namespace Spora\Drivers;

/**
 * The optional capability a driver implements when it knows the limits its
 * endpoint puts on a request.
 *
 * Deliberately separate from {@see LLMDriverInterface}: that one is a
 * published contract, and adding a method to it would break every
 * implementor — including out-of-tree ones.
 */
interface RequestLimitAwareDriverInterface
{
    /**
     * The largest base64-encoded image this endpoint accepts, in bytes.
     *
     * The provider measures the encoded payload, not the file: base64
     * spends four bytes on every three, so the image that fits is three
     * quarters of the number stated here.
     */
    public function maxEncodedImageBytes(): int;

    /**
     * The largest request body this endpoint accepts, in bytes.
     *
     * The whole request counts — the system prompt, the tools, the
     * conversation text and every image in it — so this is the ceiling the
     * images of one request have to fit inside.
     */
    public function maxRequestBytes(): int;

    /**
     * The number of images this endpoint accepts in one request.
     */
    public function maxImagesPerRequest(): int;
}
