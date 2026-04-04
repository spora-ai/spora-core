<?php

declare(strict_types=1);

use Spora\Services\ToolConfigService;
use Spora\Tools\CalDavCalendarTool;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

it('returns error if missing date parameters', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $client = Mockery::mock(HttpClientInterface::class);
    $tool = new CalDavCalendarTool($config, $client);

    $result = $tool->execute(['start_date' => '2026-04-01'], 1); // missing end_date
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Missing start_date or end_date');
});

it('returns error on invalid date format', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $client = Mockery::mock(HttpClientInterface::class);
    $tool = new CalDavCalendarTool($config, $client);

    $result = $tool->execute(['start_date' => 'invalid', 'end_date' => '2026-04-30T00:00:00Z'], 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Invalid date format');
});

it('returns error if caldav is not configured', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(CalDavCalendarTool::class, 1)->andReturn([]);
    $client = Mockery::mock(HttpClientInterface::class);

    $tool = new CalDavCalendarTool($config, $client);
    $result = $tool->execute(['start_date' => '2026-04-01T00:00:00Z', 'end_date' => '2026-04-30T00:00:00Z'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('CalDAV configuration is incomplete');
});

it('correctly unfolds RFC 5545 long lines before parsing', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(CalDavCalendarTool::class, 1)->andReturn([
        'core.caldav.url'      => 'https://cal.example.com/',
        'core.caldav.username' => 'u',
        'core.caldav.password' => 'p',
    ]);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->allows('getStatusCode')->andReturn(200);

    // SUMMARY is folded per RFC 5545 §3.1: trailing space is content, leading space on
    // the continuation line is the fold indicator (and is removed by unfolding).
    // "Folded " ends line 1 (the space is content), " Correctly" starts line 2 (space = fold indicator).
    // After unfolding: "Very Long Event Title Folded Correctly By CalDAV"
    $icsBlock  = "BEGIN:VEVENT\r\n";
    $icsBlock .= "SUMMARY:Very Long Event Title Folded \r\n Correctly By CalDAV\r\n";
    $icsBlock .= "DTSTART:20260415T140000Z\r\n";
    $icsBlock .= "DTEND:20260415T150000Z\r\n";
    $icsBlock .= "END:VEVENT";

    $xmlResponse = '<?xml version="1.0" encoding="utf-8"?>' .
        '<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">' .
        '<d:response><d:propstat><d:prop><c:calendar-data>BEGIN:VCALENDAR\r\n' .
        $icsBlock .
        '\r\nEND:VCALENDAR</c:calendar-data></d:prop></d:propstat></d:response>' .
        '</d:multistatus>';

    $response->allows('getContent')->andReturn($xmlResponse);
    $client->expects('request')->andReturn($response);

    $tool = new CalDavCalendarTool($config, $client);
    $result = $tool->execute(['start_date' => '2026-04-01T00:00:00Z', 'end_date' => '2026-04-30T00:00:00Z'], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Very Long Event Title Folded Correctly By CalDAV');
});

it('makes correct http REPORT request and parses ics events', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(CalDavCalendarTool::class, 1)->andReturn([
        'core.caldav.url' => 'https://cal.example.com/',
        'core.caldav.username' => 'test_user',
        'core.caldav.password' => 'secret123',
    ]);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);

    $response->allows('getStatusCode')->andReturn(200);

    // Simulate a CalDAV XML response containing raw ICS data
    $xmlResponse = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
    <d:response>
        <d:href>/events/1.ics</d:href>
        <d:propstat>
            <d:prop>
                <c:calendar-data>BEGIN:VCALENDAR
BEGIN:VEVENT
SUMMARY:Team Meeting
DTSTART:20260410T100000Z
DTEND:20260410T110000Z
END:VEVENT
END:VCALENDAR</c:calendar-data>
            </d:prop>
            <d:status>HTTP/1.1 200 OK</d:status>
        </d:propstat>
    </d:response>
</d:multistatus>
XML;

    $response->allows('getContent')->andReturn($xmlResponse);

    $client->expects('request')->with('REPORT', 'https://cal.example.com', Mockery::on(function ($options) {
        $body = $options['body'] ?? '';
        return $options['auth_basic'] === ['test_user', 'secret123'] && str_contains($body, 'calendar-query');
    }))->andReturn($response);

    $tool = new CalDavCalendarTool($config, $client);
    $result = $tool->execute(['start_date' => '2026-04-01T00:00:00Z', 'end_date' => '2026-04-30T00:00:00Z'], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Team Meeting')
        ->and($result->content)->toContain('20260410T100000Z');
});
