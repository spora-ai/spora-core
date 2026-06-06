<?php

declare(strict_types=1);

namespace Spora\Tools\CalDav;

use Closure;
use Psr\Log\LoggerInterface;
use Spora\Tools\ValueObjects\ToolResult;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

/**
 * Centralised HTTP dispatch + status-code mapping for CalDavCalendarTool.
 * Owns the error-handling closure that maps an HTTP error to a ToolResult
 * so each public method can focus on its own success semantics.
 */
final class CalDavResponseMapper
{
    private const ERR_EVENT_NOT_FOUND = 'Event not found.';
    private const LOG_CALDAV_EXCEPTION = 'CalDAV Exception';

    public function __construct(
        private readonly CalDavClient $client,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * @param array<string, mixed> $requestOptions
     * @param Closure(ResponseInterface): ToolResult $onSuccess
     * @param Closure(ResponseInterface, int): ToolResult|null $onHttpError
     *        Optional override for the default "CalDAV server returned HTTP N" message.
     */
    public function runHttp(
        string $method,
        string $url,
        array $requestOptions,
        Closure $onSuccess,
        string $errorPrefix,
        ?Closure $onHttpError = null,
    ): ToolResult {
        try {
            $response = $this->client->request($method, $url, $requestOptions);
        } catch (Throwable $e) {
            $this->logger?->error(self::LOG_CALDAV_EXCEPTION, ['exception' => $e, 'method' => $method, 'url' => $url]);
            return new ToolResult(false, "{$errorPrefix}: {$e->getMessage()}");
        }
        return $this->handleResponse($response, $method, $url, $onSuccess, $onHttpError);
    }

    /**
     * @param Closure(ResponseInterface): ToolResult $onSuccess
     * @param Closure(ResponseInterface, int): ToolResult|null $onHttpError
     */
    private function handleResponse(
        ResponseInterface $response,
        string $method,
        string $url,
        Closure $onSuccess,
        ?Closure $onHttpError,
    ): ToolResult {
        $statusCode = $response->getStatusCode();
        if ($statusCode >= 400) {
            $errorMsg = $this->safeResponseContent($response);
            $this->client->logHttpError($method, $url, $statusCode, $errorMsg, $response->getHeaders(false));
            if ($onHttpError !== null) {
                return $onHttpError($response, $statusCode);
            }
            return new ToolResult(false, "CalDAV server returned HTTP {$statusCode}");
        }
        return $onSuccess($response);
    }

    public function handleGetResponse(ResponseInterface $response, string $eventUri, Closure $parseEvent): ToolResult
    {
        $etag = $response->getHeaders(false)['etag'][0] ?? null;
        return $parseEvent($response->getContent(), $eventUri, $etag);
    }

    public function handleGetError(int $statusCode): ToolResult
    {
        if ($statusCode === 404) {
            return new ToolResult(false, self::ERR_EVENT_NOT_FOUND);
        }
        return new ToolResult(false, "CalDAV server returned HTTP {$statusCode}");
    }

    public function handleCreateResponse(ResponseInterface $response, string $eventUri, string $summary): ToolResult
    {
        $statusCode = $response->getStatusCode();
        if ($statusCode === 201) {
            $etag = $response->getHeaders(false)['etag'][0] ?? null;
            return new ToolResult(true, "Event '{$summary}' created successfully.", [
                'event_uri' => $eventUri,
                'etag' => $etag,
            ]);
        }
        return new ToolResult(true, "Event '{$summary}' created successfully.");
    }

    public function handleCreateError(int $statusCode): ToolResult
    {
        if ($statusCode === 415) {
            return new ToolResult(false, 'Calendar server does not support event creation (unsupported media type).');
        }
        return new ToolResult(false, "CalDAV server returned HTTP {$statusCode}");
    }

    public function handlePutResponse(ResponseInterface $response, string $eventUri, string $summary): ToolResult
    {
        $newEtag = $response->getHeaders(false)['etag'][0] ?? null;
        return new ToolResult(true, "Event '{$summary}' updated successfully.", [
            'event_uri' => $eventUri,
            'etag' => $newEtag,
        ]);
    }

    public function handlePutError(int $statusCode): ToolResult
    {
        if ($statusCode === 412) {
            return new ToolResult(false, 'Precondition Failed: The event has been modified since you fetched it. Please fetch the latest version and try again.');
        }
        if ($statusCode === 404) {
            return new ToolResult(false, self::ERR_EVENT_NOT_FOUND);
        }
        return new ToolResult(false, "CalDAV server returned HTTP {$statusCode}");
    }

    public function handleDeleteResponse(): ToolResult
    {
        return new ToolResult(true, 'Event deleted successfully.');
    }

    /**
     * Read the body of a 4xx/5xx response for logging. Some mocked responses
     * don't define getContent, so swallow the failure and log an empty body.
     */
    private function safeResponseContent(ResponseInterface $response): string
    {
        try {
            return $response->getContent(false);
        } catch (Throwable) {
            return '';
        }
    }
}
