<?php

declare(strict_types=1);

namespace Spora\Tools;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMXPath;
use Icalendar\Component\VCalendar;
use Icalendar\Component\VEvent;
use Icalendar\Parser\Parser;
use Icalendar\Writer\Writer;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Spora\Services\ToolConfigService;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\ValueObjects\ToolResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * CalDAV calendar tool supporting list, get, create, edit, and delete operations.
 * Compatible with CalDAV servers like Nextcloud and Baikal.
 */
#[Tool(
    name: 'calendar',
    description: 'Manage calendar events on a CalDAV-compatible server (e.g. Nextcloud, Baikal). Supports listing, viewing, creating, editing, and deleting events.',
    displayName: 'Calendar',
    category: 'productivity',
)]
#[ToolOperation(name: 'list_events', description: 'Fetch upcoming events from CalDAV calendar within a date range', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'get_event', description: 'Get details of a specific event by its CalDAV URI', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'create_event', description: 'Create a new event on the CalDAV calendar', enabledByDefault: true, requiresApprovalByDefault: true)]
#[ToolOperation(name: 'edit_event', description: 'Edit an existing event on the CalDAV calendar', enabledByDefault: true, requiresApprovalByDefault: true)]
#[ToolOperation(name: 'delete_event', description: 'Delete an event from the CalDAV calendar', enabledByDefault: true, requiresApprovalByDefault: true)]
#[ToolSetting(key: 'core.caldav.url', label: 'CalDAV URL', type: 'text', description: 'URL to the Calendar server (e.g. Nextcloud, Baikal)', )]
#[ToolSetting(key: 'core.caldav.username', label: 'Username', type: 'text', description: 'CalDAV username', )]
#[ToolSetting(key: 'core.caldav.password', label: 'Password', type: 'password', description: 'CalDAV password or app token', required: true)]
#[ToolSetting(
    key: 'core.caldav.http_timeout',
    label: 'HTTP Timeout',
    type: 'text',
    description: 'Seconds before an HTTP request fails (default: 30)',
)]
// Parameter declaration order matches the hand-rolled schema so the approval UI
// renders fields in the same sequence. `action` is auto-synthesized.
#[ToolParameter(name: 'start_date', type: 'string', description: 'Start date in ISO-8601 format (or YYYY-MM-DD for all_day events)', required: false)]
#[ToolParameter(name: 'end_date', type: 'string', description: 'End date in ISO-8601 format (or YYYY-MM-DD for all_day events)', required: false)]
#[ToolParameter(name: 'event_uri', type: 'string', description: 'The CalDAV URI of the event (required for get_event, edit_event, delete_event)', required: false)]
#[ToolParameter(name: 'etag', type: 'string', description: 'The ETag of the event (required for edit_event, optional for delete_event)', required: false)]
#[ToolParameter(name: 'summary', type: 'string', description: 'Event title/summary (required for create_event)', required: false)]
#[ToolParameter(name: 'description', type: 'string', description: 'Event description (optional)', required: false)]
#[ToolParameter(name: 'location', type: 'string', description: 'Event location (optional)', required: false)]
#[ToolParameter(name: 'timezone', type: 'string', description: 'IANA timezone identifier (e.g. Europe/Berlin). Optional for create_event and edit_event.', required: false)]
#[ToolParameter(name: 'all_day', type: 'boolean', description: 'If true, start_date and end_date are interpreted as date-only (YYYY-MM-DD) and the event is an all-day event. Optional.', required: false)]
final class CalDavCalendarTool extends AbstractTool
{
    public function __construct(
        private readonly ToolConfigService $configService,
        private readonly HttpClientInterface $httpClient,
        private readonly ?LoggerInterface $logger = null,
        private readonly array $appConfig = [],
    ) {}

    private function effectiveTimeout(array $settings): int
    {
        if (isset($settings['core.caldav.http_timeout']) && (int) $settings['core.caldav.http_timeout'] > 0) {
            return (int) $settings['core.caldav.http_timeout'];
        }
        $envTimeout = (int) ($_ENV['SPORA_TOOL_HTTP_TIMEOUT'] ?? getenv('SPORA_TOOL_HTTP_TIMEOUT') ?: 0);
        return $envTimeout > 0 ? $envTimeout : 30;
    }

    private function logHttpRequest(string $method, string $url, array $options): void
    {
        $this->logger?->debug('CalDavCalendarTool: HTTP request', [
            'method' => $method,
            'url' => $url,
            'headers' => $options['headers'] ?? [],
            'auth_basic' => isset($options['auth_basic']) ? [$options['auth_basic'][0], '***'] : null,
            'timeout' => $options['timeout'] ?? null,
        ]);
    }

    private function logHttpResponse(string $method, string $url, int $statusCode, array $headers = []): void
    {
        $this->logger?->debug('CalDavCalendarTool: HTTP response', [
            'method' => $method,
            'url' => $url,
            'status_code' => $statusCode,
            'www_authenticate' => $headers['www-authenticate'][0] ?? null,
        ]);
    }

    private function logHttpError(string $method, string $url, int $statusCode, string $responseBody, array $headers = []): void
    {
        $this->logger?->error('CalDAV HTTP Error', [
            'method' => $method,
            'url' => $url,
            'status_code' => $statusCode,
            'response' => $responseBody,
            'www_authenticate' => $headers['www-authenticate'][0] ?? null,
        ]);
    }

    /**
     * Resolve a CalDAV event URI returned by the server against the configured base URL.
     *
     * The CalDAV server returns event URIs as <d:href> values. These are typically
     * path-relative (start with /) or fully-qualified. We need to combine them with
     * the base URL to make valid HTTP requests.
     *
     * Examples:
     * - base: https://caldav.web.de/begenda/dav/grassl-fabian@web.de/calendar
     * - href: /begenda/dav/f0d38415.../calendar/event.ics
     *   -> resolved: https://caldav.web.de/begenda/dav/f0d38415.../calendar/event.ics
     *
     * - base: https://cal.example.com/calendars/user/events
     * - href: /calendars/user/events/abc.ics
     *   -> resolved: https://cal.example.com/calendars/user/events/abc.ics
     */
    private function resolveEventUri(string $eventUri, string $baseUrl): string
    {
        if (str_starts_with($eventUri, 'http://') || str_starts_with($eventUri, 'https://')) {
            return $eventUri;
        }

        if (empty($baseUrl)) {
            return $eventUri;
        }

        // Parse the base URL to extract its origin (scheme + host + port)
        $parsed = parse_url($baseUrl);
        if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
            return $eventUri;
        }

        $origin = $parsed['scheme'] . '://' . $parsed['host']
            . (isset($parsed['port']) ? ':' . $parsed['port'] : '');

        // Ensure exactly one "/" between origin and path
        $path = '/' . ltrim($eventUri, '/');

        return $origin . $path;
    }

    /**
     * Normalize an ETag value to the RFC 7232 quoted form.
     *
     * The UI or LLM may pass an ETag without the surrounding double quotes
     * (e.g. "abc123" → abc123). Servers expect the entity-tag form: DQUOTE *etagc DQUOTE.
     * This helper wraps the value in quotes if not already quoted.
     */
    private function normalizeEtag(string $etag): string
    {
        $etag = trim($etag);
        if ($etag === '') {
            return '';
        }
        if (str_starts_with($etag, 'W/')) {
            // Weak ETag (W/"abc123") - normalize the inner part
            $inner = substr($etag, 2);
            if (!str_starts_with($inner, '"')) {
                $inner = '"' . $inner;
            }
            if (!str_ends_with($inner, '"')) {
                $inner = $inner . '"';
            }
            return 'W/' . $inner;
        }
        if (!str_starts_with($etag, '"')) {
            $etag = '"' . $etag;
        }
        if (!str_ends_with($etag, '"')) {
            $etag = $etag . '"';
        }
        return $etag;
    }

    public function execute(array $arguments, int $agentId, ?int $userId = null): ToolResult
    {
        $operation = $this->getOperationName($arguments);

        return match ($operation) {
            'list_events' => $this->listEvents($arguments, $agentId, $userId),
            'get_event' => $this->getEvent($arguments, $agentId, $userId),
            'create_event' => $this->createEvent($arguments, $agentId, $userId),
            'edit_event' => $this->editEvent($arguments, $agentId, $userId),
            'delete_event' => $this->deleteEvent($arguments, $agentId, $userId),
            default => new ToolResult(false, "Unknown operation: {$operation}"),
        };
    }

    public function describeAction(array $arguments): string
    {
        $operation = $this->getOperationName($arguments);

        return match ($operation) {
            'list_events' => 'Fetch CalDAV calendar events',
            'get_event' => 'Get a specific CalDAV calendar event',
            'create_event' => 'Create a new CalDAV calendar event',
            'edit_event' => 'Edit an existing CalDAV calendar event',
            'delete_event' => 'Delete a CalDAV calendar event',
            default => 'Unknown CalDAV operation',
        };
    }

    public function listEvents(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $startDateStr = $arguments['start_date'] ?? '';
        $endDateStr   = $arguments['end_date'] ?? '';

        if (empty($startDateStr) || empty($endDateStr)) {
            return new ToolResult(false, 'Missing start_date or end_date parameters.');
        }

        try {
            $start = new DateTimeImmutable($startDateStr);
            $end   = new DateTimeImmutable($endDateStr);
            $startFormatted = $start->setTimezone(new DateTimeZone('UTC'))->format("Ymd\THis\Z");
            $endFormatted   = $end->setTimezone(new DateTimeZone('UTC'))->format("Ymd\THis\Z");
        } catch (Throwable $e) {
            return new ToolResult(false, 'Invalid date format provided. Must be ISO-8601.');
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);
        $url      = rtrim((string) ($settings['core.caldav.url'] ?? ''), '/');
        $username = $settings['core.caldav.username'] ?? '';
        $password = $settings['core.caldav.password'] ?? '';

        if (empty($url) || empty($username) || empty($password)) {
            return new ToolResult(false, 'CalDAV configuration is incomplete or missing.');
        }

        $xmlRequest = <<<XML
<?xml version="1.0" encoding="utf-8" ?>
<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
    <d:prop>
        <d:getetag />
        <c:calendar-data />
    </d:prop>
    <c:filter>
        <c:comp-filter name="VCALENDAR">
            <c:comp-filter name="VEVENT">
                <c:time-range start="{$startFormatted}" end="{$endFormatted}"/>
            </c:comp-filter>
        </c:comp-filter>
    </c:filter>
</c:calendar-query>
XML;

        try {
            $requestOptions = [
                'headers' => [
                    'Depth'        => '1',
                    'Content-Type' => 'application/xml; charset=utf-8',
                ],
                'auth_basic' => [$username, $password],
                'body'       => $xmlRequest,
                'timeout'    => $this->effectiveTimeout($settings),
            ];

            $this->logHttpRequest('REPORT', $url, $requestOptions);

            $response = $this->httpClient->request('REPORT', $url, $requestOptions);

            $headers = $response->getHeaders(false);
            $this->logHttpResponse('REPORT', $url, $response->getStatusCode(), $headers);

            if ($response->getStatusCode() >= 400) {
                $errorMsg = $response->getContent(false);
                $this->logHttpError('REPORT', $url, $response->getStatusCode(), $errorMsg, $headers);
                return new ToolResult(false, "CalDAV server returned HTTP {$response->getStatusCode()}");
            }

            $xmlBody = $response->getContent();
            return $this->parseCalDavResponse($xmlBody);

        } catch (Throwable $e) {
            $this->logger?->error('CalDAV Exception', ['exception' => $e, 'method' => 'REPORT', 'url' => $url]);
            return new ToolResult(false, 'Failed to fetch CalDAV calendar: ' . $e->getMessage());
        }
    }

    public function getEvent(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $eventUri = trim((string) ($arguments['event_uri'] ?? ''));

        if (empty($eventUri)) {
            return new ToolResult(false, 'Missing required parameter: event_uri');
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);
        $username = $settings['core.caldav.username'] ?? '';
        $password = $settings['core.caldav.password'] ?? '';
        $baseUrl = rtrim((string) ($settings['core.caldav.url'] ?? ''), '/');

        if (empty($username) || empty($password)) {
            return new ToolResult(false, 'CalDAV configuration is incomplete or missing.');
        }

        $eventUri = $this->resolveEventUri($eventUri, $baseUrl);

        try {
            $requestOptions = [
                'headers' => ['Accept' => 'text/calendar'],
                'auth_basic' => [$username, $password],
                'timeout'    => $this->effectiveTimeout($settings),
            ];

            $this->logHttpRequest('GET', $eventUri, $requestOptions);

            $response = $this->httpClient->request('GET', $eventUri, $requestOptions);

            $headers = $response->getHeaders(false);
            $this->logHttpResponse('GET', $eventUri, $response->getStatusCode(), $headers);

            $statusCode = $response->getStatusCode();

            if ($statusCode === 404) {
                return new ToolResult(false, 'Event not found.');
            }

            if ($statusCode >= 400) {
                $errorMsg = $response->getContent(false);
                $this->logHttpError('GET', $eventUri, $statusCode, $errorMsg, $headers);
                return new ToolResult(false, "CalDAV server returned HTTP {$statusCode}");
            }

            $icsContent = $response->getContent();
            $etag = $headers['etag'][0] ?? null;

            return $this->parseIcsForGet($icsContent, $eventUri, $etag);

        } catch (Throwable $e) {
            $this->logger?->error('CalDAV Exception', ['exception' => $e, 'method' => 'GET', 'url' => $eventUri]);
            return new ToolResult(false, 'Failed to fetch CalDAV event: ' . $e->getMessage());
        }
    }

    public function createEvent(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $summary = trim((string) ($arguments['summary'] ?? ''));
        $startDateStr = $arguments['start_date'] ?? '';
        $endDateStr = $arguments['end_date'] ?? '';
        $description = trim((string) ($arguments['description'] ?? ''));
        $location = trim((string) ($arguments['location'] ?? ''));
        $timezone = trim((string) ($arguments['timezone'] ?? ''));
        $allDay = (bool) ($arguments['all_day'] ?? false);

        if (empty($summary) || empty($startDateStr) || empty($endDateStr)) {
            return new ToolResult(false, 'Missing required parameters: summary, start_date, or end_date');
        }

        try {
            $start = $this->parseEventDate($startDateStr, $timezone, $allDay);
            $end = $this->parseEventDate($endDateStr, $timezone, $allDay);
        } catch (Throwable $e) {
            return new ToolResult(false, 'Invalid date format: ' . $e->getMessage());
        }

        if ($end <= $start) {
            return new ToolResult(false, 'end_date must be after start_date.');
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);
        $url      = rtrim((string) ($settings['core.caldav.url'] ?? ''), '/');
        $username = $settings['core.caldav.username'] ?? '';
        $password = $settings['core.caldav.password'] ?? '';

        if (empty($url) || empty($username) || empty($password)) {
            return new ToolResult(false, 'CalDAV configuration is incomplete or missing.');
        }

        // Generate unique ID and filename
        $uid = $this->generateUid($agentId);
        $filename = $this->generateEventFilename($summary, $start);
        $eventUri = rtrim($url, '/') . '/' . ltrim($filename, '/');

        // Generate ICS content using craigk5n/php-icalendar-core
        $icsContent = $this->generateIcsContent($uid, $summary, $start, $end, $description, $location, $timezone, $allDay);

        try {
            $requestOptions = [
                'headers' => [
                    'Content-Type' => 'text/calendar; charset=utf-8',
                ],
                'auth_basic' => [$username, $password],
                'body'       => $icsContent,
                'timeout'    => $this->effectiveTimeout($settings),
            ];

            $this->logHttpRequest('PUT', $eventUri, $requestOptions);

            $response = $this->httpClient->request('PUT', $eventUri, $requestOptions);

            $headers = $response->getHeaders(false);
            $this->logHttpResponse('PUT', $eventUri, $response->getStatusCode(), $headers);

            $statusCode = $response->getStatusCode();

            if ($statusCode === 201) {
                $etag = $headers['etag'][0] ?? null;
                return new ToolResult(true, "Event '{$summary}' created successfully.", [
                    'event_uri' => $eventUri,
                    'etag' => $etag,
                ]);
            }

            if ($statusCode === 415) {
                return new ToolResult(false, 'Calendar server does not support event creation (unsupported media type).');
            }

            if ($statusCode >= 400) {
                $errorMsg = $response->getContent(false);
                $this->logHttpError('PUT', $eventUri, $statusCode, $errorMsg, $headers);
                return new ToolResult(false, "CalDAV server returned HTTP {$statusCode}");
            }

            return new ToolResult(true, "Event '{$summary}' created successfully.");

        } catch (Throwable $e) {
            $this->logger?->error('CalDAV Exception', ['exception' => $e, 'method' => 'PUT', 'url' => $eventUri]);
            return new ToolResult(false, 'Failed to create CalDAV event: ' . $e->getMessage());
        }
    }

    public function editEvent(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $eventUri = trim((string) ($arguments['event_uri'] ?? ''));
        $etag = $this->normalizeEtag(trim((string) ($arguments['etag'] ?? '')));

        if (empty($eventUri)) {
            return new ToolResult(false, 'Missing required parameter: event_uri');
        }

        if (empty($etag)) {
            return new ToolResult(false, 'Missing required parameter: etag (required for safe updates)');
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);
        $url      = rtrim((string) ($settings['core.caldav.url'] ?? ''), '/');
        $username = $settings['core.caldav.username'] ?? '';
        $password = $settings['core.caldav.password'] ?? '';

        if (empty($url) || empty($username) || empty($password)) {
            return new ToolResult(false, 'CalDAV configuration is incomplete or missing.');
        }

        $eventUri = $this->resolveEventUri($eventUri, $url);

        // First, fetch the existing event to get current data
        try {
            $getRequestOptions = [
                'headers' => ['Accept' => 'text/calendar'],
                'auth_basic' => [$username, $password],
                'timeout'    => $this->effectiveTimeout($settings),
            ];

            $this->logHttpRequest('GET', $eventUri, $getRequestOptions);

            $getResponse = $this->httpClient->request('GET', $eventUri, $getRequestOptions);

            $getHeaders = $getResponse->getHeaders(false);
            $this->logHttpResponse('GET', $eventUri, $getResponse->getStatusCode(), $getHeaders);

            if ($getResponse->getStatusCode() === 404) {
                return new ToolResult(false, 'Event not found.');
            }

            if ($getResponse->getStatusCode() >= 400) {
                $errorMsg = $getResponse->getContent(false);
                $this->logHttpError('GET', $eventUri, $getResponse->getStatusCode(), $errorMsg, $getHeaders);
                return new ToolResult(false, 'Failed to fetch existing event: HTTP ' . $getResponse->getStatusCode());
            }

            $existingIcs = $getResponse->getContent();
        } catch (Throwable $e) {
            $this->logger?->error('CalDAV Exception', ['exception' => $e, 'method' => 'GET', 'url' => $eventUri]);
            return new ToolResult(false, 'Failed to fetch existing event: ' . $e->getMessage());
        }

        // Parse existing event to get UID and merge changes
        $existingData = $this->parseIcsForEdit($existingIcs);

        // Apply updates
        $summary = !empty($arguments['summary']) ? trim($arguments['summary']) : $existingData['summary'];
        $startDateStr = $arguments['start_date'] ?? null;
        $endDateStr = $arguments['end_date'] ?? null;
        $timezone = trim((string) ($arguments['timezone'] ?? ''));
        $allDay = (bool) ($arguments['all_day'] ?? false);

        try {
            $start = $startDateStr ? $this->parseEventDate($startDateStr, $timezone, $allDay) : $existingData['dtstart'];
            $end = $endDateStr ? $this->parseEventDate($endDateStr, $timezone, $allDay) : $existingData['dtend'];
        } catch (Throwable $e) {
            return new ToolResult(false, 'Invalid date format: ' . $e->getMessage());
        }

        if (!$start instanceof DateTimeImmutable || !$end instanceof DateTimeImmutable) {
            return new ToolResult(false, 'Failed to parse existing event dates. Fetch the latest event details and verify DTSTART/DTEND are present.');
        }

        if ($end <= $start) {
            return new ToolResult(false, 'end_date must be after start_date.');
        }

        $description = !empty($arguments['description']) ? trim($arguments['description']) : $existingData['description'];
        $location = !empty($arguments['location']) ? trim($arguments['location']) : $existingData['location'];

        // Generate updated ICS content
        $icsContent = $this->generateIcsContent(
            $existingData['uid'],
            $summary,
            $start,
            $end,
            $description,
            $location,
            $timezone,
            $allDay,
        );

        try {
            $requestOptions = [
                'headers' => [
                    'Content-Type' => 'text/calendar; charset=utf-8',
                    'If-Match'    => $etag,
                ],
                'auth_basic' => [$username, $password],
                'body'       => $icsContent,
                'timeout'    => $this->effectiveTimeout($settings),
            ];

            $this->logHttpRequest('PUT', $eventUri, $requestOptions);

            $response = $this->httpClient->request('PUT', $eventUri, $requestOptions);

            $headers = $response->getHeaders(false);
            $this->logHttpResponse('PUT', $eventUri, $response->getStatusCode(), $headers);

            $statusCode = $response->getStatusCode();

            if ($statusCode === 412) {
                return new ToolResult(false, 'Precondition Failed: The event has been modified since you fetched it. Please fetch the latest version and try again.');
            }

            if ($statusCode === 404) {
                return new ToolResult(false, 'Event not found.');
            }

            if ($statusCode >= 400) {
                $errorMsg = $response->getContent(false);
                $this->logHttpError('PUT', $eventUri, $statusCode, $errorMsg, $headers);
                return new ToolResult(false, "CalDAV server returned HTTP {$statusCode}");
            }

            $newEtag = $headers['etag'][0] ?? null;
            return new ToolResult(true, "Event '{$summary}' updated successfully.", [
                'event_uri' => $eventUri,
                'etag' => $newEtag,
            ]);

        } catch (Throwable $e) {
            $this->logger?->error('CalDAV Exception', ['exception' => $e, 'method' => 'PUT', 'url' => $eventUri]);
            return new ToolResult(false, 'Failed to update CalDAV event: ' . $e->getMessage());
        }
    }

    public function deleteEvent(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $eventUri = trim((string) ($arguments['event_uri'] ?? ''));
        $etag = $this->normalizeEtag(trim((string) ($arguments['etag'] ?? '')));

        if (empty($eventUri)) {
            return new ToolResult(false, 'Missing required parameter: event_uri');
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);
        $username = $settings['core.caldav.username'] ?? '';
        $password = $settings['core.caldav.password'] ?? '';
        $baseUrl = rtrim((string) ($settings['core.caldav.url'] ?? ''), '/');

        if (empty($username) || empty($password)) {
            return new ToolResult(false, 'CalDAV configuration is incomplete or missing.');
        }

        $eventUri = $this->resolveEventUri($eventUri, $baseUrl);

        $requestHeaders = [];
        if (!empty($etag)) {
            $requestHeaders['If-Match'] = $etag;
        }

        try {
            $requestOptions = [
                'headers' => $requestHeaders,
                'auth_basic' => [$username, $password],
                'timeout'    => $this->effectiveTimeout($settings),
            ];

            $this->logHttpRequest('DELETE', $eventUri, $requestOptions);

            $response = $this->httpClient->request('DELETE', $eventUri, $requestOptions);

            $headers = $response->getHeaders(false);
            $this->logHttpResponse('DELETE', $eventUri, $response->getStatusCode(), $headers);

            $statusCode = $response->getStatusCode();

            if ($statusCode === 412) {
                return new ToolResult(false, 'Precondition Failed: The event has been modified since you fetched it. Please fetch the latest version and try again.');
            }

            if ($statusCode === 404) {
                return new ToolResult(false, 'Event not found.');
            }

            if ($statusCode >= 400) {
                $errorMsg = $response->getContent(false);
                $this->logHttpError('DELETE', $eventUri, $statusCode, $errorMsg, $headers);
                return new ToolResult(false, "CalDAV server returned HTTP {$statusCode}");
            }

            return new ToolResult(true, 'Event deleted successfully.');

        } catch (Throwable $e) {
            $this->logger?->error('CalDAV Exception', ['exception' => $e, 'method' => 'DELETE', 'url' => $eventUri]);
            return new ToolResult(false, 'Failed to delete CalDAV event: ' . $e->getMessage());
        }
    }

    private function parseCalDavResponse(string $xmlBody): ToolResult
    {
        // Extract calendar-data elements from CalDAV XML response using XPath
        $parser = new DOMDocument();
        libxml_use_internal_errors(true);
        $parser->loadXML($xmlBody);
        libxml_clear_errors();

        $xpath = new DOMXPath($parser);
        $xpath->registerNamespace('d', 'DAV:');
        $xpath->registerNamespace('c', 'urn:ietf:params:xml:ns:caldav');

        $calendarDataNodes = $xpath->query('//c:calendar-data');

        if ($calendarDataNodes === false || $calendarDataNodes->length === 0) {
            return new ToolResult(true, 'No events found in the specified time range.');
        }

        $eventCount = 0;
        $output = '';
        $eventData = [];

        foreach ($calendarDataNodes as $calData) {
            $icsContent = $calData->textContent;
            if (empty(trim($icsContent))) {
                continue;
            }

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

            // Parse ICS content using the library
            $icsParser = new Parser(Parser::LENIENT);
            $calendar = $icsParser->parse($icsContent);
            $events = array_values($calendar->getComponents('VEVENT'));

            foreach ($events as $event) {
                /** @var VEvent $event */
                $summary = $event->getSummary() ?? 'Unknown Event';
                $dtstart = $event->getDtStart() ?? 'Unknown Start';
                $dtend = $event->getDtEnd() ?? 'Unknown End';
                $uid = $event->getUid() ?? '';

                $eventCount++;
                $output .= "- Event: {$summary}\n  URI:   {$eventUri}\n  UID:   {$uid}\n  Start: {$dtstart}\n  End:   {$dtend}\n\n";

                $eventData[] = [
                    'event_uri' => $eventUri,
                    'uid' => $uid,
                    'summary' => $summary,
                    'dtstart' => $dtstart,
                    'dtend' => $dtend,
                ];
            }
        }

        if ($eventCount === 0) {
            return new ToolResult(true, 'No events found in the specified time range.');
        }

        return new ToolResult(
            true,
            "Found {$eventCount} events:\n\n" . trim($output),
            ['events' => $eventData],
        );
    }

    private function parseIcsForGet(string $icsContent, string $eventUri, ?string $etag): ToolResult
    {
        $parser = new Parser(Parser::LENIENT);
        $calendar = $parser->parse($icsContent);

        $events = array_values($calendar->getComponents('VEVENT'));
        if (empty($events)) {
            return new ToolResult(false, 'No VEVENT found in the calendar data.');
        }

        /** @var VEvent $event */
        $event = $events[0];

        $uid = $event->getUid();
        $summary = $event->getSummary();
        $dtstart = $event->getDtStart();
        $dtend = $event->getDtEnd();
        $description = $event->getDescription();
        $location = $event->getLocation();

        $output = "Event Details:\n";
        $output .= "- URI: {$eventUri}\n";
        if ($uid) {
            $output .= "- UID: {$uid}\n";
        }
        if ($summary) {
            $output .= "- Summary: {$summary}\n";
        }
        if ($dtstart) {
            $output .= "- Start: {$dtstart}\n";
        }
        if ($dtend) {
            $output .= "- End: {$dtend}\n";
        }
        if ($description) {
            $output .= "- Description: {$description}\n";
        }
        if ($location) {
            $output .= "- Location: {$location}\n";
        }
        if ($etag) {
            $output .= "- ETag: {$etag}\n";
        }

        return new ToolResult(true, $output, [
            'event_uri' => $eventUri,
            'uid' => $uid,
            'summary' => $summary,
            'dtstart' => $dtstart,
            'dtend' => $dtend,
            'description' => $description,
            'location' => $location,
            'etag' => $etag,
        ]);
    }

    private function parseIcsForEdit(string $icsContent): array
    {
        $parser = new Parser(Parser::LENIENT);
        $calendar = $parser->parse($icsContent);

        $events = array_values($calendar->getComponents('VEVENT'));
        if (empty($events)) {
            return ['uid' => null, 'summary' => '', 'dtstart' => null, 'dtend' => null, 'description' => '', 'location' => ''];
        }

        /** @var VEvent $event */
        $event = $events[0];

        $uid = $event->getUid() ?? '';
        $summary = $event->getSummary() ?? '';
        $dtstartStr = $event->getDtStart();
        $dtendStr = $event->getDtEnd();
        $description = $event->getDescription() ?? '';
        $location = $event->getLocation() ?? '';

        // Parse dates for edit
        $dtstart = $this->parseIcsDateString($dtstartStr ?? '');
        $dtend = $this->parseIcsDateString($dtendStr ?? '');

        return [
            'uid' => $uid,
            'summary' => $summary,
            'dtstart' => $dtstart,
            'dtend' => $dtend,
            'description' => $description,
            'location' => $location,
        ];
    }

    private function parseIcsDateString(?string $dateStr): ?DateTimeImmutable
    {
        if ($dateStr === null || $dateStr === '') {
            return null;
        }

        if (str_ends_with($dateStr, 'Z')) {
            $parsed = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $dateStr, new DateTimeZone('UTC'));

            return $parsed instanceof DateTimeImmutable
                ? $parsed->setTimezone(new DateTimeZone('UTC'))
                : null;
        }

        if (strlen($dateStr) === 8) {
            $parsed = DateTimeImmutable::createFromFormat('!Ymd', $dateStr);

            return $parsed instanceof DateTimeImmutable ? $parsed : null;
        }

        if (str_contains($dateStr, ';TZID=')) {
            if (preg_match('/;TZID=([^:]+):(.+)$/', $dateStr, $m)) {
                try {
                    $tz = new DateTimeZone($m[1]);
                    $datePart = $m[2];
                    $parsed = DateTimeImmutable::createFromFormat('Ymd\THis', $datePart, $tz);

                    return $parsed instanceof DateTimeImmutable ? $parsed->setTimezone($tz) : null;
                } catch (Throwable) {
                }
            }
        }

        try {
            return new DateTimeImmutable($dateStr);
        } catch (Throwable) {
            return null;
        }
    }

    private function generateIcsContent(
        string $uid,
        string $summary,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        string $description,
        string $location,
        string $timezone = '',
        bool $allDay = false,
    ): string {
        $calendar = new VCalendar();
        $calendar->setProductId('-//Spora//CalDAV Calendar Tool//EN');
        $calendar->setVersion('2.0');
        $calendar->setCalscale('GREGORIAN');

        $event = new VEvent();
        $event->setUid($uid);
        $event->setDtStamp(date('Ymd\THis\Z'));

        if ($allDay) {
            // For all-day events, use date-only format YYYYMMDD.
            $event->setDtStart($start->format('Ymd'));
            $event->setDtEnd($end->format('Ymd'));
        } elseif (!empty($timezone)) {
            // Convert UTC datetime and use TZID parameter via GenericProperty.
            $tz = new DateTimeZone($timezone);
            $localStart = $start->setTimezone($tz);
            $localEnd = $end->setTimezone($tz);
            $this->setDateWithTimezone($event, 'DTSTART', $localStart->format('Ymd\THis'), $timezone);
            $this->setDateWithTimezone($event, 'DTEND', $localEnd->format('Ymd\THis'), $timezone);
        } else {
            // Default: convert to UTC and use Z suffix.
            $event->setDtStart($start->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z'));
            $event->setDtEnd($end->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z'));
        }

        $event->setSummary($summary);

        if (!empty($description)) {
            $event->setDescription($description);
        }

        if (!empty($location)) {
            $event->setLocation($location);
        }

        $calendar->addComponent($event);

        $writer = new Writer();

        return $writer->write($calendar);
    }

    /**
     * Set a date property with a TZID parameter on a VEvent.
     * The craigk5n library does not support TZID parameters out of the box,
     * so we add the property manually with the right parameters.
     */
    private function setDateWithTimezone(VEvent $event, string $propertyName, string $dateValue, string $timezone): void
    {
        $event->removeProperty($propertyName);
        $property = new \Icalendar\Property\GenericProperty(
            $propertyName,
            new \Icalendar\Value\GenericValue($dateValue, 'DATE-TIME'),
            ['TZID' => $timezone],
        );
        $event->addProperty($property);
    }

    /**
     * Parse a user-provided date string for event creation/edit.
     *
     * - For all-day events: expects a date-only string (YYYY-MM-DD).
     * - For timed events with a timezone: applies the IANA timezone.
     * - For timed events without a timezone: uses UTC.
     */
    private function parseEventDate(string $dateStr, string $timezone, bool $allDay): DateTimeImmutable
    {
        if ($allDay) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $dateStr);
            if ($parsed === false) {
                throw new RuntimeException("all_day requires date-only format YYYY-MM-DD, got: {$dateStr}");
            }
            return $parsed;
        }

        if (!empty($timezone)) {
            $tz = new DateTimeZone($timezone);
            $parsed = new DateTimeImmutable($dateStr, $tz);

            return $parsed->setTimezone($tz);
        }

        $utc = new DateTimeZone('UTC');
        $parsed = new DateTimeImmutable($dateStr, $utc);

        return $parsed->setTimezone($utc);
    }

    private function generateUid(int $agentId = 0): string
    {
        // Derive the UID domain from SPORA_APP_URL. Falls back to "@spora" if unset.
        // Never use "localhost" since CalDAV UIDs should be globally unique across servers.
        $domain = $this->resolveUidDomain();

        return sprintf(
            '%s-%d@%s',
            uniqid('', true),
            $agentId,
            $domain,
        );
    }

    /**
     * Derive the domain part of a CalDAV UID from the configured app URL.
     * Returns "spora" as fallback (never "localhost") to keep UIDs globally unique.
     */
    private function resolveUidDomain(): string
    {
        $appUrl = (string) ($this->appConfig['app_url'] ?? '');
        if (!empty($appUrl)) {
            $parsed = parse_url($appUrl);
            if (is_array($parsed) && isset($parsed['host']) && $parsed['host'] !== 'localhost' && $parsed['host'] !== '127.0.0.1') {
                return $parsed['host'];
            }
        }
        return 'spora';
    }

    private function generateEventFilename(string $summary, DateTimeImmutable $start): string
    {
        $slug = preg_replace('/[^a-zA-Z0-9]/', '-', strtolower($summary));
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');
        $timestamp = $start->format('Ymd-His');
        return "{$timestamp}-{$slug}.ics";
    }
}
