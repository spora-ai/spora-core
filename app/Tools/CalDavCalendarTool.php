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
use Spora\Tools\Traits\HasOperations;
use Spora\Tools\ValueObjects\ToolResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

#[Tool(
    name: 'calendar_list_events',
    description: 'Fetch upcoming events from the configured CalDAV calendar within a selected timezone. Always query real-time dates rather than assuming.',
    displayName: 'Calendar',
    category: 'productivity',
)]
#[ToolOperation(name: 'list_events', description: 'Fetch upcoming events from CalDAV calendar', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolSetting(key: 'core.caldav.url', label: 'CalDAV URL', type: 'text', description: 'URL to the Calendar server (e.g. Nextcloud, Baikal)', scope: 'agent')]
#[ToolSetting(key: 'core.caldav.username', label: 'Username', type: 'text', description: 'CalDAV username', scope: 'agent')]
#[ToolSetting(key: 'core.caldav.password', label: 'Password', type: 'password', description: 'CalDAV password or app token', scope: 'agent', required: true)]
#[ToolSetting(
    key: 'core.caldav.http_timeout',
    label: 'HTTP Timeout',
    type: 'text',
    description: 'Seconds before an HTTP request fails (default: 30)',
    scope: 'agent',
)]
#[ToolParameter(
    name: 'start_date',
    type: 'string',
    description: 'Start date in ISO-8601 format (e.g. 2026-04-01T00:00:00Z)',
    required: true,
)]
#[ToolParameter(
    name: 'end_date',
    type: 'string',
    description: 'End date in ISO-8601 format (e.g. 2026-04-30T23:59:59Z)',
    required: true,
)]
final class CalDavCalendarTool implements ToolInterface
{
    use HasOperations;
    public function __construct(
        private readonly ToolConfigService $configService,
        private readonly HttpClientInterface $httpClient,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    private function effectiveTimeout(array $settings): int
    {
        if (isset($settings['core.caldav.http_timeout']) && (int) $settings['core.caldav.http_timeout'] > 0) {
            return (int) $settings['core.caldav.http_timeout'];
        }
        $envTimeout = (int) ($_ENV['SPORA_TOOL_HTTP_TIMEOUT'] ?? getenv('SPORA_TOOL_HTTP_TIMEOUT') ?: 0);
        return $envTimeout > 0 ? $envTimeout : 30;
    }

    public function execute(array $arguments, int $agentId, ?int $userId = null): ToolResult
    {
        return $this->listEvents($arguments, $agentId, $userId);
    }

    public function describeAction(array $arguments): string
    {
        $start = trim((string) ($arguments['start_date'] ?? ''));
        $end   = trim((string) ($arguments['end_date'] ?? ''));
        return "Fetch CalDAV calendar events from {$start} to {$end}";
    }

    public function listEvents(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $startDateStr = $arguments['start_date'] ?? '';
        $endDateStr   = $arguments['end_date'] ?? '';

        if (empty($startDateStr) || empty($endDateStr)) {
            return new ToolResult(false, 'Missing start_date or end_date parameters.');
        }

        try {
            // Validate dates
            $start = new DateTimeImmutable($startDateStr);
            $end   = new DateTimeImmutable($endDateStr);

            // CalDAV format needs completely basic string YYYYMMDDTHHMMSSZ
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
            $this->logger?->debug('CalDavCalendarTool: HTTP request', [
                'method' => 'REPORT',
                'url' => $url,
                'headers' => [
                    'Depth' => '1',
                    'Content-Type' => 'application/xml; charset=utf-8',
                    'Authorization' => '***',
                ],
                'timeout' => $this->effectiveTimeout($settings),
            ]);

            $response = $this->httpClient->request('REPORT', $url, [
                'headers' => [
                    'Depth'        => '1',
                    'Content-Type' => 'application/xml; charset=utf-8',
                ],
                'auth_basic' => [$username, $password],
                'body'       => $xmlRequest,
                'timeout'    => $this->effectiveTimeout($settings),
            ]);

            $this->logger?->debug('CalDavCalendarTool: HTTP response', [
                'status_code' => $response->getStatusCode(),
                'url' => $url,
            ]);

            if ($response->getStatusCode() >= 400) {
                $errorMsg = $response->getContent(false);
                $this->logger?->error('CalDAV Error', ['status' => $response->getStatusCode(), 'response' => $errorMsg]);
                return new ToolResult(false, "CalDAV server returned HTTP {$response->getStatusCode()}");
            }

            $xmlBody = $response->getContent();
            return $this->parseCalDavResponse($xmlBody);

        } catch (Throwable $e) {
            $this->logger?->error('CalDAV Exception', ['exception' => $e]);
            return new ToolResult(false, 'Failed to fetch CalDAV calendar: ' . $e->getMessage());
        }
    }

    private function parseCalDavResponse(string $xmlBody): ToolResult
    {
        // RFC 5545 §3.1: long property lines may be folded with CRLF + whitespace.
        // Handle both strict CRLF and bare LF (common in XML-embedded ICS data).
        // Unfold before running regex so SUMMARY, DTSTART etc. are on a single line.
        $unfolded = preg_replace("/\r?\n[ \t]/", '', $xmlBody) ?? $xmlBody;

        preg_match_all('/BEGIN:VEVENT.*?END:VEVENT/is', $unfolded, $matches);

        $events = $matches[0];
        if (empty($events)) {
            return new ToolResult(true, 'No events found in the specified time range.');
        }

        $output = "Found " . count($events) . " events:\n\n";

        foreach ($events as $icsBlock) {
            $summary = 'Unknown Event';
            $dtstart = 'Unknown Start';
            $dtend   = 'Unknown End';

            if (preg_match('/SUMMARY:(.*)$/im', $icsBlock, $m)) {
                $summary = trim($m[1]);
            }
            if (preg_match('/DTSTART(?:[^:]*):(.*)$/im', $icsBlock, $m)) {
                $dtstart = trim($m[1]);
            }
            if (preg_match('/DTEND(?:[^:]*):(.*)$/im', $icsBlock, $m)) {
                $dtend = trim($m[1]);
            }

            $output .= "- Event: {$summary}\n  Start: {$dtstart}\n  End:   {$dtend}\n\n";
        }

        return new ToolResult(true, $output);
    }

    public function getParametersSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'start_date' => [
                    'type'        => 'string',
                    'description' => 'Start date in ISO-8601 format.',
                ],
                'end_date' => [
                    'type'        => 'string',
                    'description' => 'End date in ISO-8601 format.',
                ],
            ],
            'required' => ['start_date', 'end_date'],
        ];
    }
}
