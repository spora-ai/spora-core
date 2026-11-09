<?php

declare(strict_types=1);

namespace Spora\Drivers;

/**
 * The canonical reasoning-effort scale Spora speaks.
 *
 * The cases are declared in ascending order because the levels are a
 * scale: the picker renders them in that order. A provider that spells
 * a level differently maps it in its own driver — the canonical value
 * is what the database, the API and the UI carry.
 *
 * The union of every provider's vocabulary; the accepted subset is a
 * per-driver question (see {@see EffortAwareDriverInterface::effortLevels()}).
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
}
