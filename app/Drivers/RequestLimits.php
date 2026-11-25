<?php

declare(strict_types=1);

namespace Spora\Drivers;

/**
 * The ceilings the providers publish, and how a driver resolves the ones
 * that apply to it.
 *
 * Two ceilings per endpoint, and they are different things:
 *
 *   - the **image** ceiling bounds one base64-encoded image;
 *   - the **request** ceiling bounds the whole request body — the system
 *     prompt, the tools, the conversation text and every image in it.
 *
 * Every number here bounds what the provider measures. The archive's own
 * byte caps are a different thing: they bound what the worker holds.
 */
final class RequestLimits
{
    /**
     * The Claude API's image ceiling: 10 MB of base64 per image.
     */
    public const CLAUDE_API_MAX_IMAGE_BYTES = 10_000_000;

    /**
     * Amazon Bedrock's image ceiling: 5 MB of base64 per image — half the
     * Claude API's, and the strictest of the platforms the
     * Anthropic-compatible driver can be pointed at.
     */
    public const BEDROCK_MAX_IMAGE_BYTES = 5_000_000;

    /**
     * OpenAI's image ceiling on the Chat Completions route: 20 MB per
     * image.
     */
    public const OPENAI_MAX_IMAGE_BYTES = 20_000_000;

    /**
     * OpenAI's image ceiling on the Responses route: the schema bounds the
     * whole `image_url` string — the data-URL prefix included — at
     * 20,971,520 characters.
     */
    public const OPENAI_RESPONSES_MAX_IMAGE_BYTES = 20_971_520;

    /**
     * The longest data-URL prefix Spora sends: `data:image/jpeg;base64,`.
     *
     * The OpenAI routes send the image as a data URL, so the ceiling that
     * bounds the string has the prefix counted against it.
     */
    public const DATA_URL_PREFIX_BYTES = 23;

    /**
     * The Claude API's request ceiling: 128 MB.
     */
    public const CLAUDE_API_MAX_REQUEST_BYTES = 128_000_000;

    /**
     * Amazon Bedrock's request ceiling: 20 MB.
     */
    public const BEDROCK_MAX_REQUEST_BYTES = 20_000_000;

    /**
     * OpenAI's request ceiling: 512 MB.
     */
    public const OPENAI_MAX_REQUEST_BYTES = 512_000_000;

    /**
     * The ceiling assumed for a driver that states none.
     *
     * The strictest of the known limits, because an endpoint that does not
     * say what it takes is more likely to be strict than generous, and a
     * request the provider rejects fails the turn that carried it — and
     * every turn after it, because the history is rebuilt from the stored
     * row each time.
     */
    public const SAFE_MAX_IMAGE_BYTES = self::BEDROCK_MAX_IMAGE_BYTES;

    /**
     * The request ceiling assumed for a driver that states none.
     */
    public const SAFE_MAX_REQUEST_BYTES = self::BEDROCK_MAX_REQUEST_BYTES;

    /**
     * The image ceiling the endpoint behind a driver accepts.
     */
    public static function maxEncodedImageBytesFor(?LLMDriverInterface $driver): int
    {
        if ($driver instanceof RequestLimitAwareDriverInterface) {
            return $driver->maxEncodedImageBytes();
        }

        return self::SAFE_MAX_IMAGE_BYTES;
    }

    /**
     * The request ceiling the endpoint behind a driver accepts.
     */
    public static function maxRequestBytesFor(?LLMDriverInterface $driver): int
    {
        if ($driver instanceof RequestLimitAwareDriverInterface) {
            return $driver->maxRequestBytes();
        }

        return self::SAFE_MAX_REQUEST_BYTES;
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

    /**
     * The share of a request the images may take.
     *
     * A request carries the system prompt, the tools and the conversation
     * text as well as the images, and only the images are sized here — so
     * three quarters of the request is the ceiling, and the remaining
     * quarter is the text's.
     */
    public static function maxImageBytesForRequest(int $maxRequestBytes): int
    {
        return 3 * intdiv($maxRequestBytes, 4);
    }
}
