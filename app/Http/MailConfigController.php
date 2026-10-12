<?php

declare(strict_types=1);

namespace Spora\Http;

use JsonException;
use Spora\Auth\AuthService;
use Spora\Core\Config;
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
        private readonly Config $config,
    ) {}

    public function index(): JsonResponse
    {
        $mailConfig = $this->currentMailConfig();

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
        $current = $this->currentMailConfig();
        $updatedConfig = [
            'driver'       => $body['driver']       ?? $current['driver'],
            'host'         => $body['host']         ?? $current['host'],
            'port'         => isset($body['port']) ? (int) $body['port'] : $current['port'],
            'username'     => $body['username']     ?? $current['username'],
            'password'     => '***',
            'from_address' => $body['from_address'] ?? $current['from_address'],
            'from_name'    => $body['from_name']    ?? $current['from_name'],
            'encryption'   => $body['encryption']   ?? $current['encryption'],
        ];

        return new JsonResponse([
            'data' => ['mail_config' => $updatedConfig],
        ], Response::HTTP_OK);
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
     * The stored mail settings behind both `index()` and the echo of
     * `update()`, so the two cannot disagree about what "unchanged" means.
     *
     * @return array<string, string|int|null>
     */
    private function currentMailConfig(): array
    {
        return [
            'driver'       => $this->config->getString('SPORA_MAIL_DRIVER'),
            'host'         => $this->config->getString('SPORA_MAIL_HOST'),
            'port'         => $this->config->getInt('SPORA_MAIL_PORT'),
            'username'     => $this->config->getString('SPORA_MAIL_USERNAME'),
            'password'     => $this->config->getString('SPORA_MAIL_PASSWORD'),
            'from_address' => $this->config->getString('SPORA_MAIL_FROM'),
            'from_name'    => $this->config->getString('SPORA_MAIL_FROM_NAME'),
            'encryption'   => $this->config->getString('SPORA_MAIL_ENCRYPTION'),
        ];
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
