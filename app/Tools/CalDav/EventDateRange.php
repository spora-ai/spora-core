<?php

declare(strict_types=1);

namespace Spora\Tools\CalDav;

use DateTimeImmutable;

/**
 * Bundle of date/time fields for an iCalendar event. Groups the four
 * parameters that travel together so {@see IcsBuilder::buildIcs()} stays
 * under the 7-parameter cap (SonarQube S107).
 */
final readonly class EventDateRange
{
    public function __construct(
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
        public string $timezone,
        public bool $allDay,
    ) {}
}
