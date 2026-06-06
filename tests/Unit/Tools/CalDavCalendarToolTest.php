<?php

declare(strict_types=1);

use Spora\Services\ToolConfigService;
use Spora\Tools\CalDavCalendarTool;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

const CAL_BASE_URL = 'https://cal.example.com/';
const CAL_EVENT_URI = 'https://cal.example.com/events/1.ics';
const CAL_CALDAV_URL = 'https://caldav.example.com/begenda/dav/user@example.com/calendar';
const CAL_ETAG_VALUE = '"abc123"';
const CAL_END_DATE_APR = '2026-04-30T00:00:00Z';
const CAL_START_DATE_APR = '2026-04-01T00:00:00Z';
const CAL_MSG_INCOMPLETE = 'CalDAV configuration is incomplete';
const CAL_MSG_DELETED = 'deleted successfully';
const CAL_ETAG_ABC = '"abc"';
const CAL_END_DATE_JUN = '2026-06-01T11:00:00Z';
const CAL_SUMMARY_TEST = 'Test Event';
const CAL_START_DATE_JUN = '2026-06-01T10:00:00Z';
const CAL_NEW_ETAG = '"new-etag"';
const CAL_MSG_CREATED = 'created successfully';
const CAL_NEW_TITLE = 'New Title';
const CAL_INTERNAL_ERROR = 'Internal Server Error';
const CAL_MSG_HTTP_500 = 'CalDAV server returned HTTP 500';

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

    $result = $tool->execute(['start_date' => 'invalid', 'end_date' => CAL_END_DATE_APR], 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Invalid date format');
});

it('returns error if caldav is not configured', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(CalDavCalendarTool::class, 1, null)->andReturn([]);
    $client = Mockery::mock(HttpClientInterface::class);

    $tool = new CalDavCalendarTool($config, $client);
    $result = $tool->execute(['start_date' => CAL_START_DATE_APR, 'end_date' => CAL_END_DATE_APR], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain(CAL_MSG_INCOMPLETE);
});

it('correctly unfolds RFC 5545 long lines before parsing', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(CalDavCalendarTool::class, 1, null)->andReturn([
        'core.caldav.url'      => CAL_BASE_URL,
        'core.caldav.username' => 'u',
        'core.caldav.password' => 'p',
    ]);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->allows('getStatusCode')->andReturn(200);
    $response->allows('getHeaders')->andReturn([]);

    // SUMMARY is folded per RFC 5545 §3.1: trailing space is content, leading space on
    // the continuation line is the fold indicator (and is removed by unfolding).
    // "Folded " ends line 1 (the space is content), " Correctly" starts line 2 (space = fold indicator).
    // After unfolding: "Very Long Event Title Folded Correctly By CalDAV"
    $icsBlock  = "BEGIN:VEVENT\r\n";
    $icsBlock .= "SUMMARY:Very Long Event Title Folded \r\n Correctly By CalDAV\r\n";
    $icsBlock .= "DTSTART:20260415T140000Z\r\n";
    $icsBlock .= "DTEND:20260415T150000Z\r\n";
    $icsBlock .= "END:VEVENT";

    $xmlResponse = "<?xml version=\"1.0\" encoding=\"utf-8\"?>" .
        "<d:multistatus xmlns:d=\"DAV:\" xmlns:c=\"urn:ietf:params:xml:ns:caldav\">" .
        "<d:response><d:propstat><d:prop><c:calendar-data>BEGIN:VCALENDAR\r\n" .
        $icsBlock .
        "\r\nEND:VCALENDAR</c:calendar-data></d:prop></d:propstat></d:response>" .
        "</d:multistatus>";

    $response->allows('getContent')->andReturn($xmlResponse);
    $client->expects('request')->andReturn($response);

    $tool = new CalDavCalendarTool($config, $client);
    $result = $tool->execute(['start_date' => CAL_START_DATE_APR, 'end_date' => CAL_END_DATE_APR], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Very Long Event Title Folded Correctly By CalDAV');
});

it('makes correct http REPORT request and parses ics events', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(CalDavCalendarTool::class, 1, null)->andReturn([
        'core.caldav.url' => CAL_BASE_URL,
        'core.caldav.username' => 'test_user',
        'core.caldav.password' => 'secret123',
    ]);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);

    $response->allows('getStatusCode')->andReturn(200);
    $response->allows('getHeaders')->andReturn([]);

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
    $result = $tool->execute(['action' => 'list_events', 'start_date' => CAL_START_DATE_APR, 'end_date' => CAL_END_DATE_APR], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Team Meeting')
        ->and($result->content)->toContain('20260410T100000Z')
        ->and($result->content)->toContain('/events/1.ics')
        ->and($result->data['events'][0]['event_uri'])->toBe('/events/1.ics')
        ->and($result->data['events'][0]['summary'])->toBe('Team Meeting');
});

it('get_event returns error if event_uri is missing', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $client = Mockery::mock(HttpClientInterface::class);
    $tool = new CalDavCalendarTool($config, $client);

    $result = $tool->execute(['action' => 'get_event'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('event_uri');
});

it('get_event returns error if caldav is not configured', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(CalDavCalendarTool::class, 1, null)->andReturn([]);
    $client = Mockery::mock(HttpClientInterface::class);

    $tool = new CalDavCalendarTool($config, $client);
    $result = $tool->execute(['action' => 'get_event', 'event_uri' => CAL_EVENT_URI], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain(CAL_MSG_INCOMPLETE);
});

it('get_event returns error on 404', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(CalDavCalendarTool::class, 1, null)->andReturn([
        'core.caldav.url' => CAL_BASE_URL,
        'core.caldav.username' => 'test_user',
        'core.caldav.password' => 'secret123',
    ]);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->allows('getStatusCode')->andReturn(404);
    $response->allows('getHeaders')->andReturn([]);

    $client->expects('request')->with('GET', CAL_EVENT_URI, Mockery::any())->andReturn($response);

    $tool = new CalDavCalendarTool($config, $client);
    $result = $tool->execute(['action' => 'get_event', 'event_uri' => CAL_EVENT_URI], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('not found');
});

it('delete_event resolves relative event_uri against base URL', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(CalDavCalendarTool::class, 1, null)->andReturn([
        'core.caldav.url' => CAL_CALDAV_URL,
        'core.caldav.username' => 'test_user',
        'core.caldav.password' => 'secret123',
    ]);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->allows('getStatusCode')->andReturn(204);
    $response->allows('getHeaders')->andReturn([]);

    $client->expects('request')->with('DELETE', 'https://caldav.example.com/begenda/dav/user@example.com/calendar/abc.ics', Mockery::any())->andReturn($response);

    $tool = new CalDavCalendarTool($config, $client);
    $result = $tool->execute([
        'action' => 'delete_event',
        'event_uri' => '/begenda/dav/user@example.com/calendar/abc.ics',
    ], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain(CAL_MSG_DELETED);
});

it('delete_event handles event_uri without leading slash', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(CalDavCalendarTool::class, 1, null)->andReturn([
        'core.caldav.url' => CAL_CALDAV_URL,
        'core.caldav.username' => 'test_user',
        'core.caldav.password' => 'secret123',
    ]);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->allows('getStatusCode')->andReturn(204);
    $response->allows('getHeaders')->andReturn([]);

    // Bug: when href doesn't start with "/", concatenation produced broken URL like
    // "https://caldav.web.detest-event-123". This test guards against regression.
    $client->expects('request')->with('DELETE', 'https://caldav.example.com/test-event-123', Mockery::any())->andReturn($response);

    $tool = new CalDavCalendarTool($config, $client);
    $result = $tool->execute([
        'action' => 'delete_event',
        'event_uri' => 'test-event-123',
    ], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain(CAL_MSG_DELETED);
});

it('get_event resolves relative event_uri against base URL', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(CalDavCalendarTool::class, 1, null)->andReturn([
        'core.caldav.url' => CAL_CALDAV_URL,
        'core.caldav.username' => 'test_user',
        'core.caldav.password' => 'secret123',
    ]);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->allows('getStatusCode')->andReturn(200);
    $response->allows('getHeaders')->andReturn(['etag' => [CAL_ETAG_ABC]]);
    $response->allows('getContent')->andReturn("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:event-1\r\nSUMMARY:Test\r\nDTSTART:20260601T100000Z\r\nDTEND:20260601T110000Z\r\nEND:VEVENT\r\nEND:VCALENDAR");

    $client->expects('request')->with('GET', 'https://caldav.example.com/begenda/dav/user@example.com/calendar/abc.ics', Mockery::any())->andReturn($response);

    $tool = new CalDavCalendarTool($config, $client);
    $result = $tool->execute([
        'action' => 'get_event',
        'event_uri' => '/begenda/dav/user@example.com/calendar/abc.ics',
    ], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Test');
});

it('get_event parses ics content correctly', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(CalDavCalendarTool::class, 1, null)->andReturn([
        'core.caldav.url' => CAL_BASE_URL,
        'core.caldav.username' => 'test_user',
        'core.caldav.password' => 'secret123',
    ]);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->allows('getStatusCode')->andReturn(200);
    $response->allows('getHeaders')->with(false)->andReturn(['etag' => [CAL_ETAG_VALUE]]);
    $response->allows('getContent')->andReturn("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:event-1\r\nSUMMARY:Test Meeting\r\nDTSTART:20260410T100000Z\r\nDTEND:20260410T110000Z\r\nDESCRIPTION:Test description\r\nLOCATION:Test location\r\nEND:VEVENT\r\nEND:VCALENDAR");

    $client->expects('request')->with('GET', CAL_EVENT_URI, Mockery::any())->andReturn($response);

    $tool = new CalDavCalendarTool($config, $client);
    $result = $tool->execute(['action' => 'get_event', 'event_uri' => CAL_EVENT_URI], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Test Meeting')
        ->and($result->content)->toContain('Test description')
        ->and($result->content)->toContain('Test location')
        ->and($result->content)->toContain(CAL_ETAG_VALUE);
});

it('create_event returns error if required params are missing', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $client = Mockery::mock(HttpClientInterface::class);
    $tool = new CalDavCalendarTool($config, $client);

    $result = $tool->execute(['action' => 'create_event'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Missing required parameters');
});

it('create_event returns error if end_date is not after start_date', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $client = Mockery::mock(HttpClientInterface::class);
    $tool = new CalDavCalendarTool($config, $client);

    $result = $tool->execute([
        'action' => 'create_event',
        'summary' => 'Test',
        'start_date' => '2026-06-01T12:00:00Z',
        'end_date' => CAL_END_DATE_JUN, // before start
    ], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('end_date must be after start_date');
});

it('create_event returns error if caldav is not configured', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(CalDavCalendarTool::class, 1, null)->andReturn([]);
    $client = Mockery::mock(HttpClientInterface::class);

    $tool = new CalDavCalendarTool($config, $client);
    $result = $tool->execute([
        'action' => 'create_event',
        'summary' => CAL_SUMMARY_TEST,
        'start_date' => CAL_START_DATE_JUN,
        'end_date' => CAL_END_DATE_JUN,
    ], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain(CAL_MSG_INCOMPLETE);
});

it('create_event makes correct HTTP PUT request and returns success', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(CalDavCalendarTool::class, 1, null)->andReturn([
        'core.caldav.url' => CAL_BASE_URL,
        'core.caldav.username' => 'test_user',
        'core.caldav.password' => 'secret123',
    ]);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->allows('getStatusCode')->andReturn(201);
    $response->allows('getHeaders')->with(false)->andReturn(['etag' => [CAL_NEW_ETAG]]);

    $client->expects('request')->with('PUT', Mockery::any(), Mockery::on(function ($options) {
        return $options['headers']['Content-Type'] === 'text/calendar; charset=utf-8'
            && $options['auth_basic'] === ['test_user', 'secret123']
            && str_contains($options['body'], 'BEGIN:VCALENDAR')
            && str_contains($options['body'], 'SUMMARY:Test Event');
    }))->andReturn($response);

    $tool = new CalDavCalendarTool($config, $client);
    $result = $tool->execute([
        'action' => 'create_event',
        'summary' => CAL_SUMMARY_TEST,
        'start_date' => CAL_START_DATE_JUN,
        'end_date' => CAL_END_DATE_JUN,
        'description' => 'Test description',
        'location' => 'Test location',
    ], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain(CAL_MSG_CREATED);
});

it('create_event supports all_day with date-only format', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(CalDavCalendarTool::class, 1, null)->andReturn([
        'core.caldav.url' => CAL_BASE_URL,
        'core.caldav.username' => 'test_user',
        'core.caldav.password' => 'secret123',
    ]);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->allows('getStatusCode')->andReturn(201);
    $response->allows('getHeaders')->with(false)->andReturn(['etag' => [CAL_NEW_ETAG]]);

    $client->expects('request')->with('PUT', Mockery::any(), Mockery::on(function ($options) {
        return str_contains($options['body'], 'DTSTART:20260601')
            && str_contains($options['body'], 'DTEND:20260602')
            && !str_contains($options['body'], 'DTSTART:20260601T');
    }))->andReturn($response);

    $tool = new CalDavCalendarTool($config, $client);
    $result = $tool->execute([
        'action' => 'create_event',
        'summary' => 'All Day Event',
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-02',
        'all_day' => true,
    ], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain(CAL_MSG_CREATED);
});

it('create_event rejects invalid date format for all_day events', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $client = Mockery::mock(HttpClientInterface::class);
    $tool = new CalDavCalendarTool($config, $client);

    $result = $tool->execute([
        'action' => 'create_event',
        'summary' => 'All Day Event',
        'start_date' => CAL_START_DATE_JUN,  // not date-only
        'end_date' => '2026-06-02',
        'all_day' => true,
    ], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Invalid date format');
});

it('create_event supports timezone parameter', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(CalDavCalendarTool::class, 1, null)->andReturn([
        'core.caldav.url' => CAL_BASE_URL,
        'core.caldav.username' => 'test_user',
        'core.caldav.password' => 'secret123',
    ]);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->allows('getStatusCode')->andReturn(201);
    $response->allows('getHeaders')->with(false)->andReturn(['etag' => [CAL_NEW_ETAG]]);

    $client->expects('request')->with('PUT', Mockery::any(), Mockery::on(function ($options) {
        return str_contains($options['body'], 'TZID=Europe/Berlin')
            && str_contains($options['body'], 'DTSTART;TZID=Europe/Berlin:20260601T100000')
            && str_contains($options['body'], 'DTEND;TZID=Europe/Berlin:20260601T110000');
    }))->andReturn($response);

    $tool = new CalDavCalendarTool($config, $client);
    $result = $tool->execute([
        'action' => 'create_event',
        'summary' => 'Berlin Meeting',
        'start_date' => '2026-06-01T10:00:00',
        'end_date' => '2026-06-01T11:00:00',
        'timezone' => 'Europe/Berlin',
    ], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain(CAL_MSG_CREATED);
});

it('create_event rejects invalid timezone', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $client = Mockery::mock(HttpClientInterface::class);
    $tool = new CalDavCalendarTool($config, $client);

    $result = $tool->execute([
        'action' => 'create_event',
        'summary' => 'Bad TZ Event',
        'start_date' => '2026-06-01T10:00:00',
        'end_date' => '2026-06-01T11:00:00',
        'timezone' => 'Invalid/Timezone',
    ], 1);

    expect($result->success)->toBeFalse();
});

it('create_event handles 415 unsupported media type', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(CalDavCalendarTool::class, 1, null)->andReturn([
        'core.caldav.url' => CAL_BASE_URL,
        'core.caldav.username' => 'test_user',
        'core.caldav.password' => 'secret123',
    ]);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->allows('getStatusCode')->andReturn(415);
    $response->allows('getHeaders')->andReturn([]);

    $client->expects('request')->andReturn($response);

    $tool = new CalDavCalendarTool($config, $client);
    $result = $tool->execute([
        'action' => 'create_event',
        'summary' => CAL_SUMMARY_TEST,
        'start_date' => CAL_START_DATE_JUN,
        'end_date' => CAL_END_DATE_JUN,
    ], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('unsupported media type');
});

it('edit_event returns error if event_uri is missing', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $client = Mockery::mock(HttpClientInterface::class);
    $tool = new CalDavCalendarTool($config, $client);

    $result = $tool->execute(['action' => 'edit_event', 'etag' => CAL_ETAG_ABC], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('event_uri');
});

it('edit_event returns error if etag is missing', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $client = Mockery::mock(HttpClientInterface::class);
    $tool = new CalDavCalendarTool($config, $client);

    $result = $tool->execute(['action' => 'edit_event', 'event_uri' => CAL_EVENT_URI], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('etag');
});

it('edit_event fetches existing, updates and puts back', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(CalDavCalendarTool::class, 1, null)->andReturn([
        'core.caldav.url' => CAL_BASE_URL,
        'core.caldav.username' => 'test_user',
        'core.caldav.password' => 'secret123',
    ]);

    $client = Mockery::mock(HttpClientInterface::class);

    // First GET to fetch existing
    $getResponse = Mockery::mock(ResponseInterface::class);
    $getResponse->allows('getStatusCode')->andReturn(200);
    $getResponse->allows('getHeaders')->andReturn([]);
    $getResponse->allows('getContent')->andReturn("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:event-1\r\nSUMMARY:Old Title\r\nDTSTART:20260410T100000Z\r\nDTEND:20260410T110000Z\r\nEND:VEVENT\r\nEND:VCALENDAR");

    // Then PUT with updated content
    $putResponse = Mockery::mock(ResponseInterface::class);
    $putResponse->allows('getStatusCode')->andReturn(200);
    $putResponse->allows('getHeaders')->with(false)->andReturn(['etag' => [CAL_NEW_ETAG]]);

    $client->expects('request')->with('GET', CAL_EVENT_URI, Mockery::any())->andReturn($getResponse);
    $client->expects('request')->with('PUT', CAL_EVENT_URI, Mockery::on(function ($options) {
        return $options['headers']['If-Match'] === CAL_ETAG_VALUE
            && str_contains($options['body'], CAL_NEW_TITLE);
    }))->andReturn($putResponse);

    $tool = new CalDavCalendarTool($config, $client);
    $result = $tool->execute([
        'action' => 'edit_event',
        'event_uri' => CAL_EVENT_URI,
        'etag' => CAL_ETAG_VALUE,
        'summary' => CAL_NEW_TITLE,
    ], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('updated successfully');
});

it('edit_event handles 412 Precondition Failed', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(CalDavCalendarTool::class, 1, null)->andReturn([
        'core.caldav.url' => CAL_BASE_URL,
        'core.caldav.username' => 'test_user',
        'core.caldav.password' => 'secret123',
    ]);

    $client = Mockery::mock(HttpClientInterface::class);

    $getResponse = Mockery::mock(ResponseInterface::class);
    $getResponse->allows('getStatusCode')->andReturn(200);
    $getResponse->allows('getHeaders')->andReturn([]);
    $getResponse->allows('getContent')->andReturn("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:event-1\r\nSUMMARY:Title\r\nDTSTART:20260410T100000Z\r\nDTEND:20260410T110000Z\r\nEND:VEVENT\r\nEND:VCALENDAR");

    $putResponse = Mockery::mock(ResponseInterface::class);
    $putResponse->allows('getStatusCode')->andReturn(412);
    $putResponse->allows('getHeaders')->andReturn([]);

    $client->expects('request')->with('GET', Mockery::any(), Mockery::any())->andReturn($getResponse);
    $client->expects('request')->with('PUT', Mockery::any(), Mockery::any())->andReturn($putResponse);

    $tool = new CalDavCalendarTool($config, $client);
    $result = $tool->execute([
        'action' => 'edit_event',
        'event_uri' => CAL_EVENT_URI,
        'etag' => '"stale-etag"',
        'summary' => CAL_NEW_TITLE,
    ], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Precondition Failed');
});

it('edit_event returns failure when existing event dates cannot be parsed', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(CalDavCalendarTool::class, 1, null)->andReturn([
        'core.caldav.url' => CAL_BASE_URL,
        'core.caldav.username' => 'test_user',
        'core.caldav.password' => 'secret123',
    ]);

    $client = Mockery::mock(HttpClientInterface::class);

    $getResponse = Mockery::mock(ResponseInterface::class);
    $getResponse->allows('getStatusCode')->andReturn(200);
    $getResponse->allows('getHeaders')->andReturn([]);
    $getResponse->allows('getContent')->andReturn("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:event-1\r\nSUMMARY:Broken Event\r\nEND:VEVENT\r\nEND:VCALENDAR");

    $client->expects('request')->with('GET', CAL_EVENT_URI, Mockery::any())->andReturn($getResponse);

    $tool = new CalDavCalendarTool($config, $client);
    $result = $tool->execute([
        'action' => 'edit_event',
        'event_uri' => CAL_EVENT_URI,
        'etag' => CAL_ETAG_VALUE,
        'summary' => 'Still Broken',
    ], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Failed to parse existing event dates');
});

it('delete_event returns error if event_uri is missing', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $client = Mockery::mock(HttpClientInterface::class);
    $tool = new CalDavCalendarTool($config, $client);

    $result = $tool->execute(['action' => 'delete_event'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('event_uri');
});

it('delete_event returns error if caldav is not configured', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(CalDavCalendarTool::class, 1, null)->andReturn([]);
    $client = Mockery::mock(HttpClientInterface::class);

    $tool = new CalDavCalendarTool($config, $client);
    $result = $tool->execute(['action' => 'delete_event', 'event_uri' => CAL_EVENT_URI], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain(CAL_MSG_INCOMPLETE);
});

it('delete_event makes correct HTTP DELETE request', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(CalDavCalendarTool::class, 1, null)->andReturn([
        'core.caldav.url' => CAL_BASE_URL,
        'core.caldav.username' => 'test_user',
        'core.caldav.password' => 'secret123',
    ]);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->allows('getStatusCode')->andReturn(204);
    $response->allows('getHeaders')->andReturn([]);

    $client->expects('request')->with('DELETE', CAL_EVENT_URI, Mockery::on(function ($options) {
        return $options['auth_basic'] === ['test_user', 'secret123']
            && ($options['headers']['If-Match'] ?? null) === CAL_ETAG_VALUE;
    }))->andReturn($response);

    $tool = new CalDavCalendarTool($config, $client);
    $result = $tool->execute([
        'action' => 'delete_event',
        'event_uri' => CAL_EVENT_URI,
        'etag' => CAL_ETAG_VALUE,
    ], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain(CAL_MSG_DELETED);
});

it('delete_event handles 404 Not Found', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(CalDavCalendarTool::class, 1, null)->andReturn([
        'core.caldav.url' => CAL_BASE_URL,
        'core.caldav.username' => 'test_user',
        'core.caldav.password' => 'secret123',
    ]);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->allows('getStatusCode')->andReturn(404);
    $response->allows('getHeaders')->andReturn([]);

    $client->expects('request')->andReturn($response);

    $tool = new CalDavCalendarTool($config, $client);
    $result = $tool->execute([
        'action' => 'delete_event',
        'event_uri' => CAL_EVENT_URI,
    ], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('not found');
});

it('describeAction returns correct description for list_events', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $client = Mockery::mock(HttpClientInterface::class);
    $tool = new CalDavCalendarTool($config, $client);

    $result = $tool->describeAction(['action' => 'list_events']);

    expect($result)->toBe('Fetch CalDAV calendar events');
});

it('describeAction returns correct description for get_event', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $client = Mockery::mock(HttpClientInterface::class);
    $tool = new CalDavCalendarTool($config, $client);

    $result = $tool->describeAction(['action' => 'get_event']);

    expect($result)->toBe('Get a specific CalDAV calendar event');
});

it('describeAction returns correct description for create_event', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $client = Mockery::mock(HttpClientInterface::class);
    $tool = new CalDavCalendarTool($config, $client);

    $result = $tool->describeAction(['action' => 'create_event']);

    expect($result)->toBe('Create a new CalDAV calendar event');
});

it('describeAction returns correct description for edit_event', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $client = Mockery::mock(HttpClientInterface::class);
    $tool = new CalDavCalendarTool($config, $client);

    $result = $tool->describeAction(['action' => 'edit_event']);

    expect($result)->toBe('Edit an existing CalDAV calendar event');
});

it('describeAction returns correct description for delete_event', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $client = Mockery::mock(HttpClientInterface::class);
    $tool = new CalDavCalendarTool($config, $client);

    $result = $tool->describeAction(['action' => 'delete_event']);

    expect($result)->toBe('Delete a CalDAV calendar event');
});

it('describeAction returns correct description for unknown operation', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $client = Mockery::mock(HttpClientInterface::class);
    $tool = new CalDavCalendarTool($config, $client);

    $result = $tool->describeAction(['action' => 'unknown']);

    expect($result)->toBe('Unknown CalDAV operation');
});

it('listEvents returns error on HTTP 500', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(CalDavCalendarTool::class, 1, null)->andReturn([
        'core.caldav.url' => CAL_BASE_URL,
        'core.caldav.username' => 'test_user',
        'core.caldav.password' => 'secret123',
    ]);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->allows('getStatusCode')->andReturn(500);
    $response->allows('getHeaders')->andReturn([]);
    $response->allows('getContent')->andReturn(CAL_INTERNAL_ERROR);

    $client->expects('request')->andReturn($response);

    $tool = new CalDavCalendarTool($config, $client);
    $result = $tool->execute([
        'action' => 'list_events',
        'start_date' => CAL_START_DATE_APR,
        'end_date' => CAL_END_DATE_APR,
    ], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain(CAL_MSG_HTTP_500);
});

it('getEvent returns error on HTTP 500', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(CalDavCalendarTool::class, 1, null)->andReturn([
        'core.caldav.url' => CAL_BASE_URL,
        'core.caldav.username' => 'test_user',
        'core.caldav.password' => 'secret123',
    ]);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->allows('getStatusCode')->andReturn(500);
    $response->allows('getHeaders')->andReturn([]);
    $response->allows('getContent')->andReturn(CAL_INTERNAL_ERROR);

    $client->expects('request')->andReturn($response);

    $tool = new CalDavCalendarTool($config, $client);
    $result = $tool->execute([
        'action' => 'get_event',
        'event_uri' => CAL_EVENT_URI,
    ], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain(CAL_MSG_HTTP_500);
});

it('createEvent returns error on HTTP 500', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(CalDavCalendarTool::class, 1, null)->andReturn([
        'core.caldav.url' => CAL_BASE_URL,
        'core.caldav.username' => 'test_user',
        'core.caldav.password' => 'secret123',
    ]);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->allows('getStatusCode')->andReturn(500);
    $response->allows('getHeaders')->andReturn([]);
    $response->allows('getContent')->andReturn(CAL_INTERNAL_ERROR);

    $client->expects('request')->andReturn($response);

    $tool = new CalDavCalendarTool($config, $client);
    $result = $tool->execute([
        'action' => 'create_event',
        'summary' => CAL_SUMMARY_TEST,
        'start_date' => CAL_START_DATE_JUN,
        'end_date' => CAL_END_DATE_JUN,
    ], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain(CAL_MSG_HTTP_500);
});

it('editEvent returns error on HTTP 500 during fetch', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(CalDavCalendarTool::class, 1, null)->andReturn([
        'core.caldav.url' => CAL_BASE_URL,
        'core.caldav.username' => 'test_user',
        'core.caldav.password' => 'secret123',
    ]);

    $client = Mockery::mock(HttpClientInterface::class);

    $getResponse = Mockery::mock(ResponseInterface::class);
    $getResponse->allows('getStatusCode')->andReturn(500);
    $getResponse->allows('getHeaders')->andReturn([]);

    $client->expects('request')->andReturn($getResponse);

    $tool = new CalDavCalendarTool($config, $client);
    $result = $tool->execute([
        'action' => 'edit_event',
        'event_uri' => CAL_EVENT_URI,
        'etag' => CAL_ETAG_VALUE,
        'summary' => CAL_NEW_TITLE,
    ], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Failed to fetch existing event');
});

it('deleteEvent returns error on HTTP 500', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(CalDavCalendarTool::class, 1, null)->andReturn([
        'core.caldav.url' => CAL_BASE_URL,
        'core.caldav.username' => 'test_user',
        'core.caldav.password' => 'secret123',
    ]);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->allows('getStatusCode')->andReturn(500);
    $response->allows('getHeaders')->andReturn([]);
    $response->allows('getContent')->andReturn(CAL_INTERNAL_ERROR);

    $client->expects('request')->andReturn($response);

    $tool = new CalDavCalendarTool($config, $client);
    $result = $tool->execute([
        'action' => 'delete_event',
        'event_uri' => CAL_EVENT_URI,
    ], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain(CAL_MSG_HTTP_500);
});

it("get_event handles VTIMEZONE before VEVENT in components array", function () {
    // Regression test: array_filter preserves keys, so events[0] was null when
    // VTIMEZONE came first. Use array_values() to reindex.
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows("getEffectiveSettings")->with(CalDavCalendarTool::class, 1, null)->andReturn([
        "core.caldav.url" => "https://cal.example.com/",
        "core.caldav.username" => "test_user",
        "core.caldav.password" => "secret123",
    ]);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->allows("getStatusCode")->andReturn(200);
    $response->allows("getHeaders")->with(false)->andReturn(["etag" => ["\"abc\""]]);
    $response->allows("getContent")->andReturn("BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VTIMEZONE
TZID:UTC
END:VTIMEZONE
BEGIN:VEVENT
UID:event-1
SUMMARY:After Timezone
DTSTART:20260410T100000Z
DTEND:20260410T110000Z
END:VEVENT
END:VCALENDAR");

    $client->expects("request")->with("GET", "https://cal.example.com/events/1.ics", Mockery::any())->andReturn($response);

    $tool = new CalDavCalendarTool($config, $client);
    $result = $tool->execute(["action" => "get_event", "event_uri" => "https://cal.example.com/events/1.ics"], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain("After Timezone");
});

it('edit_event normalizes unquoted etag from user', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(CalDavCalendarTool::class, 1, null)->andReturn([
        'core.caldav.url' => CAL_BASE_URL,
        'core.caldav.username' => 'test_user',
        'core.caldav.password' => 'secret123',
    ]);

    $client = Mockery::mock(HttpClientInterface::class);

    $getResponse = Mockery::mock(ResponseInterface::class);
    $getResponse->allows('getStatusCode')->andReturn(200);
    $getResponse->allows('getHeaders')->andReturn([]);
    $getResponse->allows('getContent')->andReturn("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:event-1\r\nSUMMARY:Title\r\nDTSTART:20260410T100000Z\r\nDTEND:20260410T110000Z\r\nEND:VEVENT\r\nEND:VCALENDAR");

    $putResponse = Mockery::mock(ResponseInterface::class);
    $putResponse->allows('getStatusCode')->andReturn(200);
    $putResponse->allows('getHeaders')->with(false)->andReturn(['etag' => [CAL_NEW_ETAG]]);

    // The user passed etag WITHOUT quotes - the tool should add them
    $client->expects('request')->with('GET', CAL_EVENT_URI, Mockery::any())->andReturn($getResponse);
    $client->expects('request')->with('PUT', CAL_EVENT_URI, Mockery::on(function ($options) {
        return $options['headers']['If-Match'] === CAL_ETAG_VALUE;  // wrapped in quotes
    }))->andReturn($putResponse);

    $tool = new CalDavCalendarTool($config, $client);
    $result = $tool->execute([
        'action' => 'edit_event',
        'event_uri' => CAL_EVENT_URI,
        'etag' => 'abc123',  // no quotes!
        'summary' => CAL_NEW_TITLE,
    ], 1);

    expect($result->success)->toBeTrue();
});

it('edit_event returns Precondition Failed when server returns HTTP 412', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(CalDavCalendarTool::class, 1, null)->andReturn([
        'core.caldav.url' => CAL_BASE_URL,
        'core.caldav.username' => 'u',
        'core.caldav.password' => 'p',
    ]);

    $client = Mockery::mock(HttpClientInterface::class);

    // First the GET to fetch the existing event
    $getResponse = Mockery::mock(ResponseInterface::class);
    $getResponse->allows('getStatusCode')->andReturn(200);
    $getResponse->allows('getHeaders')->andReturn([]);
    $getResponse->allows('getContent')->andReturn(
        "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:edit-412\r\nSUMMARY:Old\r\nDTSTART:20260601T100000Z\r\nDTEND:20260601T110000Z\r\nEND:VEVENT\r\nEND:VCALENDAR",
    );

    // Then the PUT to commit the change — server says "Precondition Failed"
    $putResponse = Mockery::mock(ResponseInterface::class);
    $putResponse->allows('getStatusCode')->andReturn(412);
    $putResponse->allows('getHeaders')->andReturn([]);

    $client->expects('request')->with('GET', Mockery::any(), Mockery::any())->andReturn($getResponse);
    $client->expects('request')->with('PUT', Mockery::any(), Mockery::any())->andReturn($putResponse);

    $tool = new CalDavCalendarTool($config, $client);
    $result = $tool->execute([
        'action' => 'edit_event',
        'event_uri' => CAL_EVENT_URI,
        'etag' => CAL_ETAG_VALUE,
        'summary' => 'New title',
    ], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Precondition Failed');
});

it('edit_event returns Event not found when server returns HTTP 404 on PUT', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(CalDavCalendarTool::class, 1, null)->andReturn([
        'core.caldav.url' => CAL_BASE_URL,
        'core.caldav.username' => 'u',
        'core.caldav.password' => 'p',
    ]);

    $client = Mockery::mock(HttpClientInterface::class);

    $getResponse = Mockery::mock(ResponseInterface::class);
    $getResponse->allows('getStatusCode')->andReturn(200);
    $getResponse->allows('getHeaders')->andReturn([]);
    $getResponse->allows('getContent')->andReturn(
        "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:edit-404\r\nSUMMARY:Old\r\nDTSTART:20260601T100000Z\r\nDTEND:20260601T110000Z\r\nEND:VEVENT\r\nEND:VCALENDAR",
    );

    $putResponse = Mockery::mock(ResponseInterface::class);
    $putResponse->allows('getStatusCode')->andReturn(404);
    $putResponse->allows('getHeaders')->andReturn([]);

    $client->expects('request')->with('GET', Mockery::any(), Mockery::any())->andReturn($getResponse);
    $client->expects('request')->with('PUT', Mockery::any(), Mockery::any())->andReturn($putResponse);

    $tool = new CalDavCalendarTool($config, $client);
    $result = $tool->execute([
        'action' => 'edit_event',
        'event_uri' => CAL_EVENT_URI,
        'etag' => CAL_ETAG_VALUE,
        'summary' => 'New title',
    ], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Event not found');
});

it('edit_event catches Throwable during PUT and returns error', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(CalDavCalendarTool::class, 1, null)->andReturn([
        'core.caldav.url' => CAL_BASE_URL,
        'core.caldav.username' => 'u',
        'core.caldav.password' => 'p',
    ]);

    $client = Mockery::mock(HttpClientInterface::class);

    $getResponse = Mockery::mock(ResponseInterface::class);
    $getResponse->allows('getStatusCode')->andReturn(200);
    $getResponse->allows('getHeaders')->andReturn([]);
    $getResponse->allows('getContent')->andReturn(
        "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:edit-throw\r\nSUMMARY:Old\r\nDTSTART:20260601T100000Z\r\nDTEND:20260601T110000Z\r\nEND:VEVENT\r\nEND:VCALENDAR",
    );

    $client->expects('request')->with('GET', Mockery::any(), Mockery::any())->andReturn($getResponse);
    $client->expects('request')->with('PUT', Mockery::any(), Mockery::any())
        ->andThrow(new RuntimeException('connection reset'));

    $tool = new CalDavCalendarTool($config, $client);
    $result = $tool->execute([
        'action' => 'edit_event',
        'event_uri' => CAL_EVENT_URI,
        'etag' => CAL_ETAG_VALUE,
        'summary' => 'New title',
    ], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Failed to update CalDAV event')
        ->and($result->content)->toContain('connection reset');
});

it('edit_event returns missing etag when no etag is supplied', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([
        'core.caldav.url' => CAL_BASE_URL,
        'core.caldav.username' => 'u',
        'core.caldav.password' => 'p',
    ]);
    $client = Mockery::mock(HttpClientInterface::class);

    $tool = new CalDavCalendarTool($config, $client);
    $result = $tool->execute([
        'action' => 'edit_event',
        'event_uri' => CAL_EVENT_URI,
        'summary' => 'no etag',
    ], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Missing required parameter: etag');
});

it('delete_event returns Precondition Failed when server returns HTTP 412', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(CalDavCalendarTool::class, 1, null)->andReturn([
        'core.caldav.url' => CAL_BASE_URL,
        'core.caldav.username' => 'u',
        'core.caldav.password' => 'p',
    ]);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->allows('getStatusCode')->andReturn(412);
    $response->allows('getHeaders')->andReturn([]);

    $client->expects('request')->andReturn($response);

    $tool = new CalDavCalendarTool($config, $client);
    $result = $tool->execute([
        'action' => 'delete_event',
        'event_uri' => CAL_EVENT_URI,
        'etag' => CAL_ETAG_VALUE,
    ], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Precondition Failed');
});

it('delete_event catches Throwable and returns error', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(CalDavCalendarTool::class, 1, null)->andReturn([
        'core.caldav.url' => CAL_BASE_URL,
        'core.caldav.username' => 'u',
        'core.caldav.password' => 'p',
    ]);

    $client = Mockery::mock(HttpClientInterface::class);
    $client->expects('request')->andThrow(new RuntimeException('connection refused'));

    $tool = new CalDavCalendarTool($config, $client);
    $result = $tool->execute([
        'action' => 'delete_event',
        'event_uri' => CAL_EVENT_URI,
    ], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Failed to delete CalDAV event')
        ->and($result->content)->toContain('connection refused');
});

it('create_event rejects an end_date before start_date', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(CalDavCalendarTool::class, 1, null)->andReturn([
        'core.caldav.url' => CAL_BASE_URL,
        'core.caldav.username' => 'u',
        'core.caldav.password' => 'p',
    ]);
    $client = Mockery::mock(HttpClientInterface::class);

    $tool = new CalDavCalendarTool($config, $client);
    $result = $tool->execute([
        'action' => 'create_event',
        'summary' => 'Backwards',
        'start_date' => '2026-06-15T10:00:00Z',
        'end_date'   => '2026-06-15T09:00:00Z', // before start
    ], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('end_date must be after start_date');
});

it('create_event returns 415 when server rejects media type', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(CalDavCalendarTool::class, 1, null)->andReturn([
        'core.caldav.url' => CAL_BASE_URL,
        'core.caldav.username' => 'u',
        'core.caldav.password' => 'p',
    ]);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->allows('getStatusCode')->andReturn(415);
    $response->allows('getHeaders')->andReturn([]);
    $client->expects('request')->andReturn($response);

    $tool = new CalDavCalendarTool($config, $client);
    $result = $tool->execute([
        'action' => 'create_event',
        'summary' => CAL_SUMMARY_TEST,
        'start_date' => CAL_START_DATE_JUN,
        'end_date'   => CAL_END_DATE_JUN,
    ], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('unsupported media type');
});
