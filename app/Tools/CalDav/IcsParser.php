<?php

declare(strict_types=1);

namespace Spora\Tools\CalDav;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMNode;
use DOMNodeList;
use DOMXPath;
use Icalendar\Component\VEvent;
use Icalendar\Parser\Parser;
use LibXMLError;
use Spora\Tools\ValueObjects\ToolResult;
use Throwable;

/**
 * Parses CalDAV server responses: XML multistatus (for list), and iCalendar
 * (for get/edit). Stateless: each public method is pure with respect to its
 * inputs.
 */
final class IcsParser
{
    private const ICS_DATETIME_UTC   = 'Ymd\THis\Z';
    private const ICS_DATETIME_LOCAL = 'Ymd\THis';

    /**
     * Parse a CalDAV multistatus XML body and turn each <c:calendar-data>
     * payload into a row in the result.
     */
    public function parseListResponse(string $xmlBody): ToolResult
    {
        $parser = new DOMDocument();
        $previousUseErrors = libxml_use_internal_errors(true);
        try {
            $loaded = $parser->loadXML($xmlBody);
            $loadErrors = libxml_get_errors();
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($previousUseErrors);
        }

        if ($loaded === false) {
            return $this->malformedXmlResult($loadErrors);
        }

        $eventData = $this->collectEventsFromMultistatus($parser);
        if ($eventData === []) {
            return new ToolResult(true, 'No events found in the specified time range.');
        }

        return new ToolResult(
            true,
            "Found " . count($eventData) . " events:\n\n" . $this->formatEventRows($eventData),
            ['events' => $eventData],
        );
    }

    /**
     * Parse a VCALENDAR body for a single event (used by get_event).
     */
    public function parseEventForGet(string $icsContent, string $eventUri, ?string $etag): ToolResult
    {
        $event = $this->firstVEvent($icsContent);
        if ($event === null) {
            return new ToolResult(false, 'No VEVENT found in the calendar data.');
        }

        $details = new EventDetails(
            uid: $event->getUid(),
            summary: $event->getSummary(),
            dtstart: $event->getDtStart(),
            dtend: $event->getDtEnd(),
            description: $event->getDescription(),
            location: $event->getLocation(),
        );

        return new ToolResult(true, $this->formatGetEventOutput($eventUri, $etag, $details), [
            'event_uri'   => $eventUri,
            'uid'         => $details->uid,
            'summary'     => $details->summary,
            'dtstart'     => $details->dtstart,
            'dtend'       => $details->dtend,
            'description' => $details->description,
            'location'    => $details->location,
            'etag'        => $etag,
        ]);
    }

    /**
     * Parse a VCALENDAR body and return the first VEVENT as a flat array
     * suitable for `edit_event`'s merge step. Returns a stub if the body has
     * no VEVENT (caller treats that as an "incomplete" event).
     *
     * @return array{uid: ?string, summary: string, dtstart: ?DateTimeImmutable, dtend: ?DateTimeImmutable, description: string, location: string}
     */
    public function parseEventForEdit(string $icsContent): array
    {
        /** @var VEvent|null $event */
        $event = $this->firstVEvent($icsContent);
        if ($event === null) {
            return [
                'uid'         => null,
                'summary'     => '',
                'dtstart'     => null,
                'dtend'       => null,
                'description' => '',
                'location'    => '',
            ];
        }

        return [
            'uid'         => $event->getUid() ?? '',
            'summary'     => $event->getSummary() ?? '',
            'dtstart'     => $this->parseIcsDateString($event->getDtStart() ?? ''),
            'dtend'       => $this->parseIcsDateString($event->getDtEnd() ?? ''),
            'description' => $event->getDescription() ?? '',
            'location'    => $event->getLocation() ?? '',
        ];
    }

    /**
     * @return list<array{event_uri: ?string, uid: string, summary: string, dtstart: string, dtend: string}>
     */
    public function parseEventRowsFromIcs(string $icsContent, ?string $eventUri): array
    {
        $events = array_values((new Parser(Parser::LENIENT))->parse($icsContent)->getComponents('VEVENT'));
        $rows = [];
        foreach ($events as $event) {
            /** @var VEvent $event */
            $rows[] = [
                'event_uri' => $eventUri,
                'uid'       => $event->getUid() ?? '',
                'summary'   => $event->getSummary() ?? 'Unknown Event',
                'dtstart'   => $event->getDtStart() ?? 'Unknown Start',
                'dtend'     => $event->getDtEnd() ?? 'Unknown End',
            ];
        }
        return $rows;
    }

    private function firstVEvent(string $icsContent): ?VEvent
    {
        /** @var list<VEvent> $events */
        $events = array_values((new Parser(Parser::LENIENT))->parse($icsContent)->getComponents('VEVENT'));
        if ($events === []) {
            return null;
        }
        return $events[0];
    }

    private function makeXPath(DOMDocument $parser): DOMXPath
    {
        $xpath = new DOMXPath($parser);
        $xpath->registerNamespace('d', 'DAV:');
        $xpath->registerNamespace('c', 'urn:ietf:params:xml:ns:caldav');
        return $xpath;
    }

    /**
     * @param DOMNodeList<DOMNode> $calendarDataNodes
     * @return list<array{event_uri: ?string, uid: string, summary: string, dtstart: string, dtend: string}>
     */
    private function collectEvents(DOMNodeList $calendarDataNodes, DOMXPath $xpath): array
    {
        $eventData = [];
        foreach ($calendarDataNodes as $calData) {
            $icsContent = $calData->textContent;
            if (trim($icsContent) === '') {
                continue;
            }
            $eventUri = $this->findEventUriForCalData($calData, $xpath);
            foreach ($this->parseEventRowsFromIcs($icsContent, $eventUri) as $row) {
                $eventData[] = $row;
            }
        }
        return $eventData;
    }

    /**
     * @param list<array{event_uri: ?string, uid: string, summary: string, dtstart: string, dtend: string}> $eventData
     */
    private function formatEventRows(array $eventData): string
    {
        $output = '';
        foreach ($eventData as $row) {
            $output .= "- Event: {$row['summary']}\n  URI:   {$row['event_uri']}\n  UID:   {$row['uid']}\n  Start: {$row['dtstart']}\n  End:   {$row['dtend']}\n\n";
        }
        return trim($output);
    }

    private function formatGetEventOutput(
        string $eventUri,
        ?string $etag,
        EventDetails $details,
    ): string {
        $output  = "Event Details:\n";
        $output .= "- URI: {$eventUri}\n";
        if ($details->uid) {
            $output .= "- UID: {$details->uid}\n";
        }
        if ($details->summary) {
            $output .= "- Summary: {$details->summary}\n";
        }
        if ($details->dtstart) {
            $output .= "- Start: {$details->dtstart}\n";
        }
        if ($details->dtend) {
            $output .= "- End: {$details->dtend}\n";
        }
        if ($details->description) {
            $output .= "- Description: {$details->description}\n";
        }
        if ($details->location) {
            $output .= "- Location: {$details->location}\n";
        }
        if ($etag) {
            $output .= "- ETag: {$etag}\n";
        }
        return $output;
    }

    private function findEventUriForCalData(DOMNode $calData, DOMXPath $xpath): ?string
    {
        // Walk up to find the parent <d:response> for the href (event URI)
        $eventUri = null;
        $responseNode = $calData->parentNode;
        while ($responseNode !== null) {
            if ($responseNode->localName === 'response') {
                $hrefNode = $xpath->query('d:href', $responseNode)->item(0);
                if ($hrefNode !== null) {
                    $eventUri = trim($hrefNode->textContent);
                }
                break;
            }
            $responseNode = $responseNode->parentNode;
        }
        return $eventUri;
    }

    private function parseIcsDateString(?string $dateStr): ?DateTimeImmutable
    {
        if ($dateStr === null || $dateStr === '') {
            return null;
        }
        if (strlen($dateStr) === 8 || str_ends_with($dateStr, 'Z')) {
            return $this->parseIcsDateVariant($dateStr);
        }
        return $this->parseIcsDateWithTimezone($dateStr) ?? $this->parseIcsDateGeneric($dateStr);
    }

    private function parseIcsDateVariant(string $dateStr): ?DateTimeImmutable
    {
        if (str_ends_with($dateStr, 'Z')) {
            $parsed = DateTimeImmutable::createFromFormat(self::ICS_DATETIME_UTC, $dateStr, new DateTimeZone('UTC'));
            return $parsed instanceof DateTimeImmutable ? $parsed->setTimezone(new DateTimeZone('UTC')) : null;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Ymd', $dateStr);
        return $parsed instanceof DateTimeImmutable ? $parsed : null;
    }

    /**
     * Parse an iCalendar date with an explicit TZID parameter
     * (e.g. "DTSTART;TZID=Europe/Berlin:20260601T100000"). Returns null if
     * the input lacks a recognisable TZID or the date fails to parse.
     */
    private function parseIcsDateWithTimezone(string $dateStr): ?DateTimeImmutable
    {
        if (!str_contains($dateStr, ';TZID=') || !preg_match('/;TZID=([^:]+):(.+)$/', $dateStr, $m)) {
            return null;
        }
        try {
            $tz = new DateTimeZone($m[1]);
            $parsed = DateTimeImmutable::createFromFormat(self::ICS_DATETIME_LOCAL, $m[2], $tz);
        } catch (Throwable) {
            // Invalid TZID — fall back to the generic parser in the caller.
            return null;
        }
        return $parsed instanceof DateTimeImmutable ? $parsed->setTimezone($tz) : null;
    }

    /**
     * Last-resort parser that hands the string to DateTimeImmutable's
     * native constructor. Returns null on parse failure.
     */
    private function parseIcsDateGeneric(string $dateStr): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($dateStr);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param list<LibXMLError> $loadErrors
     */
    private function malformedXmlResult(array $loadErrors): ToolResult
    {
        $firstError = $loadErrors[0] ?? null;
        $detail = $firstError instanceof LibXMLError ? trim($firstError->message) : 'malformed XML';
        return new ToolResult(false, "CalDAV response could not be parsed: {$detail}");
    }

    /**
     * @return list<array{event_uri: ?string, uid: string, summary: string, dtstart: string, dtend: string}>
     */
    private function collectEventsFromMultistatus(DOMDocument $parser): array
    {
        $xpath = $this->makeXPath($parser);
        $calendarDataNodes = $xpath->query('//c:calendar-data');
        if ($calendarDataNodes === false || $calendarDataNodes->length === 0) {
            return [];
        }
        return $this->collectEvents($calendarDataNodes, $xpath);
    }
}
