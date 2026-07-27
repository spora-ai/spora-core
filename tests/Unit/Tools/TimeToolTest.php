<?php

declare(strict_types=1);

use Spora\Tools\TimeTool;

it('returns the current time formatted', function () {
    $tool = new TimeTool();
    $result = $tool->execute([], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Current Date & Time:')
        ->and($result->content)->toContain('Timezone:')
        ->and($result->content)->toContain('Unix Timestamp:');
});

it('has a non-empty schema covering both operations and their parameters', function () {
    $tool = new TimeTool();
    $schema = $tool->getParametersSchema();

    expect($schema['type'])->toBe('object')
        ->and($schema['properties'])->toHaveKeys(['action', 'epoch', 'timezone', 'format'])
        ->and($schema['required'])->toContain('action', 'epoch');
});

it('exposes a format operation with epoch, timezone, and format parameters', function () {
    $tool = new TimeTool();
    $schema = $tool->getParametersSchema();

    expect($schema['properties'])->toHaveKey('action')
        ->and($schema['properties']['action']['enum'])->toBe(['now', 'format'])
        ->and($schema['properties']['epoch'])->toBe([
            'type'        => 'integer',
            'description' => 'Unix timestamp in seconds.',
            'minimum'     => 0,
        ])
        ->and($schema['properties']['timezone'])->toBe([
            'type'        => 'string',
            'description' => 'IANA timezone name (e.g. "UTC", "America/New_York", "Asia/Tokyo"). Defaults to "UTC".',
            'default'     => 'UTC',
        ])
        ->and($schema['properties']['format']['enum'])->toBe(['iso8601', 'rfc2822', 'human']);
});

it('formats an epoch as iso8601 by default in UTC', function () {
    $tool = new TimeTool();
    $result = $tool->execute([
        'action' => 'format',
        'epoch'  => 1753464720, // 2025-07-25T17:32:00Z
    ], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toBe('2025-07-25T17:32:00+00:00')
        ->and($result->data['timezone'])->toBe('UTC')
        ->and($result->data['format'])->toBe('iso8601');
});

it('formats an epoch in a chosen IANA timezone', function () {
    $tool = new TimeTool();
    $result = $tool->execute([
        'action'    => 'format',
        'epoch'     => 1753464720,
        'timezone'  => 'Asia/Tokyo',
    ], 1);

    // 17:32 UTC → 02:32 the next day in Tokyo (UTC+9).
    expect($result->success)->toBeTrue()
        ->and($result->content)->toBe('2025-07-26T02:32:00+09:00')
        ->and($result->data['timezone'])->toBe('Asia/Tokyo');
});

it('formats an epoch in the human format', function () {
    $tool = new TimeTool();
    $result = $tool->execute([
        'action'   => 'format',
        'epoch'    => 1753464720,
        'timezone' => 'UTC',
        'format'   => 'human',
    ], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toBe('2025-07-25 17:32:00 UTC');
});

it('formats an epoch in rfc2822', function () {
    $tool = new TimeTool();
    $result = $tool->execute([
        'action'   => 'format',
        'epoch'    => 1753464720,
        'timezone' => 'UTC',
        'format'   => 'rfc2822',
    ], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Fri, 25 Jul 2025');
});

it('rejects a negative epoch', function () {
    $tool = new TimeTool();
    $result = $tool->execute([
        'action' => 'format',
        'epoch'  => -1,
    ], 1);

    expect($result->success)->toBeFalse()
        ->and($result->data['error_code'])->toBe('EPOCH_INVALID');
});

it('rejects a missing epoch', function () {
    $tool = new TimeTool();
    $result = $tool->execute([
        'action' => 'format',
    ], 1);

    expect($result->success)->toBeFalse()
        ->and($result->data['error_code'])->toBe('EPOCH_INVALID');
});

it('rejects an unknown IANA timezone', function () {
    $tool = new TimeTool();
    $result = $tool->execute([
        'action'    => 'format',
        'epoch'     => 1753464720,
        'timezone'  => 'Atlantis/Mu',
    ], 1);

    expect($result->success)->toBeFalse()
        ->and($result->data['error_code'])->toBe('TIMEZONE_UNKNOWN')
        ->and($result->content)->toContain("Unknown IANA timezone 'Atlantis/Mu'");
});

it('rejects an unknown operation', function () {
    $tool = new TimeTool();
    $result = $tool->execute(['action' => 'rewind'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain("Unknown operation 'rewind'");
});

it('describes the now and format actions', function () {
    $tool = new TimeTool();

    expect($tool->describeAction(['action' => 'now']))->toBe('Get current date and time')
        ->and($tool->describeAction(['action' => 'format', 'epoch' => 1753464720]))
        ->toBe('Format epoch 1753464720 as datetime');
});
