<?php

declare(strict_types=1);

namespace Spora\Drivers;

/**
 * The default reasoning-effort vocabulary Spora speaks, and the label map
 * for it.
 *
 * The cases are declared in ascending order because the levels are a
 * scale: the picker renders them in that order.
 *
 * The enum is no longer the constraint on what a level may be called: a
 * configuration names its own levels, and a provider that spells one
 * differently (Groq's `default`, say) has to be expressible. What the enum
 * still owns is the default vocabulary and the pretty label for a name that
 * belongs to it.
 */
enum EffortLevel: string
{
    case None    = 'none';
    case Minimal = 'minimal';
    case Low     = 'low';
    case Medium  = 'medium';
    case High    = 'high';
    case XHigh   = 'xhigh';
    case Max     = 'max';

    /**
     * The label of this case.
     */
    public function label(): string
    {
        return self::labelFor($this->value);
    }

    /**
     * The label of a canonical name, or the name itself when the name is
     * not canonical — an operator's own level shows as itself.
     */
    public static function labelFor(string $name): string
    {
        return match ($name) {
            'none'    => 'None',
            'minimal' => 'Minimal',
            'low'     => 'Low',
            'medium'  => 'Medium',
            'high'    => 'High',
            'xhigh'   => 'xHigh',
            'max'     => 'Max',
            default   => $name,
        };
    }
}
