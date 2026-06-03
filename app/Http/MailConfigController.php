<?php

declare(strict_types=1);

namespace Spora\Http;

use JsonException;
use Spora\Auth\AuthService;
use Spora\Core\DotenvWriter;
use Spora\Services\SystemMailer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Manages mail server configuration: view, update, and send test emails.
 */
final class MailConfigController
{
    private const ENV_KEYS = [
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
        private readonly array $config = [],
    ) {}

    public function index(Request $request): JsonResponse
    {
        $mailConfig = [
            'driver'       => $this->config['mail_driver']     ?? 'php_mail',
            'host'         => $this->config['mail_host']       ?? null,
            'port'         => $this->config['mail_port']       ?? 587,
            'username'     => $this->config['mail_username']   ?? null,
            'password'     => $this->config['mail_password']   ?? null,
            'from_address' => $this->config['mail_from']       ?? null,
            'from_name'    => $this->config['mail_from_name']  ?? 'Spora',
            'encryption'   => $this->config['mail_encryption'] ?? 'tls',
        ];

        // Mask password
        if ($mailConfig['password'] !== null && $mailConfig['password'] !== '') {
            $mailConfig['password'] = '***';
        }

        return new JsonResponse([
            'data' => ['mail_config' => $mailConfig],
        ], Response::HTTP_OK);
    }

    public function update(Request $request): JsonResponse
    {
        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }

        $envValues = [];

        foreach (self::ENV_KEYS as $field => $envKey) {
            if (isset($body[$field])) {
                $value = (string) $body[$field];
                // Don't write masked password back
                if ($field === 'password' && $value === '***') {
                    continue;
                }
                $envValues[$envKey] = $value;
            }
        }

        if (isset($body['port'])) {
            $envValues['SPORA_MAIL_PORT'] = (string) (int) $body['port'];
        }

        if (!empty($envValues)) {
            DotenvWriter::sets($envValues);
        }

        // Return updated config with password masked
        $updatedConfig = [
            'driver'       => $body['driver']       ?? $this->config['mail_driver']     ?? 'php_mail',
            'host'         => $body['host']         ?? $this->config['mail_host']       ?? null,
            'port'         => isset($body['port']) ? (int) $body['port'] : ($this->config['mail_port']       ?? 587),
            'username'     => $body['username']     ?? $this->config['mail_username']   ?? null,
            'password'     => '***',
            'from_address' => $body['from_address'] ?? $this->config['mail_from']       ?? null,
            'from_name'    => $body['from_name']    ?? $this->config['mail_from_name']  ?? 'Spora',
            'encryption'   => $body['encryption']   ?? $this->config['mail_encryption'] ?? 'tls',
        ];

        return new JsonResponse([
            'data' => ['mail_config' => $updatedConfig],
        ], Response::HTTP_OK);
    }

    public function test(Request $request): JsonResponse
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
