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
        'core.smtp.dsn' => 'smtp://test',
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
        'core.smtp.dsn' => 'invalid-dsn://test',
        'core.smtp.from' => 'bot@spora.local',
        'core.smtp.allowed_recipients' => 'admin@spora.local, boss@spora.local',
    ]);

    $tool = new SendEmailTool($config);

    // We expect it to pass security check but fail on symfony/mailer's DSN parsing
    $result = $tool->execute(['to' => 'admin@spora.local', 'subject' => 'H', 'body' => 'B'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Failed to send email');
});
