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
        ->and($schema['properties']['epoch']['type'])->toBe('integer')
        ->and($schema['properties']['epoch']['minimum'])->toBe(0)
        ->and($schema['properties']['timezone']['type'])->toBe('string')
        ->and($schema['properties']['timezone']['default'])->toBe('UTC')
        ->and($schema['properties']['format']['enum'])->toBe(['iso8601', 'rfc2822', 'human']);
});

it('formats an epoch as iso8601 by default in UTC', function () {
    $tool = new TimeTool();
    $result = $tool->execute([
        'action' => 'format',
        'epoch'  => 1753464720, // 2025-07-25T17:32:00Z
    ], 1);

    $payload = json_decode((string) $result->content, true, flags: JSON_THROW_ON_ERROR);

    expect($result->success)->toBeTrue()
        ->and($payload['formatted'])->toBe('2025-07-25T17:32:00+00:00')
        ->and($payload['weekday'])->toBe('Friday')
        ->and($result->data['formatted'])->toBe('2025-07-25T17:32:00+00:00')
        ->and($result->data['weekday'])->toBe('Friday');
});

it('formats an epoch in a chosen IANA timezone', function () {
    $tool = new TimeTool();
    $result = $tool->execute([
        'action'    => 'format',
        'epoch'     => 1753464720,
        'timezone'  => 'Asia/Tokyo',
    ], 1);

    // 17:32 UTC → 02:32 the next day in Tokyo (UTC+9).
    $payload = json_decode((string) $result->content, true, flags: JSON_THROW_ON_ERROR);

    expect($result->success)->toBeTrue()
        ->and($payload['formatted'])->toBe('2025-07-26T02:32:00+09:00')
        ->and($payload['weekday'])->toBe('Saturday')
        ->and($result->data['formatted'])->toBe('2025-07-26T02:32:00+09:00')
        ->and($result->data['weekday'])->toBe('Saturday');
});

it('formats an epoch in the human format', function () {
    $tool = new TimeTool();
    $result = $tool->execute([
        'action'   => 'format',
        'epoch'    => 1753464720,
        'timezone' => 'UTC',
        'format'   => 'human',
    ], 1);

    $payload = json_decode((string) $result->content, true, flags: JSON_THROW_ON_ERROR);

    expect($result->success)->toBeTrue()
        ->and($payload['formatted'])->toBe('2025-07-25 17:32:00 UTC')
        ->and($payload['weekday'])->toBe('Friday')
        ->and($result->data['formatted'])->toBe('2025-07-25 17:32:00 UTC')
        ->and($result->data['weekday'])->toBe('Friday');
});

it('formats an epoch in rfc2822', function () {
    $tool = new TimeTool();
    $result = $tool->execute([
        'action'   => 'format',
        'epoch'    => 1753464720,
        'timezone' => 'UTC',
        'format'   => 'rfc2822',
    ], 1);

    $payload = json_decode((string) $result->content, true, flags: JSON_THROW_ON_ERROR);

    expect($result->success)->toBeTrue()
        ->and($payload['formatted'])->toContain('Fri, 25 Jul 2025')
        ->and($payload['weekday'])->toBe('Friday');
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

it('returns the weekday on now', function () {
    $tool = new TimeTool();
    $result = $tool->execute([], 1);

    expect($result->success)->toBeTrue()
        ->and($result->data['weekday'])->toBeIn(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']);
});

it('returns the weekday on format', function () {
    $tool = new TimeTool();
    $result = $tool->execute([
        'action'   => 'format',
        'epoch'    => 1753464720,
        'timezone' => 'UTC',
    ], 1);

    $payload = json_decode((string) $result->content, true, flags: JSON_THROW_ON_ERROR);

    expect($result->success)->toBeTrue()
        ->and($payload['weekday'])->toBe('Friday')
        ->and($result->data['weekday'])->toBe('Friday');
});

it('does not require epoch when only the now operation is enabled', function () {
    // Per-op required[] narrowing: a strict LLM provider validates the
    // request against the schema's required[] before dispatch. Without
    // this narrowing, the LLM is forced to send dummy `epoch: 0` values
    // to call `now`, which only confuses it (and the audit caught the
    // agent doing exactly that).
    $tool   = new TimeTool();
    $schema = $tool->getParametersSchema();
    $filtered = Spora\Tools\Schema\OperationSchemaFilter::filter($schema, ['now'], 'action');

    expect($filtered['required'])->toBe(['action'])
        ->and($filtered['properties']['action']['enum'])->toBe(['now']);

    // And the reverse: with `format` only, epoch is required.
    $filteredFormat = Spora\Tools\Schema\OperationSchemaFilter::filter($schema, ['format'], 'action');
    expect($filteredFormat['required'])->toContain('action', 'epoch');
});
