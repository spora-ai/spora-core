<?php

declare(strict_types=1);

namespace Spora\Services;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Spora\Mailer\LogTransport;
use Spora\Models\MailTemplate;
use Spora\Models\User;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * System-level transactional mailer powered by Symfony Mailer.
 *
 * Reads mail configuration from container config (merged config.php + .env via SPORA_MAIL_* vars).
 * Uses MailTemplate records for templated emails (verification, password reset, welcome, etc.).
 */
final class SystemMailer implements MailerInterface
{
    public function __construct(
        private readonly array $config,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Build and return a Symfony Mailer instance configured from container config.
     *
     * @throws InvalidArgumentException if mail configuration is incomplete, driver is unsupported,
     *                                  or SMTP encryption is set to an insecure value
     */
    public function buildMailer(): Mailer
    {
        $config = $this->getMailConfig();

        $driver = $config['mail_driver'] ?? null;

        $dsn = match ($driver) {
            'smtp' => $this->buildSmtpDsn($config),
            'php_mail', 'sendmail' => 'sendmail://default',
            'log' => 'log://default',
            default => throw new InvalidArgumentException(
                "Mail driver '{$driver}' is not supported. Use 'smtp', 'php_mail', 'sendmail', or 'log'.",
            ),
        };

        if ($driver === 'log') {
            return new Mailer(new LogTransport(null, $this->logger ?? new \Psr\Log\NullLogger()));
        }

        return new Mailer(Transport::fromDsn($dsn));
    }

    /**
     * Send a templated email by name.
     *
     * Loads the MailTemplate by name, renders subject/body with the provided variables,
     * builds the email, and dispatches it via Symfony Mailer.
     *
     * @param string $templateName The MailTemplate.name to load
     * @param array<string, mixed> $variables Key-value pairs for template rendering
     * @param array<string> $to Array of recipient email addresses
     * @return bool True if the email was sent successfully
     * @throws InvalidArgumentException if template is not found or mail config is missing
     */
    public function sendTemplatedEmail(string $templateName, array $variables, array $to): bool
    {
        $template = MailTemplate::where('name', $templateName)->first();

        if ($template === null) {
            throw new InvalidArgumentException("Mail template '{$templateName}' not found.");
        }

        $rendered = $template->render($variables);
        $config   = $this->getMailConfig();
        $from     = new Address(
            $config['mail_from'] ?? 'noreply@spora.local',
            $config['mail_from_name'] ?? 'Spora',
        );

        $email = (new Email())
            ->from($from)
            ->to(...$to)
            ->subject($rendered['subject'] ?? '')
            ->text($rendered['body_text'] ?? '')
            ->html($rendered['body_html'] ?? $rendered['body_text'] ?? '');

        $this->buildMailer()->send($email);

        return true;
    }

    /**
     * Send an account verification email.
     *
     * @param string $email Recipient email address
     * @param string $verificationUrl Full URL the user clicks to verify their account
     * @return bool True on success
     */
    public function sendVerificationEmail(string $email, string $verificationUrl): bool
    {
        return $this->sendTemplatedEmail('email_verification', [
            'email'              => $email,
            'verification_link'  => $verificationUrl,
        ], [$email]);
    }

    /**
     * Send a password reset email.
     *
     * @param string $email Recipient email address
     * @param string $resetUrl Full URL the user clicks to reset their password
     * @return bool True on success
     */
    public function sendPasswordResetEmail(string $email, string $resetUrl): bool
    {
        return $this->sendTemplatedEmail('password_reset', [
            'email'     => $email,
            'reset_link' => $resetUrl,
        ], [$email]);
    }

    /**
     * Send a welcome email to a newly registered user.
     *
     * @param int $userId The new user's ID (reserved for future personalization)
     * @param string $email Recipient email address
     * @return bool True on success
     */
    public function sendWelcomeEmail(int $userId, string $email): bool
    {
        $user = User::find($userId);
        $userName = $user !== null ? ($user->name ?? $email) : $email;

        return $this->sendTemplatedEmail('welcome', [
            'user_name' => $userName,
            'email'     => $email,
        ], [$email]);
    }

    /**
     * Send a simple test email to verify mail configuration.
     *
     * @param string $to Recipient email address
     * @return bool True on success
     */
    public function sendTestEmail(string $to): bool
    {
        $config = $this->getMailConfig();
        $from   = new Address(
            $config['mail_from'] ?? 'noreply@spora.local',
            $config['mail_from_name'] ?? 'Spora',
        );

        $email = (new Email())
            ->from($from)
            ->to($to)
            ->subject('Spora Test Email')
            ->text('This is a test email sent from Spora. If you received this, your mail configuration is working.');

        $this->buildMailer()->send($email);

        return true;
    }

    /**
     * Read mail configuration from the container config.
     *
     * @throws InvalidArgumentException if required mail config is not set (driver, host, from)
     * @return array<string, mixed>
     */
    private function getMailConfig(): array
    {
        $config = $this->config;

        // Layer mail config from SPORA_MAIL_* env vars
        $env = static fn(string $k): ?string => $_ENV[$k] ?? (getenv($k) ?: null);

        return [
            'mail_driver'     => $env('SPORA_MAIL_DRIVER')     ?? $config['mail_driver']     ?? 'php_mail',
            'mail_host'       => $env('SPORA_MAIL_HOST')       ?? $config['mail_host']       ?? null,
            'mail_port'       => $env('SPORA_MAIL_PORT')       ?? $config['mail_port']       ?? 465,
            'mail_username'   => $env('SPORA_MAIL_USERNAME')   ?? $config['mail_username']   ?? null,
            'mail_password'   => $env('SPORA_MAIL_PASSWORD')   ?? $config['mail_password']   ?? null,
            'mail_encryption' => $env('SPORA_MAIL_ENCRYPTION') ?? $config['mail_encryption'] ?? 'tls',
            'mail_from'       => $env('SPORA_MAIL_FROM')       ?? $config['mail_from']       ?? null,
            'mail_from_name'  => $env('SPORA_MAIL_FROM_NAME')  ?? $config['mail_from_name']  ?? 'Spora',
        ];
    }

    /**
     * Build a Symfony Mailer SMTPS DSN from configuration.
     *
     * Uses the `smtps://` scheme so the connection is implicitly TLS-encrypted
     * (CWE-319). `mail_encryption` is accepted for backward compatibility but
     * is no longer appended to the DSN — the encryption is implicit in SMTPS.
     * A value of `none` is rejected as insecure.
     *
     * @param array<string, mixed> $config
     * @return string DSN in the form smtps://user:pass@host:port
     *
     * @throws InvalidArgumentException if host is missing or encryption is `none`
     */
    private function buildSmtpDsn(array $config): string
    {
        $host       = $config['mail_host']       ?? null;
        $port       = (int) ($config['mail_port']       ?? 465);
        $user       = $config['mail_username']   ?? null;
        $pass       = $config['mail_password']   ?? null;
        $encryption = $config['mail_encryption'] ?? 'tls';

        if ($host === null) {
            throw new InvalidArgumentException(
                'SMTP mail driver configured but SPORA_MAIL_HOST / mail_host is not set.',
            );
        }

        if ($encryption === 'none') {
            throw new InvalidArgumentException(
                'SMTP mail encryption "none" is insecure. Use SMTPS (default) or set SPORA_MAIL_ENCRYPTION to "tls" / "ssl".',
            );
        }

        $userEncoded = $user !== null ? rawurlencode((string) $user) : '';
        $passEncoded = $pass !== null ? rawurlencode((string) $pass) : '';

        if ($userEncoded !== '' && $passEncoded !== '') {
            $credentials = "{$userEncoded}:{$passEncoded}@";
        } else {
            $credentials = '';
        }

        return sprintf(
            'smtps://%s%s:%d',
            $credentials,
            rawurlencode($host),
            $port,
        );
    }
}
