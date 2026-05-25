<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use Spora\Services\SystemMailer;

// ---------------------------------------------------------------------------
// Log driver — NullLogger fix
// ---------------------------------------------------------------------------

test('SystemMailer with log driver and no logger injected uses NullLogger', function (): void {
    $mailer = new SystemMailer(['mail_driver' => 'log']);

    $threw = false;
    try {
        $mailer->buildMailer();
    } catch (Throwable) {
        $threw = true;
    }

    expect($threw)->toBeFalse();
});

test('SystemMailer with log driver and real logger injected returns a Mailer', function (): void {
    $logger = new NullLogger();

    $mailer = new SystemMailer(['mail_driver' => 'log'], $logger);

    $builtMailer = $mailer->buildMailer();
    expect($builtMailer)->toBeInstanceOf(Symfony\Component\Mailer\Mailer::class);
});
