<?php

declare(strict_types=1);

namespace Spora\Http;

use JsonException;
use Spora\Auth\AuthService;
use Spora\Core\Config;
use Spora\Services\SystemMailer;
use Spora\Settings\SettingDefinition;
use Spora\Settings\SettingsRegistry;
use Spora\Settings\SettingsRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Reads and writes the `mail` group of the settings store, and sends a test
 * message with what is currently in effect.
 *
 * `update()` used to write `.env` through `DotenvWriter`, which could not work:
 * the writer resolves its path to the package's own `.env` rather than the
 * application's, and `Kernel::loadDotEnv()` reads the file through
 * `Dotenv::createImmutable()`, so a write is never re-read even when it lands
 * in the right file. The controller hid that by answering with the request
 * body instead of re-reading what it had stored, so the operator saw a saved
 * configuration and nothing had changed.
 */
final class MailConfigController
{
    /** What a secret reads as on the wire, and what a client sends back to mean "unchanged". */
    private const MASK = '***';

    /**
     * Request field => settings key. Doubles as the response shape, so the two
     * cannot drift apart.
     */
    private const FIELDS = [
        'driver'       => 'SPORA_MAIL_DRIVER',
        'host'         => 'SPORA_MAIL_HOST',
        'port'         => 'SPORA_MAIL_PORT',
        'username'     => 'SPORA_MAIL_USERNAME',
        'password'     => 'SPORA_MAIL_PASSWORD',
        'from_address' => 'SPORA_MAIL_FROM',
        'from_name'    => 'SPORA_MAIL_FROM_NAME',
        'encryption'   => 'SPORA_MAIL_ENCRYPTION',
    ];

    public function __construct(
        private readonly AuthService $authService,
        private readonly SystemMailer $systemMailer,
        private readonly Config $config,
        private readonly SettingsRepository $settings,
        private readonly SettingsRegistry $registry,
    ) {}

    public function index(): JsonResponse
    {
        return $this->mailConfigResponse();
    }

    public function update(Request $request): JsonResponse
    {
        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }

        $userId   = $this->authService->currentUserId();
        $accepted = [];

        // Validated before anything is written: a request refused half-way
        // through leaves the operator unable to say what was saved.
        foreach (self::FIELDS as $field => $key) {
            if (!array_key_exists($field, $body)) {
                continue;
            }

            $value = $body[$field];

            // The mask is what a read hands out. Writing it back would replace a
            // real password with the three characters the client was shown.
            if ($value === self::MASK) {
                continue;
            }

            $definition = $this->registry->get($key);
            if ($definition === null) {
                continue;
            }

            $rejection = $this->rejection($definition, $value);
            if ($rejection !== null) {
                return $this->error('INVALID_SETTING_VALUE', $rejection, Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // A secret cannot hold an empty string: it is stored encrypted, and
            // `SecurityManager::decrypt()` refuses a blob shorter than a nonce
            // plus a MAC — which is exactly what encrypting '' produces. Writing
            // '' would leave a row that reads as undecryptable forever, and every
            // later read of that key throws. Clearing a secret deletes its row.
            if ($value === '' && $definition->secret) {
                $value = null;
            }

            $accepted[] = [$definition, $value];
        }

        // `put()` coerces through the registry and encrypts a `secret: true`
        // key; a `null` value deletes the row.
        foreach ($accepted as [$definition, $value]) {
            $this->settings->put($definition, $value, $userId);
        }

        return $this->mailConfigResponse();
    }

    public function test(): JsonResponse
    {
        $currentUserEmail = $this->authService->currentUserEmail();

        if ($currentUserEmail === null) {
            return $this->error('NOT_AUTHENTICATED', 'No authenticated user email found.', Response::HTTP_UNAUTHORIZED);
        }

        try {
            $this->systemMailer->sendTestEmail($currentUserEmail);

            return new JsonResponse([
                'data' => ['message' => 'Test email sent successfully.'],
            ], Response::HTTP_OK);
        } catch (Throwable $e) {
            return $this->error(
                'MAIL_SEND_FAILED',
                'Failed to send test email: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }
    }

    /**
     * The single read path for both verbs, so `PUT` cannot answer with one
     * thing and `GET` with another.
     *
     * The overlay is re-applied rather than read field by field from the
     * repository: it is what the next request will resolve against, so a value
     * stored here and a value reported back cannot disagree — the `.env` write
     * only ever "worked" because nothing read it back.
     *
     * A field cleared in this request is the one exception: its row is gone and
     * `Config` still holds the value that row had, so the fallback to
     * `config.php` / `.env` / the default shows up on the next read.
     */
    private function mailConfigResponse(): JsonResponse
    {
        $this->config->applySettingsOverlay($this->settings, $this->registry);

        $password = $this->config->getString('SPORA_MAIL_PASSWORD');

        return new JsonResponse([
            'data' => [
                'mail_config' => [
                    'driver'       => $this->config->getString('SPORA_MAIL_DRIVER'),
                    'host'         => $this->config->getString('SPORA_MAIL_HOST'),
                    'port'         => $this->config->getInt('SPORA_MAIL_PORT'),
                    'username'     => $this->config->getString('SPORA_MAIL_USERNAME'),
                    // The plaintext never leaves the server: `secret: true` means
                    // the store hands the API the mask, not the value.
                    'password'     => ($password === null || $password === '') ? $password : self::MASK,
                    'from_address' => $this->config->getString('SPORA_MAIL_FROM'),
                    'from_name'    => $this->config->getString('SPORA_MAIL_FROM_NAME'),
                    'encryption'   => $this->config->getString('SPORA_MAIL_ENCRYPTION'),
                ],
            ],
        ], Response::HTTP_OK);
    }

    /**
     * The registry's own rules, applied before anything is written.
     *
     * `SettingsRepository::put()` coerces but does not validate, and coercion
     * cannot tell a typo from a value: `(int) 'abc'` is a perfectly good 0, so
     * a mistyped port would be stored and then reported back as saved.
     *
     * @return string|null The reason to refuse the value, or null to store it.
     */
    private function rejection(SettingDefinition $definition, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_scalar($value)) {
            return sprintf('%s must be a single value.', $definition->key);
        }

        return $this->singleValueRejection($definition, (string) $value);
    }

    private function singleValueRejection(SettingDefinition $definition, string $raw): ?string
    {
        if ($definition->validation !== '' && preg_match($definition->validation, $raw) !== 1) {
            return sprintf('%s must match %s.', $definition->key, $definition->validation);
        }

        $allowed = array_column($definition->options, 'value');
        if ($allowed !== [] && !in_array($this->registry->coerce($definition, $raw), $allowed, true)) {
            return sprintf('%s must be one of: %s.', $definition->key, implode(', ', $allowed));
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(Request $request): array
    {
        $content = $request->getContent();
        if ($content === '') {
            return [];
        }

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
