<?php

declare(strict_types=1);

namespace Spora\Tools;

/**
 * The provider's list envelope, unwrapped.
 *
 * Some OpenAI-compatible providers serialise an array that sits as an
 * object's value inside a `{"item": …}` envelope instead of as a plain JSON
 * array. MiniMax's tool-call channel does this for every array nested inside
 * a `configure_tools` entry: a model that asks for
 * `settings: {allowed_skills: ["a", "b"]}` has it arrive as
 * `settings: {allowed_skills: {item: ["a", "b"]}}` — and the model cannot
 * produce the flat shape on request, so a retry gets the same envelope.
 *
 * Two shapes arrive, and the second is why a plain key lookup is not enough:
 *
 *   - `{item: [...]}` — the list, inside the envelope.
 *   - `{item: x}`     — a one-element list with the element unwrapped too.
 *
 * A value that is not the envelope passes through untouched, so a payload
 * that is legitimately an object — a settings map with a key called `item`,
 * a tool's own object-valued setting — survives.
 */
final class WrappedList
{
    /** The key the envelope wraps its payload under. */
    private const ITEM_KEY = 'item';

    /**
     * Is this the envelope?
     *
     * Only the unambiguous shape counts: a non-list array whose sole key is
     * `item`. A multi-key object is a payload, not an envelope, and a list
     * is a list however it was written.
     */
    public static function isWrapped(mixed $value): bool
    {
        return is_array($value) && array_keys($value) === [self::ITEM_KEY];
    }

    /**
     * The envelope's payload, or the value unchanged.
     *
     * For a slot that expects a map rather than a list — `settings` — the
     * payload is taken as it is. The caller decides whether a payload that
     * is not a map is acceptable there.
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
     * unwrapped too, so it goes back into one — including a scalar payload,
     * which is the same shape one level further down. A payload that is a
     * list is returned as it is, including the empty list, which is a list
     * and not a missing value.
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
