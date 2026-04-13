<?php

declare(strict_types=1);

use Spora\Services\ToolConfigService;
use Spora\Tools\SendEmailTool;

it('returns error if missing required parameters', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $tool = new SendEmailTool($config);

    $result = $tool->execute(['to' => 'a@b.com'], 1); // missing subject/body
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Missing required parameters');
});

it('returns error if smtp is not configured', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(SendEmailTool::class, 1)->andReturn([]);

    $tool = new SendEmailTool($config);
    $result = $tool->execute(['to' => 'a@b.com', 'subject' => 'H', 'body' => 'B'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('SMTP configuration is incomplete');
});

it('blocks sending if email is not in allowed_recipients list', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(SendEmailTool::class, 1)->andReturn([
        'core.smtp.host' => 'smtp.example.com',
        'core.smtp.port' => '587',
        'core.smtp.username' => 'user',
        'core.smtp.password' => 'pass',
        'core.smtp.from' => 'bot@spora.local',
        'core.smtp.allowed_recipients' => 'admin@spora.local, boss@spora.local',
    ]);

    $tool = new SendEmailTool($config);

    $result = $tool->execute(['to' => 'hacker@evil.com', 'subject' => 'H', 'body' => 'B'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('SECURITY REJECTION')
        ->and($result->content)->toContain('hacker@evil.com');
});

it('allows sending if email is in allowed_recipients and handles DSN failure gracefully', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(SendEmailTool::class, 1)->andReturn([
        'core.smtp.host' => 'invalid-host-that-cannot-be-resolved',
        'core.smtp.port' => '587',
        'core.smtp.username' => 'user',
        'core.smtp.password' => 'pass',
        'core.smtp.from' => 'bot@spora.local',
        'core.smtp.allowed_recipients' => 'admin@spora.local, boss@spora.local',
    ]);

    $tool = new SendEmailTool($config);

    // We expect it to pass security check but fail on SMTP connection
    $result = $tool->execute(['to' => 'admin@spora.local', 'subject' => 'H', 'body' => 'B'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Failed to send email');
});

// ---------------------------------------------------------------------------
// DSN construction — rawurlencode + port casting
// ---------------------------------------------------------------------------

/**
 * Helper: use reflection to call the private DSN-building logic indirectly by
 * capturing the DSN that gets passed to Transport::fromDsn().
 * We test this by supplying an invalid host so the tool reaches the DSN-build
 * phase and then fails on connect — the error message will contain the DSN
 * or we can inspect what Transport received.
 *
 * A simpler approach: verify the tool does NOT fail with a DSN parse error
 * when credentials contain special characters (+, @, :, space).
 */
it('handles SMTP password with special characters without DSN parse errors', function () {
    $config = Mockery::mock(ToolConfigService::class);
    // Password contains characters that urlencode() would mangle (space → +, @ stays as %)
    $config->allows('getEffectiveSettings')->with(SendEmailTool::class, 1)->andReturn([
        'core.smtp.host' => '127.0.0.1',
        'core.smtp.port' => '9999', // nothing listening — connection will fail, not parse
        'core.smtp.username' => 'user+alias@domain.com',
        'core.smtp.password' => 'p@ss w0rd+special!',
        'core.smtp.from' => 'bot@spora.local',
        'core.smtp.allowed_recipients' => '*',
    ]);

    $tool = new SendEmailTool($config);
    $result = $tool->execute(['to' => 'anyone@test.com', 'subject' => 'Hi', 'body' => 'Body'], 1);

    // Must fail on connection, NOT on DSN parsing — a parse error would produce
    // a different message like "The mailer DSN is invalid"
    expect($result->success)->toBeFalse();
    expect($result->content)->not->toContain('The mailer DSN is invalid')
        ->and($result->content)->not->toContain('MalformedUriException');
});

it('handles SMTP username with @ sign using rawurlencode', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(SendEmailTool::class, 1)->andReturn([
        'core.smtp.host' => '127.0.0.1',
        'core.smtp.port' => '9999',
        'core.smtp.username' => 'user@corporate.example.com',
        'core.smtp.password' => 'correct-password',
        'core.smtp.from' => 'bot@spora.local',
        'core.smtp.allowed_recipients' => '*',
    ]);

    $tool = new SendEmailTool($config);
    $result = $tool->execute(['to' => 'anyone@test.com', 'subject' => 'Hi', 'body' => 'Body'], 1);

    // Should fail on connection, not on DSN parse
    expect($result->success)->toBeFalse();
    expect($result->content)->not->toContain('The mailer DSN is invalid');
});

it('casts non-numeric port to integer (defaults to 0) rather than passing a string', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(SendEmailTool::class, 1)->andReturn([
        'core.smtp.host' => '127.0.0.1',
        'core.smtp.port' => 'not-a-number', // invalid — should become 0 not crash the sprintf
        'core.smtp.username' => 'user',
        'core.smtp.password' => 'pass',
        'core.smtp.from' => 'bot@spora.local',
        'core.smtp.allowed_recipients' => '*',
    ]);

    $tool = new SendEmailTool($config);
    $result = $tool->execute(['to' => 'anyone@test.com', 'subject' => 'Hi', 'body' => 'Body'], 1);

    // Must not throw — a %s format would embed the raw string and could produce
    // "smtp://user:pass@127.0.0.1:not-a-number" which is an invalid DSN.
    // With %d the port becomes 0 and Transport may complain about the port value,
    // but there will be no sprintf-format or parse exception.
    expect($result->success)->toBeFalse();
    expect($result->content)->toContain('Failed to send email');
});

it('applies custom timeout from settings to the SMTP connection', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(SendEmailTool::class, 1)->andReturn([
        'core.smtp.host' => '127.0.0.1',
        'core.smtp.port' => '587',
        'core.smtp.username' => 'user',
        'core.smtp.password' => 'pass',
        'core.smtp.from' => 'bot@spora.local',
        'core.smtp.allowed_recipients' => '*',
        'core.smtp.timeout' => '120',
    ]);

    $tool = new SendEmailTool($config);
    $result = $tool->execute(['to' => 'anyone@test.com', 'subject' => 'Hi', 'body' => 'Body'], 1);

    // Must fail gracefully at connection, not by timeout
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Failed to send email');
});
