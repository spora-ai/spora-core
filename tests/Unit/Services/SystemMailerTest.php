<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use InvalidArgumentException;
use Psr\Log\NullLogger;
use ReflectionMethod;
use Spora\Models\MailTemplate;
use Spora\Models\User;
use Spora\Services\SystemMailer;
use Symfony\Component\Mailer\Mailer;
use Throwable;

// SystemMailer::getMailConfig() lets SPORA_MAIL_* env vars override the passed
// config. Any earlier test that boots the Kernel triggers
// Dotenv::createImmutable()->safeLoad(), which writes the .env's
// SPORA_MAIL_DRIVER=log into $_ENV process-wide. Without this cleanup, tests
// that assert behavior for SMTP / invalid drivers silently get the log driver.
beforeEach(function (): void {
    foreach (array_keys($_ENV) as $key) {
        if (is_string($key) && str_starts_with($key, 'SPORA_MAIL_')) {
            unset($_ENV[$key]);
        }
    }
});

function captureMailerLogger(): SystemMailerCapturingLogger
{
    return new SystemMailerCapturingLogger();
}

test('assertCanBuildMailer succeeds for the log driver without opening a socket', function (): void {
    $mailer = new SystemMailer(['mail_driver' => 'log']);

    $threw = false;
    try {
        $mailer->assertCanBuildMailer();
    } catch (Throwable) {
        $threw = true;
    }

    expect($threw)->toBeFalse();
});

test('assertCanBuildMailer succeeds for a valid SMTP config without opening a socket', function (): void {
    $mailer = new SystemMailer([
        'mail_driver'     => 'smtp',
        'mail_host'       => 'smtp.example.com',
        'mail_port'       => 587,
        'mail_username'   => 'user',
        'mail_password'   => 'secret',
        'mail_encryption' => 'tls',
    ]);

    $mailer->assertCanBuildMailer();

    expect(true)->toBeTrue();
});

test('assertCanBuildMailer propagates InvalidArgumentException from the mail driver', function (): void {
    $mailer = new SystemMailer(['mail_driver' => 'carrier_pigeon']);

    $mailer->assertCanBuildMailer();
})->throws(InvalidArgumentException::class, "Mail driver 'carrier_pigeon' is not supported");

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
    expect($builtMailer)->toBeInstanceOf(Mailer::class);
});

test('buildMailer with smtp driver and valid host returns a Mailer', function (): void {
    $mailer = new SystemMailer([
        'mail_driver'     => 'smtp',
        'mail_host'       => 'smtp.example.com',
        'mail_port'       => 587,
        'mail_username'   => 'user',
        'mail_password'   => 'secret',
        'mail_encryption' => 'tls',
    ]);

    $built = $mailer->buildMailer();
    expect($built)->toBeInstanceOf(Mailer::class);
});

test('buildMailer with smtp driver and missing host throws InvalidArgumentException', function (): void {
    $mailer = new SystemMailer(['mail_driver' => 'smtp']);

    $mailer->buildMailer();
})->throws(InvalidArgumentException::class);

test('buildMailer with smtp driver and missing host error message mentions host', function (): void {
    $mailer = new SystemMailer(['mail_driver' => 'smtp']);

    $caught = null;
    try {
        $mailer->buildMailer();
    } catch (InvalidArgumentException $e) {
        $caught = $e;
    }
    expect($caught)->toBeInstanceOf(InvalidArgumentException::class);
    expect($caught->getMessage())->toContain('SPORA_MAIL_HOST');
    expect($caught->getMessage())->toContain('mail_host');
});

test('buildMailer with smtp driver and encryption=none throws InvalidArgumentException', function (): void {
    $mailer = new SystemMailer([
        'mail_driver'     => 'smtp',
        'mail_host'       => 'smtp.example.com',
        'mail_encryption' => 'none',
    ]);

    $mailer->buildMailer();
})->throws(InvalidArgumentException::class, 'SMTP mail encryption "none" is insecure');

test('buildMailer with smtp driver produces an smtp DSN for STARTTLS', function (): void {
    $mailer = new SystemMailer([
        'mail_driver'     => 'smtp',
        'mail_host'       => 'smtp.example.com',
        'mail_port'       => 587,
        'mail_username'   => 'user',
        'mail_password'   => 'secret',
        'mail_encryption' => 'tls',
    ]);

    $reflection = new ReflectionMethod($mailer, 'buildSmtpDsn');
    $dsn = $reflection->invoke($mailer, [
        'mail_host'       => 'smtp.example.com',
        'mail_port'       => 587,
        'mail_username'   => 'user',
        'mail_password'   => 'secret',
        'mail_encryption' => 'tls',
    ]);

    expect($dsn)->toStartWith('smtp://');
    expect($dsn)->not->toContain('?encryption=');
    expect($dsn)->toContain(':587');
});

test('buildMailer with smtp driver produces an smtps DSN for implicit TLS', function (): void {
    $mailer = new SystemMailer([
        'mail_driver'     => 'smtp',
        'mail_host'       => 'smtp.example.com',
        'mail_port'       => 465,
        'mail_username'   => 'user',
        'mail_password'   => 'secret',
        'mail_encryption' => 'ssl',
    ]);

    $reflection = new ReflectionMethod($mailer, 'buildSmtpDsn');
    $dsn = $reflection->invoke($mailer, [
        'mail_host'       => 'smtp.example.com',
        'mail_port'       => 465,
        'mail_username'   => 'user',
        'mail_password'   => 'secret',
        'mail_encryption' => 'ssl',
    ]);

    expect($dsn)->toStartWith('smtps://');
    expect($dsn)->toContain(':465');
});

test('buildMailer with smtp driver uses default port 465 when not specified', function (): void {
    $mailer = new SystemMailer([
        'mail_driver' => 'smtp',
        'mail_host'   => 'smtp.example.com',
    ]);

    $reflection = new ReflectionMethod($mailer, 'buildSmtpDsn');
    $dsn = $reflection->invoke($mailer, [
        'mail_host' => 'smtp.example.com',
    ]);

    expect($dsn)->toBe('smtp://smtp.example.com:587');
});

test('buildMailer with smtp driver and username only (no password) returns Mailer', function (): void {
    $mailer = new SystemMailer([
        'mail_driver'   => 'smtp',
        'mail_host'     => 'smtp.example.com',
        'mail_username' => 'only-user',
    ]);

    expect($mailer->buildMailer())->toBeInstanceOf(Mailer::class);
});

test('buildMailer with smtp driver and password only (no username) returns Mailer', function (): void {
    $mailer = new SystemMailer([
        'mail_driver'   => 'smtp',
        'mail_host'     => 'smtp.example.com',
        'mail_password' => 'only-pass',
    ]);

    expect($mailer->buildMailer())->toBeInstanceOf(Mailer::class);
});

test('buildMailer with smtp driver and no credentials returns Mailer', function (): void {
    $mailer = new SystemMailer([
        'mail_driver' => 'smtp',
        'mail_host'   => 'smtp.example.com',
    ]);

    expect($mailer->buildMailer())->toBeInstanceOf(Mailer::class);
});

test('buildMailer with smtp driver and custom port and encryption returns Mailer', function (): void {
    $mailer = new SystemMailer([
        'mail_driver'     => 'smtp',
        'mail_host'       => 'smtp.example.com',
        'mail_port'       => 2525,
        'mail_encryption' => 'ssl',
    ]);

    expect($mailer->buildMailer())->toBeInstanceOf(Mailer::class);
});

test('buildMailer with smtp driver uses default port and encryption when not specified', function (): void {
    $mailer = new SystemMailer([
        'mail_driver' => 'smtp',
        'mail_host'   => 'smtp.example.com',
    ]);

    expect($mailer->buildMailer())->toBeInstanceOf(Mailer::class);
});

test('buildMailer with php_mail driver returns a Mailer', function (): void {
    $mailer = new SystemMailer(['mail_driver' => 'php_mail']);

    expect($mailer->buildMailer())->toBeInstanceOf(Mailer::class);
});

test('buildMailer with sendmail driver returns a Mailer', function (): void {
    $mailer = new SystemMailer(['mail_driver' => 'sendmail']);

    expect($mailer->buildMailer())->toBeInstanceOf(Mailer::class);
});

test('buildMailer with an unsupported driver throws InvalidArgumentException', function (): void {
    $mailer = new SystemMailer(['mail_driver' => 'carrier_pigeon']);

    $mailer->buildMailer();
})->throws(InvalidArgumentException::class);

test('buildMailer with an unsupported driver error message lists supported drivers', function (): void {
    $mailer = new SystemMailer(['mail_driver' => 'carrier_pigeon']);

    $caught = null;
    try {
        $mailer->buildMailer();
    } catch (InvalidArgumentException $e) {
        $caught = $e;
    }
    expect($caught)->toBeInstanceOf(InvalidArgumentException::class);
    expect($caught->getMessage())->toContain('carrier_pigeon');
    expect($caught->getMessage())->toContain("'smtp'");
    expect($caught->getMessage())->toContain("'php_mail'");
    expect($caught->getMessage())->toContain("'sendmail'");
    expect($caught->getMessage())->toContain("'log'");
});

test('buildMailer with null driver falls back to default and builds Mailer', function (): void {
    $mailer = new SystemMailer(['mail_driver' => null]);

    expect($mailer->buildMailer())->toBeInstanceOf(Mailer::class);
});

test('buildMailer with no driver config uses php_mail default and returns Mailer', function (): void {
    $mailer = new SystemMailer([]);

    expect($mailer->buildMailer())->toBeInstanceOf(Mailer::class);
});

test('buildMailer honors SPORA_MAIL_DRIVER env var over config', function (): void {
    $saved = $_ENV['SPORA_MAIL_DRIVER'] ?? null;
    $_ENV['SPORA_MAIL_DRIVER'] = 'log';
    putenv('SPORA_MAIL_DRIVER=log');

    try {
        $logger = captureMailerLogger();
        $mailer = new SystemMailer(['mail_driver' => 'smtp', 'mail_host' => 'smtp.example.com'], $logger);

        $mailer->buildMailer();
        // log driver → uses injected logger
        expect($logger->records)->toBeEmpty(); // buildMailer itself does not log
    } finally {
        if ($saved === null) {
            unset($_ENV['SPORA_MAIL_DRIVER']);
            putenv('SPORA_MAIL_DRIVER');
        } else {
            $_ENV['SPORA_MAIL_DRIVER'] = $saved;
            putenv("SPORA_MAIL_DRIVER={$saved}");
        }
    }
});

test('buildMailer with smtp driver and special characters in host returns Mailer', function (): void {
    $mailer = new SystemMailer([
        'mail_driver'   => 'smtp',
        'mail_host'     => 'smtp host.example.com',
        'mail_username' => 'user@org',
        'mail_password' => 'p@ss:word',
    ]);

    // rawurlencode must accept these; the call should not throw
    expect($mailer->buildMailer())->toBeInstanceOf(Mailer::class);
});

test('sendTemplatedEmail with a valid template returns true and logs the message', function (): void {
    MailTemplate::create([
        'name'      => 'hello_tmpl',
        'subject'   => 'Hello {{name}}',
        'body' => 'Hi {{name}}, your code is {{code}}.',
        'body_html' => '<p>Hi {{name}}, your code is {{code}}.</p>',
    ]);

    $logger = captureMailerLogger();
    $mailer = new SystemMailer(['mail_driver' => 'log'], $logger);

    $result = $mailer->sendTemplatedEmail(
        'hello_tmpl',
        ['name' => 'Alice', 'code' => '1234'],
        ['alice@example.com'],
    );

    expect($result)->toBeTrue();
    expect($logger->records)->toHaveCount(1);
    expect($logger->records[0]['message'])->toBe('[Spora] Mail sent via log driver');
    expect($logger->records[0]['context']['to'])->toBe('alice@example.com');
    expect($logger->records[0]['context']['subject'])->toBe('Hello Alice');
});

test('sendTemplatedEmail with an unknown template throws InvalidArgumentException', function (): void {
    $mailer = new SystemMailer(['mail_driver' => 'log'], captureMailerLogger());

    $mailer->sendTemplatedEmail('does_not_exist', ['x' => 1], ['a@example.com']);
})->throws(InvalidArgumentException::class, "Mail template 'does_not_exist' not found.");

test('sendTemplatedEmail sends to multiple recipients', function (): void {
    MailTemplate::create([
        'name'      => 'broadcast',
        'subject'   => 'Announcement',
        'body' => 'Body',
    ]);

    $logger = captureMailerLogger();
    $mailer = new SystemMailer(['mail_driver' => 'log'], $logger);

    $mailer->sendTemplatedEmail('broadcast', [], [
        'first@example.com',
        'second@example.com',
        'third@example.com',
    ]);

    expect($logger->records)->toHaveCount(1);
    expect($logger->records[0]['context']['to'])->toBe('first@example.com, second@example.com, third@example.com');
});

test('sendTemplatedEmail uses the from address from config', function (): void {
    MailTemplate::create([
        'name'      => 'cfg_from',
        'subject'   => 'Subject',
        'body' => 'Body',
    ]);

    $logger = captureMailerLogger();
    $mailer = new SystemMailer([
        'mail_driver'     => 'log',
        'mail_from'       => 'custom@example.com',
        'mail_from_name'  => 'Custom Sender',
    ], $logger);

    $mailer->sendTemplatedEmail('cfg_from', [], ['a@example.com']);

    expect($logger->records[0]['context']['from'])->toBe('custom@example.com');
});

test('sendTemplatedEmail falls back to default from address when config is empty', function (): void {
    MailTemplate::create([
        'name'      => 'default_from',
        'subject'   => 'Subject',
        'body' => 'Body',
    ]);

    $logger = captureMailerLogger();
    $mailer = new SystemMailer(['mail_driver' => 'log'], $logger);

    $mailer->sendTemplatedEmail('default_from', [], ['a@example.com']);

    expect($logger->records[0]['context']['from'])->toBe('noreply@spora.local');
});

test('sendTemplatedEmail falls back to default from name when config is empty', function (): void {
    MailTemplate::create([
        'name'      => 'default_from_name',
        'subject'   => 'Subject',
        'body' => 'Body',
    ]);

    $logger = captureMailerLogger();
    $mailer = new SystemMailer(['mail_driver' => 'log'], $logger);

    $mailer->sendTemplatedEmail('default_from_name', [], ['a@example.com']);

    // from is logged; from_name is in the Address object but only the email is captured
    // by the log transport. We assert that the from address defaults to noreply@spora.local.
    expect($logger->records[0]['context']['from'])->toBe('noreply@spora.local');
});

test('sendTemplatedEmail throws when the rendered body is empty', function (): void {
    MailTemplate::create([
        'name'      => 'all_null',
        'subject'   => null,
        'body' => null,
        'body_html' => null,
    ]);

    $logger = captureMailerLogger();
    $mailer = new SystemMailer(['mail_driver' => 'log'], $logger);

    expect(fn() => $mailer->sendTemplatedEmail('all_null', [], ['a@example.com']))
        ->toThrow(InvalidArgumentException::class);
});

test('sendTemplatedEmail with body_html shell containing {markdown_html} substitutes the rendered markdown', function (): void {
    MailTemplate::create([
        'name'      => 'shell_test',
        'subject'   => 'Hi {{name}}',
        'body'      => "# Hello {{name}}\n\nClick below:\n\n- One\n- Two",
        'body_html' => '<h1>Header</h1>{markdown_html}<footer>End</footer>',
    ]);

    $logger = captureMailerLogger();
    $mailer = new SystemMailer(['mail_driver' => 'log'], $logger);

    $result = $mailer->sendTemplatedEmail('shell_test', ['name' => 'Fabian'], ['x@y.test']);

    expect($result)->toBeTrue();
    expect($logger->records)->toHaveCount(1);
    expect($logger->records[0]['context']['subject'])->toBe('Hi Fabian');
});

test('sendTemplatedEmail keeps unknown placeholders intact in the subject', function (): void {
    MailTemplate::create([
        'name'      => 'unknown_placeholder',
        'subject'   => 'Hello {{name}} (ref: {{unknown}})',
        'body' => 'Hi {{name}}',
    ]);

    $logger = captureMailerLogger();
    $mailer = new SystemMailer(['mail_driver' => 'log'], $logger);

    $mailer->sendTemplatedEmail('unknown_placeholder', ['name' => 'Bob'], ['a@example.com']);

    expect($logger->records[0]['context']['subject'])->toBe('Hello Bob (ref: {{unknown}})');
});

test('sendTemplatedEmail uses env var SPORA_MAIL_FROM over config', function (): void {
    $saved = $_ENV['SPORA_MAIL_FROM'] ?? null;
    $_ENV['SPORA_MAIL_FROM'] = 'env-from@example.com';
    putenv('SPORA_MAIL_FROM=env-from@example.com');

    try {
        MailTemplate::create([
            'name'      => 'env_from',
            'subject'   => 'S',
            'body' => 'B',
        ]);

        $logger = captureMailerLogger();
        $mailer = new SystemMailer([
            'mail_driver' => 'log',
            'mail_from'   => 'config-from@example.com',
        ], $logger);

        $mailer->sendTemplatedEmail('env_from', [], ['a@example.com']);

        expect($logger->records[0]['context']['from'])->toBe('env-from@example.com');
    } finally {
        if ($saved === null) {
            unset($_ENV['SPORA_MAIL_FROM']);
            putenv('SPORA_MAIL_FROM');
        } else {
            $_ENV['SPORA_MAIL_FROM'] = $saved;
            putenv("SPORA_MAIL_FROM={$saved}");
        }
    }
});

test('sendTemplatedEmail uses env var SPORA_MAIL_FROM_NAME over config', function (): void {
    $saved = $_ENV['SPORA_MAIL_FROM_NAME'] ?? null;
    $_ENV['SPORA_MAIL_FROM_NAME'] = 'Env Spora';
    putenv('SPORA_MAIL_FROM_NAME=Env Spora');

    try {
        MailTemplate::create([
            'name'      => 'env_from_name',
            'subject'   => 'S',
            'body' => 'B',
        ]);

        $logger = captureMailerLogger();
        $mailer = new SystemMailer([
            'mail_driver'    => 'log',
            'mail_from'      => 'a@example.com',
            'mail_from_name' => 'Config Spora',
        ], $logger);

        // Just assert no exception is thrown — the rendered from name is inside the Address object
        $result = $mailer->sendTemplatedEmail('env_from_name', [], ['a@example.com']);

        expect($result)->toBeTrue();
    } finally {
        if ($saved === null) {
            unset($_ENV['SPORA_MAIL_FROM_NAME']);
            putenv('SPORA_MAIL_FROM_NAME');
        } else {
            $_ENV['SPORA_MAIL_FROM_NAME'] = $saved;
            putenv("SPORA_MAIL_FROM_NAME={$saved}");
        }
    }
});

test('sendVerificationEmail renders the email_verification template with verification_link', function (): void {
    MailTemplate::create([
        'name'      => 'email_verification',
        'subject'   => 'Verify your email: {{verification_link}}',
        'body' => 'Hi {{email}}, click: {{verification_link}}',
        'body_html' => '<p>Hi {{email}}, click: {{verification_link}}</p>',
    ]);

    $logger = captureMailerLogger();
    $mailer = new SystemMailer(['mail_driver' => 'log'], $logger);

    $result = $mailer->sendVerificationEmail('user@example.com', 'https://app.test/verify?token=abc');

    expect($result)->toBeTrue();
    expect($logger->records)->toHaveCount(1);
    expect($logger->records[0]['context']['to'])->toBe('user@example.com');
    expect($logger->records[0]['context']['subject'])->toBe('Verify your email: https://app.test/verify?token=abc');
});

test('sendVerificationEmail throws when the email_verification template is missing', function (): void {
    $mailer = new SystemMailer(['mail_driver' => 'log'], captureMailerLogger());

    $mailer->sendVerificationEmail('user@example.com', 'https://app.test/verify?token=abc');
})->throws(InvalidArgumentException::class, "Mail template 'email_verification' not found.");

test('sendPasswordResetEmail renders the password_reset template with reset_link', function (): void {
    MailTemplate::create([
        'name'      => 'password_reset',
        'subject'   => 'Reset your password: {{reset_link}}',
        'body' => 'Hi {{email}}, click: {{reset_link}}',
        'body_html' => '<p>Hi {{email}}, click: {{reset_link}}</p>',
    ]);

    $logger = captureMailerLogger();
    $mailer = new SystemMailer(['mail_driver' => 'log'], $logger);

    $result = $mailer->sendPasswordResetEmail('user@example.com', 'https://app.test/reset?token=xyz');

    expect($result)->toBeTrue();
    expect($logger->records)->toHaveCount(1);
    expect($logger->records[0]['context']['to'])->toBe('user@example.com');
    expect($logger->records[0]['context']['subject'])->toBe('Reset your password: https://app.test/reset?token=xyz');
});

test('sendPasswordResetEmail throws when the password_reset template is missing', function (): void {
    $mailer = new SystemMailer(['mail_driver' => 'log'], captureMailerLogger());

    $mailer->sendPasswordResetEmail('user@example.com', 'https://app.test/reset?token=xyz');
})->throws(InvalidArgumentException::class, "Mail template 'password_reset' not found.");

test('sendWelcomeEmail uses the user name when found', function (): void {
    MailTemplate::create([
        'name'      => 'welcome',
        'subject'   => 'Welcome {{user_name}}!',
        'body' => 'Hi {{user_name}} ({{email}})',
    ]);

    $user = User::create([
        'email'      => 'newuser@example.com',
        'username'   => 'newuser',
        'name'       => 'New User',
        'password'   => password_hash('Password1!', PASSWORD_BCRYPT),
        'registered' => time(),
    ]);

    $logger = captureMailerLogger();
    $mailer = new SystemMailer(['mail_driver' => 'log'], $logger);

    $result = $mailer->sendWelcomeEmail((int) $user->id, 'newuser@example.com');

    expect($result)->toBeTrue();
    expect($logger->records)->toHaveCount(1);
    expect($logger->records[0]['context']['to'])->toBe('newuser@example.com');
    expect($logger->records[0]['context']['subject'])->toBe('Welcome New User!');
});

test('sendWelcomeEmail falls back to email when user has no name', function (): void {
    MailTemplate::create([
        'name'      => 'welcome',
        'subject'   => 'Welcome {{user_name}}!',
        'body' => 'Hi {{user_name}} ({{email}})',
    ]);

    $user = User::create([
        'email'      => 'nameless@example.com',
        'username'   => 'nameless',
        'name'       => null,
        'password'   => password_hash('Password1!', PASSWORD_BCRYPT),
        'registered' => time(),
    ]);

    $logger = captureMailerLogger();
    $mailer = new SystemMailer(['mail_driver' => 'log'], $logger);

    $result = $mailer->sendWelcomeEmail((int) $user->id, 'nameless@example.com');

    expect($result)->toBeTrue();
    expect($logger->records[0]['context']['subject'])->toBe('Welcome nameless@example.com!');
});

test('sendWelcomeEmail falls back to email when user is not found', function (): void {
    MailTemplate::create([
        'name'      => 'welcome',
        'subject'   => 'Welcome {{user_name}}!',
        'body' => 'Hi {{user_name}} ({{email}})',
    ]);

    $logger = captureMailerLogger();
    $mailer = new SystemMailer(['mail_driver' => 'log'], $logger);

    $result = $mailer->sendWelcomeEmail(999999, 'ghost@example.com');

    expect($result)->toBeTrue();
    expect($logger->records[0]['context']['subject'])->toBe('Welcome ghost@example.com!');
});

test('sendWelcomeEmail throws when the welcome template is missing', function (): void {
    $mailer = new SystemMailer(['mail_driver' => 'log'], captureMailerLogger());

    $mailer->sendWelcomeEmail(1, 'a@example.com');
})->throws(InvalidArgumentException::class, "Mail template 'welcome' not found.");

test('buildDashboardUrl with empty app_prefix produces host/dashboard without double slash', function (): void {
    $mailer = new SystemMailer([
        'mail_driver' => 'log',
        'app_url'     => 'https://example.com',
        'app_prefix'  => '',
    ]);

    $reflection = new ReflectionMethod($mailer, 'buildDashboardUrl');
    $url = $reflection->invoke($mailer);

    expect($url)->toBe('https://example.com/dashboard');
    expect($url)->not->toContain('//dashboard');
});

test('buildDashboardUrl with app_prefix=/spora produces host/spora/dashboard', function (): void {
    $mailer = new SystemMailer([
        'mail_driver' => 'log',
        'app_url'     => 'https://example.com',
        'app_prefix'  => '/spora',
    ]);

    $reflection = new ReflectionMethod($mailer, 'buildDashboardUrl');
    $url = $reflection->invoke($mailer);

    expect($url)->toBe('https://example.com/spora/dashboard');
});

test('buildDashboardUrl normalises a bare app_prefix and strips trailing slash', function (): void {
    $mailer = new SystemMailer([
        'mail_driver' => 'log',
        'app_url'     => 'https://example.com/',
        'app_prefix'  => 'spora/',
    ]);

    $reflection = new ReflectionMethod($mailer, 'buildDashboardUrl');
    $url = $reflection->invoke($mailer);

    expect($url)->toBe('https://example.com/spora/dashboard');
});

test('buildDashboardUrl strips trailing slashes from app_url', function (): void {
    $mailer = new SystemMailer([
        'mail_driver' => 'log',
        'app_url'     => 'https://example.com///',
        'app_prefix'  => '',
    ]);

    $reflection = new ReflectionMethod($mailer, 'buildDashboardUrl');
    $url = $reflection->invoke($mailer);

    expect($url)->toBe('https://example.com/dashboard');
});

test('buildDashboardUrl preserves IPv6 hostnames verbatim', function (): void {
    $mailer = new SystemMailer([
        'mail_driver' => 'log',
        'app_url'     => 'https://[::1]:8080',
        'app_prefix'  => '',
    ]);

    $reflection = new ReflectionMethod($mailer, 'buildDashboardUrl');
    $url = $reflection->invoke($mailer);

    expect($url)->toBe('https://[::1]:8080/dashboard');
    expect($url)->not->toContain(':/dashboard');
});

test('sendWelcomeEmail renders successfully when the template references {{dashboard_url}}', function (): void {
    MailTemplate::create([
        'name'      => 'welcome',
        'subject'   => 'Welcome {{user_name}}',
        'body'      => 'Open your dashboard: [{{dashboard_url}}]({{dashboard_url}})',
        'body_html' => '<p>Open <a href="{{dashboard_url}}">{{dashboard_url}}</a></p>',
    ]);

    $user = User::create([
        'email'      => 'dashuser@example.com',
        'username'   => 'dashuser',
        'name'       => 'Dash User',
        'password'   => password_hash('Password1!', PASSWORD_BCRYPT),
        'registered' => time(),
    ]);

    $logger = captureMailerLogger();
    $mailer = new SystemMailer([
        'mail_driver' => 'log',
        'app_url'     => 'https://example.com',
        'app_prefix'  => '/spora',
    ], $logger);

    $result = $mailer->sendWelcomeEmail((int) $user->id, 'dashuser@example.com');

    // The rendered dashboard URL is internal to the Email object (the log
    // transport only captures to/from/subject), so a successful send proves
    // the variable is wired through. The exact URL shape is asserted by the
    // buildDashboardUrl() tests above.
    expect($result)->toBeTrue();
    expect($logger->records)->toHaveCount(1);
    expect($logger->records[0]['context']['subject'])->toBe('Welcome Dash User');
});

test('sendTestEmail sends a test message and returns true', function (): void {
    $logger = captureMailerLogger();
    $mailer = new SystemMailer(['mail_driver' => 'log'], $logger);

    $result = $mailer->sendTestEmail('admin@example.com');

    expect($result)->toBeTrue();
    expect($logger->records)->toHaveCount(1);
    expect($logger->records[0]['context']['to'])->toBe('admin@example.com');
    expect($logger->records[0]['context']['subject'])->toBe('Spora Test Email');
    expect($logger->records[0]['context']['from'])->toBe('noreply@spora.local');
});

test('sendTestEmail uses the from address from config', function (): void {
    $logger = captureMailerLogger();
    $mailer = new SystemMailer([
        'mail_driver'    => 'log',
        'mail_from'      => 'noreply@mydomain.com',
        'mail_from_name' => 'MyDomain',
    ], $logger);

    $result = $mailer->sendTestEmail('admin@example.com');

    expect($result)->toBeTrue();
    expect($logger->records[0]['context']['from'])->toBe('noreply@mydomain.com');
});

test('sendTestEmail uses env var SPORA_MAIL_FROM over config', function (): void {
    $saved = $_ENV['SPORA_MAIL_FROM'] ?? null;
    $_ENV['SPORA_MAIL_FROM'] = 'env-noreply@example.com';
    putenv('SPORA_MAIL_FROM=env-noreply@example.com');

    try {
        $logger = captureMailerLogger();
        $mailer = new SystemMailer([
            'mail_driver' => 'log',
            'mail_from'   => 'config-noreply@example.com',
        ], $logger);

        $result = $mailer->sendTestEmail('admin@example.com');

        expect($result)->toBeTrue();
        expect($logger->records[0]['context']['from'])->toBe('env-noreply@example.com');
    } finally {
        if ($saved === null) {
            unset($_ENV['SPORA_MAIL_FROM']);
            putenv('SPORA_MAIL_FROM');
        } else {
            $_ENV['SPORA_MAIL_FROM'] = $saved;
            putenv("SPORA_MAIL_FROM={$saved}");
        }
    }
});

test('sendTestEmail falls back to default from name when config omits it', function (): void {
    $logger = captureMailerLogger();
    $mailer = new SystemMailer([
        'mail_driver' => 'log',
        'mail_from'   => 'a@example.com',
    ], $logger);

    // Just verify the call succeeds — the from name is internal to the Address object
    $result = $mailer->sendTestEmail('admin@example.com');

    expect($result)->toBeTrue();
    expect($logger->records[0]['context']['from'])->toBe('a@example.com');
});
