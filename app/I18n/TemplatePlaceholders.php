<?php

declare(strict_types=1);

namespace Spora\I18n;

/**
 * The `sprintf`-style placeholders of a catalogue template, in the order
 * they appear.
 *
 * Only `%s` and `%d` are placeholders. A percent sign that is not one of
 * them is copy — "50 % davon" is correct German — so the translator leaves
 * it exactly as written and the catalogue gate does not count it. A
 * percent sign written directly in front of an `s` or a `d` is read as a
 * placeholder, so copy that needs one has to put a space between them.
 *
 * The gate and the translator read the syntax here, so a value the gate
 * accepts is a value the translator can fill.
 */
final class TemplatePlaceholders
{
    /** The sequences a `sprintf`-style template uses. */
    public const SPECIFIERS = ['%s', '%d'];

    /** The same sequences, as one pattern. */
    public const PATTERN = '/%[sd]/';

    /**
     * The placeholders in a template, in order.
     *
     * @return list<string>
     */
    public static function in(string $template): array
    {
        preg_match_all(self::PATTERN, $template, $matches);

        return $matches[0];
    }
}
