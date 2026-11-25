<?php

declare(strict_types=1);

namespace Spora\Drivers;

/**
 * The per-image ceilings the providers publish, and how a driver resolves
 * the one that applies to it.
 *
 * Every number here bounds the **encoded** payload, because that is what
 * the provider measures: an image travels as base64, which spends four
 * bytes on every three. The archive's own byte caps are a different
 * thing — they bound what the worker holds, not what the provider takes.
 */
final class EncodedImageLimits
{
    /**
     * The Claude API's ceiling: 10 MB of base64 per image.
     */
    public const CLAUDE_API_MAX_ENCODED_BYTES = 10_000_000;

    /**
     * Amazon Bedrock's ceiling: 5 MB of base64 per image — half the
     * Claude API's, and the strictest of the platforms the
     * Anthropic-compatible driver can be pointed at.
     */
    public const BEDROCK_MAX_ENCODED_BYTES = 5_000_000;

    /**
     * OpenAI's ceiling: 20 MB per image.
     */
    public const OPENAI_MAX_ENCODED_BYTES = 20_000_000;

    /**
     * The ceiling assumed for a driver that states none.
     *
     * The strictest of the known limits, because an endpoint that does
     * not say what it takes is more likely to be strict than generous,
     * and an image the provider rejects fails the turn that carried it —
     * and every turn after it, because the history is rebuilt from the
     * stored row each time.
     */
    public const SAFE_MAX_ENCODED_BYTES = self::BEDROCK_MAX_ENCODED_BYTES;

    /**
     * The ceiling the endpoint behind a driver accepts.
     */
    public static function maxEncodedBytesFor(?LLMDriverInterface $driver): int
    {
        if ($driver instanceof EncodedImageLimitAwareDriverInterface) {
            return $driver->maxEncodedImageBytes();
        }

        return self::SAFE_MAX_ENCODED_BYTES;
    }

    /**
     * The largest file whose base64 form still fits an encoded ceiling.
     *
     * Base64 spends four bytes on every three and rounds the last group
     * up, so the file that fits is three quarters of the ceiling — and one
     * byte more would encode to more than the ceiling.
     */
    public static function maxRawBytesFor(int $maxEncodedBytes): int
    {
        return 3 * intdiv($maxEncodedBytes, 4);
    }
}
