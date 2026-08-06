<?php

declare(strict_types=1);

namespace Spora\Services;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Spora\Core\RequestOrigin;
use Spora\Mailer\LogTransport;
use Spora\Models\MailTemplate;
use Spora\Models\User;
use Spora\Services\Mail\MailTemplateRenderer;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * System-level transactional mailer powered by Symfony Mailer.
 *
 * Reads mail configuration from container config (merged config.php + .env via SPORA_MAIL_* vars).
 * Uses MailTemplate records for templated emails (verification, password reset, welcome, etc.).
 *
 * The Markdown body stored on each MailTemplate is rendered into HTML + plain-text
 * by the optional {@see MailTemplateRenderer}. Without an injected renderer, the
 * mailer falls back to {@see MailTemplateRenderer::createDefault()} so legacy
 * callers (notably {@code SystemMailerTest}) keep working without DI wiring.
 */
final class SystemMailer implements MailerInterface
{
    public function __construct(
        private readonly array $config,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?MailTemplateRenderer $renderer = null,
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
     * Verify the mailer can be constructed without opening a socket.
     * Callers invoke this before transport side-effects (e.g. delight-im
     * confirmation rows) so a misconfigured mail setup fails with a clean
     * 502 instead of locking the user behind a 24h throttle.
     */
    public function assertCanBuildMailer(): void
    {
        $this->buildMailer();
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

        $renderer = $this->renderer ?? MailTemplateRenderer::createDefault();
        $rendered = $renderer->render(
            $variables,
            $template->subject ?? '',
            $template->body,
            $template->body_html,
        );

        if ($rendered->bodyText === '' && $rendered->bodyHtml === '') {
            throw new InvalidArgumentException("Mail template '{$templateName}' rendered to an empty body.");
        }

        $config = $this->getMailConfig();
        $from   = new Address(
            $config['mail_from'] ?? 'noreply@spora.local',
            $config['mail_from_name'] ?? 'Spora',
        );

        $email = (new Email())
            ->from($from)
            ->to(...$to)
            ->subject($rendered->subject)
            ->text($rendered->bodyText)
            ->html($rendered->bodyHtml);

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
            'site_name'          => $this->getMailConfig()['mail_from_name'] ?? 'Spora',
        ], [$email]);
    }

    /**
     * Send an email-change confirmation email to the NEW address requested by
     * the account holder. Renders the {@code email_change_verification}
     * template so the recipient sees change-specific wording rather than
     * signup-only phrasing ("if you did not create an account").
     *
     * @param string $email The new address the recipient will confirm
     * @param string $verificationUrl Full URL the recipient clicks to confirm
     * @return bool True on success
     */
    public function sendEmailChangeVerificationEmail(string $email, string $verificationUrl): bool
    {
        return $this->sendTemplatedEmail('email_change_verification', [
            'email'             => $email,
            'verification_link' => $verificationUrl,
            'site_name'         => $this->getMailConfig()['mail_from_name'] ?? 'Spora',
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
            'site_name' => $this->getMailConfig()['mail_from_name'] ?? 'Spora',
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
            'user_name'     => $userName,
            'email'         => $email,
            'site_name'     => $this->getMailConfig()['mail_from_name'] ?? 'Spora',
            'dashboard_url' => $this->buildDashboardUrl(),
        ], [$email]);
    }

    /**
     * Resolve the welcome-email dashboard CTA URL from `config.app_url` and
     * `config.app_prefix`. `RequestOrigin::normalizePrefix()` keeps the prefix
     * canonical (`/spora`, never `spora/` or `/`), so a single trailing slash
     * on the suffix is enough to cover both the prefixed and host-root cases
     * without producing `//` or a missing-slash collision.
     */
    private function buildDashboardUrl(): string
    {
        $baseUrl = rtrim((string) ($this->config['app_url'] ?? ''), '/');
        $prefix  = RequestOrigin::normalizePrefix((string) ($this->config['app_prefix'] ?? ''));

        return $baseUrl . $prefix . '/dashboard';
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
            'mail_port'       => $env('SPORA_MAIL_PORT')       ?? $config['mail_port']       ?? 587,
            'mail_username'   => $env('SPORA_MAIL_USERNAME')   ?? $config['mail_username']   ?? null,
            'mail_password'   => $env('SPORA_MAIL_PASSWORD')   ?? $config['mail_password']   ?? null,
            'mail_encryption' => $env('SPORA_MAIL_ENCRYPTION') ?? $config['mail_encryption'] ?? 'tls',
            'mail_from'       => $env('SPORA_MAIL_FROM')       ?? $config['mail_from']       ?? null,
            'mail_from_name'  => $env('SPORA_MAIL_FROM_NAME')  ?? $config['mail_from_name']  ?? 'Spora',
        ];
    }

    /**
     * Port 587 = STARTTLS (smtp://); port 465 = implicit TLS (smtps://).
     * The configured `mail_encryption` selects the scheme so the handshake
     * matches the server's expected protocol.
     *
     * @param array<string, mixed> $config
     * @throws InvalidArgumentException if host is missing or encryption is unsupported
     */
    private function buildSmtpDsn(array $config): string
    {
        $host       = $config['mail_host']       ?? null;
        $port       = (int) ($config['mail_port']       ?? 587);
        $user       = $config['mail_username']   ?? null;
        $pass       = $config['mail_password']   ?? null;
        $encryption = strtolower((string) ($config['mail_encryption'] ?? 'tls'));

        if ($host === null) {
            throw new InvalidArgumentException(
                'SMTP mail driver configured but SPORA_MAIL_HOST / mail_host is not set.',
            );
        }

        if ($encryption === 'none') {
            throw new InvalidArgumentException(
                'SMTP mail encryption "none" is insecure. Use "tls" (STARTTLS) or "ssl" (implicit TLS).',
            );
        }

        if (!in_array($encryption, ['tls', 'ssl'], true)) {
            throw new InvalidArgumentException(
                'SMTP mail encryption must be "tls" (STARTTLS) or "ssl" (implicit TLS).',
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
            '%s://%s%s:%d',
            $encryption === 'ssl' ? 'smtps' : 'smtp',
            $credentials,
            rawurlencode($host),
            $port,
        );
    }
}
