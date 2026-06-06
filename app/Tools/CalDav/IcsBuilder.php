<?php

declare(strict_types=1);

namespace Spora\Tools\CalDav;

use DateTimeImmutable;
use DateTimeZone;
use Icalendar\Component\VCalendar;
use Icalendar\Component\VEvent;
use Icalendar\Property\GenericProperty;
use Icalendar\Value\GenericValue;
use Icalendar\Writer\Writer;

/**
 * Builds iCalendar (RFC 5545) payloads for create/edit operations, and
 * generates UIDs and filenames for new events. Pure: no I/O, no DB.
 */
final class IcsBuilder
{
    private const ICS_DATETIME_UTC   = 'Ymd\THis\Z';
    private const ICS_DATETIME_LOCAL = 'Ymd\THis';

    public function __construct(private readonly array $appConfig = []) {}

    /**
     * Build a complete VCALENDAR document string for one event.
     */
    public function buildIcs(
        string $uid,
        string $summary,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        string $description,
        string $location,
        string $timezone,
        bool $allDay,
    ): string {
        $calendar = $this->makeCalendar();
        $event    = $this->makeEvent($uid, $summary, $description, $location);

        $this->applyDates($event, $start, $end, $timezone, $allDay);

        $calendar->addComponent($event);
        return (new Writer())->write($calendar);
    }

    /**
     * Parse a user-provided date string for event creation/edit.
     *
     * - For all-day events: expects a date-only string (YYYY-MM-DD).
     * - For timed events with a timezone: applies the IANA timezone.
     * - For timed events without a timezone: uses UTC.
     */
    public function parseEventDate(string $dateStr, string $timezone, bool $allDay): DateTimeImmutable
    {
        if ($allDay) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $dateStr);
            if ($parsed === false) {
                throw new CalDavException("all_day requires date-only format YYYY-MM-DD, got: {$dateStr}");
            }
            return $parsed;
        }

        if ($timezone !== '') {
            $tz = new DateTimeZone($timezone);
            return (new DateTimeImmutable($dateStr, $tz))->setTimezone($tz);
        }

        $utc = new DateTimeZone('UTC');
        return (new DateTimeImmutable($dateStr, $utc))->setTimezone($utc);
    }

    public function generateUid(int $agentId): string
    {
        $domain = $this->resolveUidDomain();
        return sprintf('%s-%d@%s', uniqid('', true), $agentId, $domain);
    }

    public function generateEventFilename(string $summary, DateTimeImmutable $start): string
    {
        $slug = preg_replace('/[^a-zA-Z0-9]/', '-', strtolower($summary));
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');
        $timestamp = $start->format('Ymd-His');
        return "{$timestamp}-{$slug}.ics";
    }

    private function makeCalendar(): VCalendar
    {
        $calendar = new VCalendar();
        $calendar->setProductId('-//Spora//CalDAV Calendar Tool//EN');
        $calendar->setVersion('2.0');
        $calendar->setCalscale('GREGORIAN');
        return $calendar;
    }

    private function makeEvent(string $uid, string $summary, string $description, string $location): VEvent
    {
        $event = new VEvent();
        $event->setUid($uid);
        // DTSTAMP must be UTC (the trailing Z is the RFC 5545 marker); date()
        // would emit the server-local time mislabeled as UTC. gmdate() does
        // the right thing without an extra DateTimeImmutable.
        $event->setDtStamp(gmdate(self::ICS_DATETIME_UTC));
        $event->setSummary($summary);
        if ($description !== '') {
            $event->setDescription($description);
        }
        if ($location !== '') {
            $event->setLocation($location);
        }
        return $event;
    }

    private function applyDates(VEvent $event, DateTimeImmutable $start, DateTimeImmutable $end, string $timezone, bool $allDay): void
    {
        if ($allDay) {
            $event->setDtStart($start->format('Ymd'));
            $event->setDtEnd($end->format('Ymd'));
            return;
        }

        if ($timezone !== '') {
            $tz = new DateTimeZone($timezone);
            $this->setDateWithTimezone($event, 'DTSTART', $start->setTimezone($tz)->format(self::ICS_DATETIME_LOCAL), $timezone);
            $this->setDateWithTimezone($event, 'DTEND', $end->setTimezone($tz)->format(self::ICS_DATETIME_LOCAL), $timezone);
            return;
        }

        $event->setDtStart($start->setTimezone(new DateTimeZone('UTC'))->format(self::ICS_DATETIME_UTC));
        $event->setDtEnd($end->setTimezone(new DateTimeZone('UTC'))->format(self::ICS_DATETIME_UTC));
    }

    /**
     * Set a date property with a TZID parameter on a VEvent.
     * The craigk5n library does not support TZID parameters out of the box,
     * so we add the property manually with the right parameters.
     */
    private function setDateWithTimezone(VEvent $event, string $propertyName, string $dateValue, string $timezone): void
    {
        $event->removeProperty($propertyName);
        $property = new GenericProperty(
            $propertyName,
            new GenericValue($dateValue, 'DATE-TIME'),
            ['TZID' => $timezone],
        );
        $event->addProperty($property);
    }

    private function resolveUidDomain(): string
    {
        $appUrl = (string) ($this->appConfig['app_url'] ?? '');
        if ($appUrl !== '') {
            $parsed = parse_url($appUrl);
            if (is_array($parsed) && isset($parsed['host']) && $parsed['host'] !== 'localhost' && $parsed['host'] !== '127.0.0.1') {
                return $parsed['host'];
            }
        }
        return 'spora';
    }
}
