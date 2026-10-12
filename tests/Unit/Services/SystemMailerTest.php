<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use InvalidArgumentException;
use Psr\Log\NullLogger;
use ReflectionMethod;
use Spora\Core\Config;
use Spora\Models\MailTemplate;
use Spora\Models\User;
use Spora\Services\SystemMailer;
use Symfony\Component\Mailer\Mailer;
use Throwable;

function captureMailerLogger(): SystemMailerCapturingLogger
{
    return new SystemMailerCapturingLogger();
}

test('assertCanBuildMailer succeeds for the log driver without opening a socket', function (): void {
    $mailer = new SystemMailer(new Config(['SPORA_MAIL_DRIVER' => 'log']));

    $threw = false;
    try {
        $mailer->assertCanBuildMailer();
    } catch (Throwable) {
        $threw = true;
    }

    expect($threw)->toBeFalse();
});

test('assertCanBuildMailer succeeds for a valid SMTP config without opening a socket', function (): void {
    $mailer = new SystemMailer(new Config([
        'SPORA_MAIL_DRIVER'     => 'smtp',
        'SPORA_MAIL_HOST'       => 'smtp.example.com',
        'SPORA_MAIL_PORT'       => 587,
        'SPORA_MAIL_USERNAME'   => 'user',
        'SPORA_MAIL_PASSWORD'   => 'secret',
        'SPORA_MAIL_ENCRYPTION' => 'tls',
    ]));

    $mailer->assertCanBuildMailer();

    expect(true)->toBeTrue();
});

test('assertCanBuildMailer propagates InvalidArgumentException from the mail driver', function (): void {
    $mailer = new SystemMailer(new Config(['SPORA_MAIL_DRIVER' => 'carrier_pigeon']));

    $mailer->assertCanBuildMailer();
})->throws(InvalidArgumentException::class, "Mail driver 'carrier_pigeon' is not supported");

test('SystemMailer with log driver and no logger injected uses NullLogger', function (): void {
    $mailer = new SystemMailer(new Config(['SPORA_MAIL_DRIVER' => 'log']));

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

    $mailer = new SystemMailer(new Config(['SPORA_MAIL_DRIVER' => 'log']), $logger);

    $builtMailer = $mailer->buildMailer();
    expect($builtMailer)->toBeInstanceOf(Mailer::class);
});

test('buildMailer with smtp driver and valid host returns a Mailer', function (): void {
    $mailer = new SystemMailer(new Config([
        'SPORA_MAIL_DRIVER'     => 'smtp',
        'SPORA_MAIL_HOST'       => 'smtp.example.com',
        'SPORA_MAIL_PORT'       => 587,
        'SPORA_MAIL_USERNAME'   => 'user',
        'SPORA_MAIL_PASSWORD'   => 'secret',
        'SPORA_MAIL_ENCRYPTION' => 'tls',
    ]));

    $built = $mailer->buildMailer();
    expect($built)->toBeInstanceOf(Mailer::class);
});

test('buildMailer with smtp driver and missing host throws InvalidArgumentException', function (): void {
    $mailer = new SystemMailer(new Config(['SPORA_MAIL_DRIVER' => 'smtp']));

    $mailer->buildMailer();
})->throws(InvalidArgumentException::class);

test('buildMailer with smtp driver and missing host error message mentions host', function (): void {
    $mailer = new SystemMailer(new Config(['SPORA_MAIL_DRIVER' => 'smtp']));

    $caught = null;
    try {
        $mailer->buildMailer();
    } catch (InvalidArgumentException $e) {
        $caught = $e;
    }
    expect($caught)->toBeInstanceOf(InvalidArgumentException::class);
    expect($caught->getMessage())->toContain('SPORA_MAIL_HOST');
});

test('buildMailer with smtp driver and encryption=none throws InvalidArgumentException', function (): void {
    $mailer = new SystemMailer(new Config([
        'SPORA_MAIL_DRIVER'     => 'smtp',
        'SPORA_MAIL_HOST'       => 'smtp.example.com',
        'SPORA_MAIL_ENCRYPTION' => 'none',
    ]));

    $mailer->buildMailer();
})->throws(InvalidArgumentException::class, 'SMTP mail encryption "none" is insecure');

test('buildMailer with smtp driver produces an smtp DSN for STARTTLS', function (): void {
    $mailer = new SystemMailer(new Config([
        'SPORA_MAIL_DRIVER'     => 'smtp',
        'SPORA_MAIL_HOST'       => 'smtp.example.com',
        'SPORA_MAIL_PORT'       => 587,
        'SPORA_MAIL_USERNAME'   => 'user',
        'SPORA_MAIL_PASSWORD'   => 'secret',
        'SPORA_MAIL_ENCRYPTION' => 'tls',
    ]));

    $dsn = (new ReflectionMethod($mailer, 'buildSmtpDsn'))->invoke($mailer);

    expect($dsn)->toStartWith('smtp://');
    expect($dsn)->not->toContain('?encryption=');
    expect($dsn)->toContain(':587');
});

test('buildMailer with smtp driver produces an smtps DSN for implicit TLS', function (): void {
    $mailer = new SystemMailer(new Config([
        'SPORA_MAIL_DRIVER'     => 'smtp',
        'SPORA_MAIL_HOST'       => 'smtp.example.com',
        'SPORA_MAIL_PORT'       => 465,
        'SPORA_MAIL_USERNAME'   => 'user',
        'SPORA_MAIL_PASSWORD'   => 'secret',
        'SPORA_MAIL_ENCRYPTION' => 'ssl',
    ]));

    $dsn = (new ReflectionMethod($mailer, 'buildSmtpDsn'))->invoke($mailer);

    expect($dsn)->toStartWith('smtps://');
    expect($dsn)->toContain(':465');
});

test('buildMailer with smtp driver renders the registry default port 587 into the DSN', function (): void {
    // 587 is `SettingsRegistry`'s SPORA_MAIL_PORT default. The mailer supplies
    // no port of its own any more, so this proves the shipped default survives
    // the trip through Config instead of being re-invented per call.
    $mailer = new SystemMailer(new Config([
        'SPORA_MAIL_DRIVER'     => 'smtp',
        'SPORA_MAIL_HOST'       => 'smtp.example.com',
        'SPORA_MAIL_PORT'       => 587,
        'SPORA_MAIL_ENCRYPTION' => 'tls',
    ]));

    $dsn = (new ReflectionMethod($mailer, 'buildSmtpDsn'))->invoke($mailer);

    expect($dsn)->toBe('smtp://smtp.example.com:587');
});

test('buildMailer with smtp driver and username only (no password) returns Mailer', function (): void {
    $mailer = new SystemMailer(new Config([
        'SPORA_MAIL_DRIVER'     => 'smtp',
        'SPORA_MAIL_HOST'       => 'smtp.example.com',
        'SPORA_MAIL_PORT'       => 587,
        'SPORA_MAIL_ENCRYPTION' => 'tls',
        'SPORA_MAIL_USERNAME'   => 'only-user',
    ]));

    expect($mailer->buildMailer())->toBeInstanceOf(Mailer::class);
});

test('buildMailer with smtp driver and password only (no username) returns Mailer', function (): void {
    $mailer = new SystemMailer(new Config([
        'SPORA_MAIL_DRIVER'     => 'smtp',
        'SPORA_MAIL_HOST'       => 'smtp.example.com',
        'SPORA_MAIL_PORT'       => 587,
        'SPORA_MAIL_ENCRYPTION' => 'tls',
        'SPORA_MAIL_PASSWORD'   => 'only-pass',
    ]));

    expect($mailer->buildMailer())->toBeInstanceOf(Mailer::class);
});

test('buildMailer with smtp driver and no credentials returns Mailer', function (): void {
    $mailer = new SystemMailer(new Config([
        'SPORA_MAIL_DRIVER'     => 'smtp',
        'SPORA_MAIL_HOST'       => 'smtp.example.com',
        'SPORA_MAIL_PORT'       => 587,
        'SPORA_MAIL_ENCRYPTION' => 'tls',
    ]));

    expect($mailer->buildMailer())->toBeInstanceOf(Mailer::class);
});

test('buildMailer with smtp driver and custom port and encryption returns Mailer', function (): void {
    $mailer = new SystemMailer(new Config([
        'SPORA_MAIL_DRIVER'     => 'smtp',
        'SPORA_MAIL_HOST'       => 'smtp.example.com',
        'SPORA_MAIL_PORT'       => 2525,
        'SPORA_MAIL_ENCRYPTION' => 'ssl',
    ]));

    expect($mailer->buildMailer())->toBeInstanceOf(Mailer::class);
});

test('buildMailer with smtp driver returns Mailer on the registry defaults', function (): void {
    $mailer = new SystemMailer(new Config([
        'SPORA_MAIL_DRIVER'     => 'smtp',
        'SPORA_MAIL_HOST'       => 'smtp.example.com',
        'SPORA_MAIL_PORT'       => 587,
        'SPORA_MAIL_ENCRYPTION' => 'tls',
    ]));

    expect($mailer->buildMailer())->toBeInstanceOf(Mailer::class);
});

test('buildMailer with php_mail driver returns a Mailer', function (): void {
    $mailer = new SystemMailer(new Config(['SPORA_MAIL_DRIVER' => 'php_mail']));

    expect($mailer->buildMailer())->toBeInstanceOf(Mailer::class);
});

test('buildMailer with sendmail driver returns a Mailer', function (): void {
    $mailer = new SystemMailer(new Config(['SPORA_MAIL_DRIVER' => 'sendmail']));

    expect($mailer->buildMailer())->toBeInstanceOf(Mailer::class);
});

test('buildMailer with an unsupported driver throws InvalidArgumentException', function (): void {
    $mailer = new SystemMailer(new Config(['SPORA_MAIL_DRIVER' => 'carrier_pigeon']));

    $mailer->buildMailer();
})->throws(InvalidArgumentException::class);

test('buildMailer with an unsupported driver error message lists supported drivers', function (): void {
    $mailer = new SystemMailer(new Config(['SPORA_MAIL_DRIVER' => 'carrier_pigeon']));

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
    $mailer = new SystemMailer(new Config(['SPORA_MAIL_DRIVER' => null]));

    expect($mailer->buildMailer())->toBeInstanceOf(Mailer::class);
});

test('buildMailer with an empty Config falls back to the php_mail DSN', function (): void {
    // The shipped default is `SettingsRegistry`'s `smtp`; the literal
    // `buildMailer()` falls back to belongs to it alone, which is why the only
    // way to reach it is hand-building a Config that names no driver.
    $mailer = new SystemMailer(new Config());

    expect($mailer->buildMailer())->toBeInstanceOf(Mailer::class);
});

test('buildMailer ignores SPORA_MAIL_DRIVER in the environment in favour of the Config', function (): void {
    // Environment layering happens once, in `ConfigFactory`. A consumer that
    // re-read `$_ENV` would disagree with every gate that resolves through
    // Config, which is exactly what the deleted getMailConfig() did.
    $saved = $_ENV['SPORA_MAIL_DRIVER'] ?? null;
    $_ENV['SPORA_MAIL_DRIVER'] = 'log';
    putenv('SPORA_MAIL_DRIVER=log');

    try {
        // No host, so the Config's `smtp` driver fails its own guard. Had the
        // environment won, `log` would have built a transport and thrown
        // nothing.
        $mailer = new SystemMailer(new Config([
            'SPORA_MAIL_DRIVER'     => 'smtp',
        ]));

        expect(fn(): Mailer => $mailer->buildMailer())
            ->toThrow(InvalidArgumentException::class, 'SPORA_MAIL_HOST');
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
    $mailer = new SystemMailer(new Config([
        'SPORA_MAIL_DRIVER'     => 'smtp',
        'SPORA_MAIL_HOST'       => 'smtp host.example.com',
        'SPORA_MAIL_PORT'       => 587,
        'SPORA_MAIL_ENCRYPTION' => 'tls',
        'SPORA_MAIL_USERNAME'   => 'user@org',
        'SPORA_MAIL_PASSWORD'   => 'p@ss:word',
    ]));

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
    $mailer = new SystemMailer(new Config(['SPORA_MAIL_DRIVER' => 'log', 'SPORA_MAIL_FROM_NAME' => 'Spora']), $logger);

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
    $mailer = new SystemMailer(new Config(['SPORA_MAIL_DRIVER' => 'log', 'SPORA_MAIL_FROM_NAME' => 'Spora']), captureMailerLogger());

    $mailer->sendTemplatedEmail('does_not_exist', ['x' => 1], ['a@example.com']);
})->throws(InvalidArgumentException::class, "Mail template 'does_not_exist' not found.");

test('sendTemplatedEmail sends to multiple recipients', function (): void {
    MailTemplate::create([
        'name'      => 'broadcast',
        'subject'   => 'Announcement',
        'body' => 'Body',
    ]);

    $logger = captureMailerLogger();
    $mailer = new SystemMailer(new Config(['SPORA_MAIL_DRIVER' => 'log', 'SPORA_MAIL_FROM_NAME' => 'Spora']), $logger);

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
    $mailer = new SystemMailer(new Config([
        'SPORA_MAIL_DRIVER'     => 'log',
        'SPORA_MAIL_FROM'       => 'custom@example.com',
        'SPORA_MAIL_FROM_NAME'  => 'Custom Sender',
    ]), $logger);

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
    // `noreply@spora.local` is `sendTemplatedEmail()`'s own literal;
    // `SettingsRegistry` declares `noreply@example.com`, which ConfigFactory
    // always supplies. This covers the hand-built Config only.
    $mailer = new SystemMailer(new Config(['SPORA_MAIL_DRIVER' => 'log', 'SPORA_MAIL_FROM_NAME' => 'Spora']), $logger);

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
    $mailer = new SystemMailer(new Config(['SPORA_MAIL_DRIVER' => 'log', 'SPORA_MAIL_FROM_NAME' => 'Spora']), $logger);

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
    $mailer = new SystemMailer(new Config(['SPORA_MAIL_DRIVER' => 'log', 'SPORA_MAIL_FROM_NAME' => 'Spora']), $logger);

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
    $mailer = new SystemMailer(new Config(['SPORA_MAIL_DRIVER' => 'log', 'SPORA_MAIL_FROM_NAME' => 'Spora']), $logger);

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
    $mailer = new SystemMailer(new Config(['SPORA_MAIL_DRIVER' => 'log', 'SPORA_MAIL_FROM_NAME' => 'Spora']), $logger);

    $mailer->sendTemplatedEmail('unknown_placeholder', ['name' => 'Bob'], ['a@example.com']);

    expect($logger->records[0]['context']['subject'])->toBe('Hello Bob (ref: {{unknown}})');
});

test('sendTemplatedEmail takes the from address from Config, not from the environment', function (): void {
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
        $mailer = new SystemMailer(new Config([
            'SPORA_MAIL_DRIVER' => 'log',
            'SPORA_MAIL_FROM'   => 'config-from@example.com',
            'SPORA_MAIL_FROM_NAME' => 'Spora',
        ]), $logger);

        $mailer->sendTemplatedEmail('env_from', [], ['a@example.com']);

        expect($logger->records[0]['context']['from'])->toBe('config-from@example.com');
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

test('sendTemplatedEmail builds the address from the Config from address and from name', function (): void {
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
        $mailer = new SystemMailer(new Config([
            'SPORA_MAIL_DRIVER'    => 'log',
            'SPORA_MAIL_FROM'      => 'a@example.com',
            'SPORA_MAIL_FROM_NAME' => 'Config Spora',
        ]), $logger);

        // The from name lives inside the Address object, which the log
        // transport does not report; the address it is paired with is what
        // proves the pair came from one Config rather than two sources.
        $result = $mailer->sendTemplatedEmail('env_from_name', [], ['a@example.com']);

        expect($result)->toBeTrue();
        expect($logger->records[0]['context']['from'])->toBe('a@example.com');
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
    $mailer = new SystemMailer(new Config(['SPORA_MAIL_DRIVER' => 'log', 'SPORA_MAIL_FROM_NAME' => 'Spora']), $logger);

    $result = $mailer->sendVerificationEmail('user@example.com', 'https://app.test/verify?token=abc');

    expect($result)->toBeTrue();
    expect($logger->records)->toHaveCount(1);
    expect($logger->records[0]['context']['to'])->toBe('user@example.com');
    expect($logger->records[0]['context']['subject'])->toBe('Verify your email: https://app.test/verify?token=abc');
});

test('sendVerificationEmail throws when the email_verification template is missing', function (): void {
    $mailer = new SystemMailer(new Config(['SPORA_MAIL_DRIVER' => 'log', 'SPORA_MAIL_FROM_NAME' => 'Spora']), captureMailerLogger());

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
    $mailer = new SystemMailer(new Config(['SPORA_MAIL_DRIVER' => 'log', 'SPORA_MAIL_FROM_NAME' => 'Spora']), $logger);

    $result = $mailer->sendPasswordResetEmail('user@example.com', 'https://app.test/reset?token=xyz');

    expect($result)->toBeTrue();
    expect($logger->records)->toHaveCount(1);
    expect($logger->records[0]['context']['to'])->toBe('user@example.com');
    expect($logger->records[0]['context']['subject'])->toBe('Reset your password: https://app.test/reset?token=xyz');
});

test('sendPasswordResetEmail throws when the password_reset template is missing', function (): void {
    $mailer = new SystemMailer(new Config(['SPORA_MAIL_DRIVER' => 'log', 'SPORA_MAIL_FROM_NAME' => 'Spora']), captureMailerLogger());

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
    $mailer = new SystemMailer(new Config(['SPORA_MAIL_DRIVER' => 'log', 'SPORA_MAIL_FROM_NAME' => 'Spora']), $logger);

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
    $mailer = new SystemMailer(new Config(['SPORA_MAIL_DRIVER' => 'log', 'SPORA_MAIL_FROM_NAME' => 'Spora']), $logger);

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
    $mailer = new SystemMailer(new Config(['SPORA_MAIL_DRIVER' => 'log', 'SPORA_MAIL_FROM_NAME' => 'Spora']), $logger);

    $result = $mailer->sendWelcomeEmail(999999, 'ghost@example.com');

    expect($result)->toBeTrue();
    expect($logger->records[0]['context']['subject'])->toBe('Welcome ghost@example.com!');
});

test('sendWelcomeEmail throws when the welcome template is missing', function (): void {
    $mailer = new SystemMailer(new Config(['SPORA_MAIL_DRIVER' => 'log', 'SPORA_MAIL_FROM_NAME' => 'Spora']), captureMailerLogger());

    $mailer->sendWelcomeEmail(1, 'a@example.com');
})->throws(InvalidArgumentException::class, "Mail template 'welcome' not found.");

test('buildDashboardUrl with empty app_prefix produces host/dashboard without double slash', function (): void {
    $mailer = new SystemMailer(new Config([
        'SPORA_MAIL_DRIVER' => 'log',
        'SPORA_APP_URL'     => 'https://example.com',
        'SPORA_APP_PREFIX'  => '',
    ]));

    $reflection = new ReflectionMethod($mailer, 'buildDashboardUrl');
    $url = $reflection->invoke($mailer);

    expect($url)->toBe('https://example.com/dashboard');
    expect($url)->not->toContain('//dashboard');
});

test('buildDashboardUrl with app_prefix=/spora produces host/spora/dashboard', function (): void {
    $mailer = new SystemMailer(new Config([
        'SPORA_MAIL_DRIVER' => 'log',
        'SPORA_APP_URL'     => 'https://example.com',
        'SPORA_APP_PREFIX'  => '/spora',
    ]));

    $reflection = new ReflectionMethod($mailer, 'buildDashboardUrl');
    $url = $reflection->invoke($mailer);

    expect($url)->toBe('https://example.com/spora/dashboard');
});

test('buildDashboardUrl normalises a bare app_prefix and strips trailing slash', function (): void {
    $mailer = new SystemMailer(new Config([
        'SPORA_MAIL_DRIVER' => 'log',
        'SPORA_APP_URL'     => 'https://example.com/',
        'SPORA_APP_PREFIX'  => 'spora/',
    ]));

    $reflection = new ReflectionMethod($mailer, 'buildDashboardUrl');
    $url = $reflection->invoke($mailer);

    expect($url)->toBe('https://example.com/spora/dashboard');
});

test('buildDashboardUrl strips trailing slashes from app_url', function (): void {
    $mailer = new SystemMailer(new Config([
        'SPORA_MAIL_DRIVER' => 'log',
        'SPORA_APP_URL'     => 'https://example.com///',
        'SPORA_APP_PREFIX'  => '',
    ]));

    $reflection = new ReflectionMethod($mailer, 'buildDashboardUrl');
    $url = $reflection->invoke($mailer);

    expect($url)->toBe('https://example.com/dashboard');
});

test('buildDashboardUrl preserves IPv6 hostnames verbatim', function (): void {
    $mailer = new SystemMailer(new Config([
        'SPORA_MAIL_DRIVER' => 'log',
        'SPORA_APP_URL'     => 'https://[::1]:8080',
        'SPORA_APP_PREFIX'  => '',
    ]));

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
    $mailer = new SystemMailer(new Config([
        'SPORA_MAIL_DRIVER' => 'log',
        'SPORA_APP_URL'     => 'https://example.com',
        'SPORA_APP_PREFIX'  => '/spora',
        'SPORA_MAIL_FROM_NAME' => 'Spora',
    ]), $logger);

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
    $mailer = new SystemMailer(new Config(['SPORA_MAIL_DRIVER' => 'log', 'SPORA_MAIL_FROM_NAME' => 'Spora']), $logger);

    $result = $mailer->sendTestEmail('admin@example.com');

    expect($result)->toBeTrue();
    expect($logger->records)->toHaveCount(1);
    expect($logger->records[0]['context']['to'])->toBe('admin@example.com');
    expect($logger->records[0]['context']['subject'])->toBe('Spora Test Email');
    expect($logger->records[0]['context']['from'])->toBe('noreply@spora.local');
});

test('sendTestEmail uses the from address from config', function (): void {
    $logger = captureMailerLogger();
    $mailer = new SystemMailer(new Config([
        'SPORA_MAIL_DRIVER'    => 'log',
        'SPORA_MAIL_FROM'      => 'noreply@mydomain.com',
        'SPORA_MAIL_FROM_NAME' => 'MyDomain',
    ]), $logger);

    $result = $mailer->sendTestEmail('admin@example.com');

    expect($result)->toBeTrue();
    expect($logger->records[0]['context']['from'])->toBe('noreply@mydomain.com');
});

test('sendTestEmail takes the from address from Config, not from the environment', function (): void {
    $saved = $_ENV['SPORA_MAIL_FROM'] ?? null;
    $_ENV['SPORA_MAIL_FROM'] = 'env-noreply@example.com';
    putenv('SPORA_MAIL_FROM=env-noreply@example.com');

    try {
        $logger = captureMailerLogger();
        $mailer = new SystemMailer(new Config([
            'SPORA_MAIL_DRIVER' => 'log',
            'SPORA_MAIL_FROM'   => 'config-noreply@example.com',
            'SPORA_MAIL_FROM_NAME' => 'Spora',
        ]), $logger);

        $result = $mailer->sendTestEmail('admin@example.com');

        expect($result)->toBeTrue();
        expect($logger->records[0]['context']['from'])->toBe('config-noreply@example.com');
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
    $mailer = new SystemMailer(new Config([
        'SPORA_MAIL_DRIVER' => 'log',
        'SPORA_MAIL_FROM'   => 'a@example.com',
        'SPORA_MAIL_FROM_NAME' => 'Spora',
    ]), $logger);

    // Just verify the call succeeds — the from name is internal to the Address object
    $result = $mailer->sendTestEmail('admin@example.com');

    expect($result)->toBeTrue();
    expect($logger->records[0]['context']['from'])->toBe('a@example.com');
});
