<?php

declare(strict_types=1);

namespace Spora\Tools;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Spora\Services\ToolConfigService;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\CalDav\CalDavClient;
use Spora\Tools\CalDav\CalDavResponseMapper;
use Spora\Tools\CalDav\EventDateRange;
use Spora\Tools\CalDav\IcsBuilder;
use Spora\Tools\CalDav\IcsParser;
use Spora\Tools\ValueObjects\ToolResult;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

/**
 * CalDAV calendar tool supporting list, get, create, edit, and delete operations.
 * Compatible with CalDAV servers like Nextcloud and Baikal.
 *
 * HTTP transport is in CalDavClient; iCalendar payload construction is in
 * IcsBuilder; iCalendar/XML response parsing is in IcsParser; HTTP status
 * mapping and central request dispatch is in CalDavResponseMapper. This
 * class owns the operation semantics and argument validation only.
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
    private const ERR_CONFIG_INCOMPLETE = 'CalDAV configuration is incomplete or missing.';
    private const ERR_EVENT_NOT_FOUND   = 'Event not found.';
    private const ERR_MISSING_EVENT_URI = 'Missing required parameter: event_uri';

    public function __construct(
        private readonly ToolConfigService $configService,
        \Symfony\Contracts\HttpClient\HttpClientInterface $httpClient,
        ?LoggerInterface $logger = null,
        array $appConfig = [],
    ) {
        $this->client = new CalDavClient($httpClient, $logger);
        $this->builder = new IcsBuilder($appConfig);
        $this->parser = new IcsParser();
        $this->mapper = new CalDavResponseMapper($this->client, $logger);
    }

    private readonly CalDavClient $client;
    private readonly IcsBuilder    $builder;
    private readonly IcsParser     $parser;
    private readonly CalDavResponseMapper $mapper;

    public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
    {
        $operation = $this->getOperationName($arguments);

        return match ($operation) {
            'list_events'  => $this->listEvents($arguments, $agentId, $userId),
            'get_event'    => $this->getEvent($arguments, $agentId, $userId),
            'create_event' => $this->createEvent($arguments, $agentId, $userId),
            'edit_event'   => $this->editEvent($arguments, $agentId, $userId),
            'delete_event' => $this->deleteEvent($arguments, $agentId, $userId),
            default        => new ToolResult(false, "Unknown operation: {$operation}"),
        };
    }

    public function describeAction(array $arguments): string
    {
        $operation = $this->getOperationName($arguments);

        return match ($operation) {
            'list_events'  => 'Fetch CalDAV calendar events',
            'get_event'    => 'Get a specific CalDAV calendar event',
            'create_event' => 'Create a new CalDAV calendar event',
            'edit_event'   => 'Edit an existing CalDAV calendar event',
            'delete_event' => 'Delete a CalDAV calendar event',
            default        => 'Unknown CalDAV operation',
        };
    }

    public function listEvents(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $startDateStr = $arguments['start_date'] ?? '';
        $endDateStr   = $arguments['end_date'] ?? '';
        if ($startDateStr === '' || $endDateStr === '') {
            return new ToolResult(false, 'Missing start_date or end_date parameters.');
        }

        $dates = $this->parseDateRange($startDateStr, $endDateStr);
        if ($dates instanceof ToolResult) {
            return $dates;
        }

        $config = $this->loadBaseConfig($agentId, $userId);
        if ($config instanceof ToolResult) {
            return $config;
        }

        $requestOptions = [
            'headers' => [
                'Depth'        => '1',
                'Content-Type' => 'application/xml; charset=utf-8',
            ],
            'auth_basic' => [$config['username'], $config['password']],
            'body'       => $this->buildReportXml($dates[0], $dates[1]),
            'timeout'    => $this->client->effectiveTimeout($config['settings']),
        ];

        return $this->mapper->runHttp(
            'REPORT',
            $config['url'],
            $requestOptions,
            fn(ResponseInterface $r) => $this->parser->parseListResponse($r->getContent()),
            'Failed to fetch CalDAV calendar',
        );
    }

    public function getEvent(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $eventUri = trim((string) ($arguments['event_uri'] ?? ''));
        if ($eventUri === '') {
            return new ToolResult(false, self::ERR_MISSING_EVENT_URI);
        }

        $config = $this->loadBaseConfig($agentId, $userId);
        if ($config instanceof ToolResult) {
            return $config;
        }

        $eventUri = $this->client->resolveEventUri($eventUri, $config['url']);
        $requestOptions = [
            'headers'    => ['Accept' => 'text/calendar'],
            'auth_basic' => [$config['username'], $config['password']],
            'timeout'    => $this->client->effectiveTimeout($config['settings']),
        ];

        return $this->mapper->runHttp(
            'GET',
            $eventUri,
            $requestOptions,
            function (ResponseInterface $r) use ($eventUri) {
                return $this->mapper->handleGetResponse($r, $eventUri, fn(string $ics, string $uri, ?string $etag) => $this->parser->parseEventForGet($ics, $uri, $etag));
            },
            'Failed to fetch CalDAV event',
            fn(ResponseInterface $r, int $code) => $this->mapper->handleGetError($code),
        );
    }

    public function createEvent(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $inputs = $this->parseCreateInputs($arguments);
        if ($inputs instanceof ToolResult) {
            return $inputs;
        }

        $dates = $this->parseCreateDates($inputs);
        if ($dates instanceof ToolResult) {
            return $dates;
        }

        $config = $this->loadBaseConfig($agentId, $userId);
        if ($config instanceof ToolResult) {
            return $config;
        }

        $eventUri  = rtrim($config['url'], '/') . '/' . ltrim($this->builder->generateEventFilename($inputs['summary'], $dates['start']), '/');
        $icsContent = $this->builder->buildIcs(
            $this->builder->generateUid($agentId),
            $inputs['summary'],
            $inputs['description'],
            $inputs['location'],
            new EventDateRange(
                start: $dates['start'],
                end: $dates['end'],
                timezone: $inputs['timezone'],
                allDay: $inputs['allDay'],
            ),
        );

        $requestOptions = [
            'headers'    => ['Content-Type' => 'text/calendar; charset=utf-8'],
            'auth_basic' => [$config['username'], $config['password']],
            'body'       => $icsContent,
            'timeout'    => $this->client->effectiveTimeout($config['settings']),
        ];

        return $this->mapper->runHttp(
            'PUT',
            $eventUri,
            $requestOptions,
            fn(ResponseInterface $r) => $this->mapper->handleCreateResponse($r, $eventUri, $inputs['summary']),
            'Failed to create CalDAV event',
            fn(ResponseInterface $r, int $code) => $this->mapper->handleCreateError($code),
        );
    }

    public function editEvent(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $inputs = $this->parseEditInputs($arguments);
        if ($inputs instanceof ToolResult) {
            return $inputs;
        }

        $config = $this->loadBaseConfig($agentId, $userId);
        if ($config instanceof ToolResult) {
            return $config;
        }

        $eventUri = $this->client->resolveEventUri($inputs['eventUri'], $config['url']);
        $existing = $this->fetchExistingEvent($eventUri, $config);
        if ($existing instanceof ToolResult) {
            return $existing;
        }

        $updates = $this->buildEditUpdates($arguments, $existing, $inputs['timezone'], $inputs['allDay']);
        if ($updates instanceof ToolResult) {
            return $updates;
        }

        $icsContent = $this->builder->buildIcs(
            $updates['uid'] ?? $this->builder->generateUid($agentId),
            $updates['summary'],
            $updates['description'],
            $updates['location'],
            new EventDateRange(
                start: $updates['start'],
                end: $updates['end'],
                timezone: $inputs['timezone'],
                allDay: $inputs['allDay'],
            ),
        );

        $requestOptions = [
            'headers'    => [
                'Content-Type' => 'text/calendar; charset=utf-8',
                'If-Match'    => $inputs['etag'],
            ],
            'auth_basic' => [$config['username'], $config['password']],
            'body'       => $icsContent,
            'timeout'    => $this->client->effectiveTimeout($config['settings']),
        ];

        return $this->mapper->runHttp(
            'PUT',
            $eventUri,
            $requestOptions,
            fn(ResponseInterface $r) => $this->mapper->handlePutResponse($r, $eventUri, $updates['summary']),
            'Failed to update CalDAV event',
            fn(ResponseInterface $r, int $code) => $this->mapper->handlePutError($code),
        );
    }

    public function deleteEvent(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $eventUri = trim((string) ($arguments['event_uri'] ?? ''));
        if ($eventUri === '') {
            return new ToolResult(false, self::ERR_MISSING_EVENT_URI);
        }

        $config = $this->loadBaseConfig($agentId, $userId);
        if ($config instanceof ToolResult) {
            return $config;
        }

        $eventUri = $this->client->resolveEventUri($eventUri, $config['url']);
        $etag = $this->client->normalizeEtag(trim((string) ($arguments['etag'] ?? '')));

        $headers = [];
        if ($etag !== '') {
            $headers['If-Match'] = $etag;
        }
        $requestOptions = [
            'headers'    => $headers,
            'auth_basic' => [$config['username'], $config['password']],
            'timeout'    => $this->client->effectiveTimeout($config['settings']),
        ];

        return $this->mapper->runHttp(
            'DELETE',
            $eventUri,
            $requestOptions,
            fn(ResponseInterface $r) => $this->mapper->handleDeleteResponse(),
            'Failed to delete CalDAV event',
            fn(ResponseInterface $r, int $code) => $this->mapper->handlePutError($code),
        );
    }

    /**
     * @return array{eventUri: string, etag: string, timezone: string, allDay: bool}|ToolResult
     */
    private function parseEditInputs(array $arguments): array|ToolResult
    {
        $eventUri = trim((string) ($arguments['event_uri'] ?? ''));
        if ($eventUri === '') {
            return new ToolResult(false, self::ERR_MISSING_EVENT_URI);
        }

        $etag = $this->client->normalizeEtag(trim((string) ($arguments['etag'] ?? '')));
        if ($etag === '') {
            return new ToolResult(false, 'Missing required parameter: etag (required for safe updates)');
        }

        return [
            'eventUri' => $eventUri,
            'etag'     => $etag,
            'timezone' => trim((string) ($arguments['timezone'] ?? '')),
            'allDay'   => (bool) ($arguments['all_day'] ?? false),
        ];
    }

    /**
     * @return array{summary: string, start_date: string, end_date: string, description: string, location: string, timezone: string, allDay: bool}|ToolResult
     */
    private function parseCreateInputs(array $arguments): array|ToolResult
    {
        $summary     = trim((string) ($arguments['summary'] ?? ''));
        $startDateStr = (string) ($arguments['start_date'] ?? '');
        $endDateStr   = (string) ($arguments['end_date'] ?? '');

        if ($summary === '' || $startDateStr === '' || $endDateStr === '') {
            return new ToolResult(false, 'Missing required parameters: summary, start_date, or end_date');
        }

        return [
            'summary'     => $summary,
            'start_date'  => $startDateStr,
            'end_date'    => $endDateStr,
            'description' => trim((string) ($arguments['description'] ?? '')),
            'location'    => trim((string) ($arguments['location'] ?? '')),
            'timezone'    => trim((string) ($arguments['timezone'] ?? '')),
            'allDay'      => (bool) ($arguments['all_day'] ?? false),
        ];
    }

    /**
     * @param array{summary: string, start_date: string, end_date: string, description: string, location: string, timezone: string, allDay: bool} $inputs
     * @return array{start: DateTimeImmutable, end: DateTimeImmutable}|ToolResult
     */
    private function parseCreateDates(array $inputs): array|ToolResult
    {
        try {
            $start = $this->builder->parseEventDate($inputs['start_date'], $inputs['timezone'], $inputs['allDay']);
            $end   = $this->builder->parseEventDate($inputs['end_date'], $inputs['timezone'], $inputs['allDay']);
        } catch (Throwable $e) {
            return new ToolResult(false, 'Invalid date format: ' . $e->getMessage());
        }

        if ($end <= $start) {
            return new ToolResult(false, 'end_date must be after start_date.');
        }
        return ['start' => $start, 'end' => $end];
    }

    /**
     * @return array{url: string, username: string, password: string, settings: array<string, mixed>}|ToolResult
     */
    private function loadBaseConfig(int $agentId, ?int $userId): array|ToolResult
    {
        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);
        $url      = rtrim((string) ($settings['core.caldav.url'] ?? ''), '/');
        $username = (string) ($settings['core.caldav.username'] ?? '');
        $password = (string) ($settings['core.caldav.password'] ?? '');

        if ($url === '' || $username === '' || $password === '') {
            return new ToolResult(false, self::ERR_CONFIG_INCOMPLETE);
        }

        return [
            'url'      => $url,
            'username' => $username,
            'password' => $password,
            'settings' => $settings,
        ];
    }

    /**
     * @return array{0: string, 1: string}|ToolResult
     *   The two formatted UTC date strings used in the REPORT XML, or a failure ToolResult.
     */
    private function parseDateRange(string $startDateStr, string $endDateStr): array|ToolResult
    {
        try {
            $start = new DateTimeImmutable($startDateStr);
            $end   = new DateTimeImmutable($endDateStr);
        } catch (Throwable) {
            return new ToolResult(false, 'Invalid date format provided. Must be ISO-8601.');
        }
        $startFormatted = $start->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
        $endFormatted   = $end->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
        return [$startFormatted, $endFormatted];
    }

    private function buildReportXml(string $startFormatted, string $endFormatted): string
    {
        return <<<XML
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
    }

    /**
     * @param array{url: string, username: string, password: string, settings: array<string, mixed>} $config
     * @return array<string, mixed>|ToolResult Flattened existing event data, or failure ToolResult.
     */
    private function fetchExistingEvent(string $eventUri, array $config): array|ToolResult
    {
        $requestOptions = [
            'headers'    => ['Accept' => 'text/calendar'],
            'auth_basic' => [$config['username'], $config['password']],
            'timeout'    => $this->client->effectiveTimeout($config['settings']),
        ];

        $response = $this->client->request('GET', $eventUri, $requestOptions);
        $statusCode = $response->getStatusCode();
        if ($statusCode === 404) {
            return new ToolResult(false, self::ERR_EVENT_NOT_FOUND);
        }
        if ($statusCode >= 400) {
            return new ToolResult(false, 'Failed to fetch existing event: HTTP ' . $statusCode);
        }

        return $this->parser->parseEventForEdit($response->getContent());
    }

    /**
     * @param array<string, mixed> $existingData
     * @return array{uid: ?string, summary: string, start: DateTimeImmutable, end: DateTimeImmutable, description: string, location: string}|ToolResult
     */
    private function buildEditUpdates(array $arguments, array $existingData, string $timezone, bool $allDay): array|ToolResult
    {
        $dates = $this->resolveEditDates($arguments, $existingData, $timezone, $allDay);
        if ($dates instanceof ToolResult) {
            return $dates;
        }

        if ($dates['end'] <= $dates['start']) {
            return new ToolResult(false, 'end_date must be after start_date.');
        }

        return [
            'uid'         => $existingData['uid'] ?: null,
            'summary'     => !empty($arguments['summary']) ? trim((string) $arguments['summary']) : (string) $existingData['summary'],
            'start'       => $dates['start'],
            'end'         => $dates['end'],
            'description' => !empty($arguments['description']) ? trim((string) $arguments['description']) : (string) $existingData['description'],
            'location'    => !empty($arguments['location']) ? trim((string) $arguments['location']) : (string) $existingData['location'],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $existingData
     * @return array{start: DateTimeImmutable, end: DateTimeImmutable}|ToolResult
     */
    private function resolveEditDates(array $arguments, array $existingData, string $timezone, bool $allDay): array|ToolResult
    {
        try {
            $start = !empty($arguments['start_date'])
                ? $this->builder->parseEventDate((string) $arguments['start_date'], $timezone, $allDay)
                : $existingData['dtstart'];
            $end = !empty($arguments['end_date'])
                ? $this->builder->parseEventDate((string) $arguments['end_date'], $timezone, $allDay)
                : $existingData['dtend'];
        } catch (Throwable $e) {
            return new ToolResult(false, 'Invalid date format: ' . $e->getMessage());
        }

        if (!$start instanceof DateTimeImmutable || !$end instanceof DateTimeImmutable) {
            return new ToolResult(false, 'Failed to parse existing event dates. Fetch the latest event details and verify DTSTART/DTEND are present.');
        }
        return ['start' => $start, 'end' => $end];
    }
}
