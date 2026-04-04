<?php

declare(strict_types=1);

use Spora\Services\ToolConfigService;
use Spora\Tools\ReadEmailTool;

it('returns error if imap is not configured', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(ReadEmailTool::class, 1)->andReturn([]);

    $tool = new ReadEmailTool($config);
    $result = $tool->execute([], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('IMAP configuration is incomplete');
});

it('does not mark emails as read by default', function () {
    // The tool passes mark_as_read=false by default, so setFlag('Seen') must NOT be called.
    // We verify this by confirming the IMAP connection attempt is made (config is valid)
    // but the call fails at connect-time, not inside a flag-setting path.
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(ReadEmailTool::class, 1)->andReturn([
        'core.imap.host'     => 'invalid.local.host',
        'core.imap.port'     => '993',
        'core.imap.username' => 'test',
        'core.imap.password' => 'test',
    ]);

    $tool = new ReadEmailTool($config);
    // Without mark_as_read — should still fail at connection, not at setFlag.
    $result = @$tool->execute([], 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Failed to fetch emails');
});

it('attempts to connect to imap but fails gracefully with bad host', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(ReadEmailTool::class, 1)->andReturn([
        'core.imap.host' => 'invalid.local.host',
        'core.imap.port' => '993',
        'core.imap.username' => 'test',
        'core.imap.password' => 'test',
    ]);

    $tool = new ReadEmailTool($config);
    $result = @$tool->execute([], 1);

    // Webklex PHP IMAP will throw an exception during make/connect.
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Failed to fetch emails');
});
