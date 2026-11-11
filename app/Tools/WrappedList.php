<?php

declare(strict_types=1);

namespace Spora\Tools;

/**
 * The provider's list envelope, unwrapped.
 *
 * Some OpenAI-compatible providers serialise an array that sits as an
 * object's value inside a `{"item": …}` envelope instead of as a plain JSON
 * array. MiniMax's tool-call channel does this for every array nested inside
 * a `configure_tools` entry — a model that sends
 * `settings: {allowed_skills: ["a", "b"]}` has it arrive as
 * `settings: {allowed_skills: {item: ["a", "b"]}}` — and cannot produce the
 * flat shape on request, so a retry gets the same envelope.
 *
 * Two shapes arrive: `{item: [...]}` keeps the list inside the envelope,
 * `{item: x}` unwraps a one-element list to the element itself.
 *
 * A value that is not the envelope passes through untouched, so a payload
 * that is legitimately an object survives.
 */
final class WrappedList
{
    /** The key the envelope wraps its payload under. */
    private const ITEM_KEY = 'item';

    /**
     * Is this the envelope?
     *
     * Only the unambiguous shape counts: a non-list array whose sole key is
     * `item`. A multi-key object is a payload, not an envelope.
     */
    public static function isWrapped(mixed $value): bool
    {
        return is_array($value) && array_keys($value) === [self::ITEM_KEY];
    }

    /**
     * The envelope's payload, or the value unchanged.
     *
     * For a slot that expects a map rather than a list — `settings` — the
     * payload is taken as it is.
     */
    public static function unwrap(mixed $value): mixed
    {
        if (!self::isWrapped($value)) {
            return $value;
        }

        return $value[self::ITEM_KEY];
    }

    /**
     * The envelope's payload as a list.
     *
     * A payload that is not a list is a one-element list with the element
     * unwrapped too, so it goes back into one. A payload that is a list is
     * returned as it is, empty list included.
     */
    public static function unwrapToList(mixed $value): mixed
    {
        if (!self::isWrapped($value)) {
            return $value;
        }

        $payload = $value[self::ITEM_KEY];

        if (is_array($payload)) {
            return array_is_list($payload) ? $payload : [$payload];
        }

        return [$payload];
    }
}
