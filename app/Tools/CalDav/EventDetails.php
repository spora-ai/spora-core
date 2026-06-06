<?php

declare(strict_types=1);

namespace Spora\Tools\CalDav;

/**
 * Flat bundle of the optional event-detail strings rendered by
 * {@see IcsParser::formatGetEventOutput()}. Groups the six optional
 * parameters so the formatter stays under the 7-parameter cap (SonarQube
 * S107).
 */
final readonly class EventDetails
{
    public function __construct(
        public ?string $uid,
        public ?string $summary,
        public ?string $dtstart,
        public ?string $dtend,
        public ?string $description,
        public ?string $location,
    ) {}
}
