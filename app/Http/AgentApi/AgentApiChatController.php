<?php

declare(strict_types=1);

namespace Spora\Http\AgentApi;

use InvalidArgumentException;
use OpenApi\Attributes as OA;
use Spora\Core\Config;
use Spora\Http\JsonControllerHelpers;
use Spora\Models\Task;
use Spora\Services\AgentApi\AgentApiCallbackRecorder;
use Spora\Services\AgentApi\AgentApiChatProjection;
use Spora\Services\AgentApi\AgentApiContext;
use Spora\Services\AgentApi\AgentApiFileIngest;
use Spora\Services\AgentApi\AgentApiScope;
use Spora\Services\SsrfGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The read and write surface of the Agent API, behind
 * {@see \Spora\Http\Middleware\AgentApiKeyMiddleware}.
 *
 * The agent comes from the key and never from the request body. There is no
 * session fallback: a bearer key is the only credential this surface accepts,
 * so holding a browser cookie cannot widen a key's reach.
 *
 * The feature gate lives in `AgentApiRouteDefinitions`, not here: the
 * routes are simply not registered when `SPORA_AGENT_API` is off or the
 * instance runs client-worker mode, so an instance without the feature
 * answers 404 from the router and the surface stays unenumerable. `SPORA_AGENT_API`
 * is database-backed and `Kernel::handle()` applies the settings overlay
 * before `buildRouter()`, so flipping it needs no restart.
 */
#[OA\Tag(name: 'Agent-api', description: 'Per-agent API for external tools')]
final class AgentApiChatController
{
    use JsonControllerHelpers;

    /** Ceiling on `media_ids`, each of which costs a lookup to authorise. */
    private const MAX_MEDIA_IDS = 32;

    public function __construct(
        private readonly AgentApiScope $scope,
        private readonly AgentApiChatProjection $projection,
        private readonly AgentApiFileIngest $ingest,
        private readonly AgentApiCallbackRecorder $callbacks,
        private readonly SsrfGuard $ssrf,
        private readonly Config $config,
    ) {}

    #[OA\Get(path: '/api/v1/agent-api/chats', summary: "List this agent's chats", tags: ['Agent-api'])]
    #[OA\Parameter(
        name: 'since',
        in: 'query',
        required: false,
        description: 'ISO-8601 timestamp; only chats updated after it.',
        schema: new OA\Schema(type: 'string', format: 'date-time'),
    )]
    #[OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'One page of chats, newest first. 25 per page by default.')]
    #[OA\Response(response: 422, description: '`since` was not a parseable date.')]
    public function index(Request $request): JsonResponse
    {
        $since = $this->queryString($request, 'since');

        if ($since !== null && strtotime($since) === false) {
            // Engines disagree about an unparseable date — SQLite coerces it
            // to something, MySQL errors — so refuse it rather than let the
            // caller discover the difference by running on another host.
            return $this->unprocessable('VALIDATION_ERROR', 'since must be a parseable date.');
        }

        $context = AgentApiContext::fromRequest($request);
        $tasks = $this->scope->listChats(
            $context->agentId(),
            $since,
            $this->queryInt($request, 'page'),
            $this->queryInt($request, 'per_page'),
        );

        return new JsonResponse([
            'data' => ['chats' => array_map(fn(Task $t): array => $this->projection->status($t), $tasks)],
        ]);
    }

    #[OA\Post(path: '/api/v1/agent-api/chats', summary: 'Start a chat with this agent', tags: ['Agent-api'])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['text'],
        properties: [
            new OA\Property(property: 'text', type: 'string', description: 'The user message.'),
            new OA\Property(property: 'media_ids', type: 'array', items: new OA\Items(type: 'string')),
            new OA\Property(property: 'files', type: 'array', items: new OA\Items(type: 'object'), description: 'Inline attachments as data URLs.'),
            new OA\Property(property: 'callback_url', type: 'string', format: 'uri'),
            new OA\Property(property: 'max_steps', type: 'integer'),
        ],
    ))]
    #[OA\Response(response: 202, description: 'The chat was queued.')]
    #[OA\Response(response: 422, description: 'The request body was not usable.')]
    public function store(Request $request): JsonResponse
    {

        $context = AgentApiContext::fromRequest($request);
        $body = $this->jsonBody($request);

        if ($body instanceof JsonResponse) {
            return $body;
        }

        $text = $this->requiredText($body);

        if ($text === null) {
            return $this->unprocessable('VALIDATION_ERROR', 'text is required.');
        }

        $callbackUrl = $this->stringField($body, 'callback_url');

        if ($callbackUrl !== null && $this->ssrf->rejectReason($callbackUrl) !== null) {
            return $this->unprocessable('VALIDATION_ERROR', 'callback_url is not an acceptable https URL.');
        }

        $mediaIds = $this->readableMediaIds($body, $context);

        if ($mediaIds === null) {
            return $this->unprocessable('VALIDATION_ERROR', 'media_ids may carry at most ' . self::MAX_MEDIA_IDS . ' ids.');
        }

        $files = $body['files'] ?? null;

        if ($files !== null && !is_array($files)) {
            return $this->unprocessable('VALIDATION_ERROR', 'files must be an array.');
        }

        if (is_array($files)) {
            foreach ($this->ingest->ingestInlineFiles($context, array_values($files)) as $id) {
                $mediaIds[] = $id;
            }
        }

        $task = $this->scope->startChat(
            $context->ownerUserId,
            $context->agentId(),
            $text,
            $this->boundedInt($this->intField($body, 'max_steps'), 1, 100),
            $mediaIds,
        );

        // Recorded after the chat exists: a failed insert leaves a queued chat
        // with no callback rather than rolling back work the agent has already
        // been handed.
        $this->callbacks->record($context, $task, $callbackUrl);

        return new JsonResponse(['data' => ['chat' => $this->projection->status($task)]], Response::HTTP_ACCEPTED);
    }

    #[OA\Get(path: '/api/v1/agent-api/chats/{chatId}/status', summary: 'Status and final answer', tags: ['Agent-api'])]
    #[OA\Response(response: 200, description: 'Status, answer, and any block.')]
    #[OA\Response(response: 404, description: AgentApiScope::ERR_NO_SUCH_CHAT)]
    public function status(Request $request, int $chatId): JsonResponse
    {

        $task = $this->loadChat($request, $chatId);

        if ($task instanceof JsonResponse) {
            return $task;
        }

        return new JsonResponse(['data' => ['chat' => $this->projection->status($task)]]);
    }

    #[OA\Get(path: '/api/v1/agent-api/chats/{chatId}/messages', summary: 'User messages and answers only', tags: ['Agent-api'])]
    #[OA\Parameter(name: 'since_sequence', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'The conversation, tool calls omitted.')]
    #[OA\Response(response: 404, description: AgentApiScope::ERR_NO_SUCH_CHAT)]
    public function messages(Request $request, int $chatId): JsonResponse
    {

        $task = $this->loadChat($request, $chatId);

        if ($task instanceof JsonResponse) {
            return $task;
        }

        return new JsonResponse([
            'data' => [
                'messages' => $this->projection->messages(
                    (int) $task->id,
                    $this->queryInt($request, 'since_sequence'),
                    $this->config->getString('SPORA_APP_URL'),
                ),
            ],
        ]);
    }

    #[OA\Get(path: '/api/v1/agent-api/chats/{chatId}/transcript', summary: 'Everything the admin UI shows', tags: ['Agent-api'])]
    #[OA\Parameter(name: 'since_sequence', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Tool calls, thinking, usage totals.')]
    #[OA\Response(response: 404, description: AgentApiScope::ERR_NO_SUCH_CHAT)]
    public function transcript(Request $request, int $chatId): JsonResponse
    {

        $context = AgentApiContext::fromRequest($request);

        try {
            $transcript = $this->scope->transcript(
                $chatId,
                $context->agentId(),
                $this->queryInt($request, 'since_sequence'),
            );
        } catch (InvalidArgumentException) {
            return $this->notFound('NOT_FOUND', AgentApiScope::ERR_NOT_FOUND);
        }

        return new JsonResponse(['data' => ['chat' => $transcript]]);
    }

    #[OA\Post(path: '/api/v1/agent-api/chats/{chatId}/messages', summary: 'Continue a chat', tags: ['Agent-api'])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['text'],
        properties: [
            new OA\Property(property: 'text', type: 'string'),
            new OA\Property(property: 'media_ids', type: 'array', items: new OA\Items(type: 'string')),
            new OA\Property(property: 'additional_steps', type: 'integer'),
        ],
    ))]
    #[OA\Response(response: 202, description: 'The follow-up was queued.')]
    #[OA\Response(response: 404, description: AgentApiScope::ERR_NO_SUCH_CHAT)]
    #[OA\Response(response: 409, description: 'The chat is not in a state that can be continued.')]
    public function continueChat(Request $request, int $chatId): JsonResponse
    {

        $context = AgentApiContext::fromRequest($request);
        $body = $this->jsonBody($request);

        if ($body instanceof JsonResponse) {
            return $body;
        }

        $text = $this->requiredText($body);

        if ($text === null) {
            return $this->unprocessable('VALIDATION_ERROR', 'text is required.');
        }

        $mediaIds = $this->readableMediaIds($body, $context);

        if ($mediaIds === null) {
            return $this->unprocessable('VALIDATION_ERROR', 'media_ids may carry at most ' . self::MAX_MEDIA_IDS . ' ids.');
        }

        try {
            $task = $this->scope->continueChat(
                $chatId,
                $context->agentId(),
                $context->ownerUserId,
                $text,
                $this->boundedInt($this->intField($body, 'additional_steps'), 1, 100),
                $mediaIds,
            );
        } catch (InvalidArgumentException $e) {
            $status = $this->scope->chatMissing($chatId, $context->agentId())
                ? Response::HTTP_NOT_FOUND
                : Response::HTTP_CONFLICT;

            return $this->error(
                $status === 404 ? 'NOT_FOUND' : 'INVALID_STATE',
                $status === 404 ? AgentApiScope::ERR_NOT_FOUND : $e->getMessage(),
                $status,
            );
        }

        return new JsonResponse(['data' => ['chat' => $this->projection->status($task)]], Response::HTTP_ACCEPTED);
    }

    /**
     * @return Task|JsonResponse the chat, or the 404 envelope
     */
    private function loadChat(Request $request, int $chatId): Task|JsonResponse
    {
        try {
            return $this->scope->requireTask($chatId, AgentApiContext::fromRequest($request)->agentId());
        } catch (InvalidArgumentException) {
            return $this->notFound('NOT_FOUND', AgentApiScope::ERR_NOT_FOUND);
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function requiredText(array $body): ?string
    {
        $text = trim($this->stringField($body, 'text') ?? $this->stringField($body, 'prompt') ?? '');

        return $text === '' ? null : $text;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function stringField(array $body, string $name): ?string
    {
        $value = $body[$name] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function intField(array $body, string $name): ?int
    {
        $value = $body[$name] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Only ids the key's own agent can read are forwarded, so a caller
     * cannot attach an asset id it observed somewhere else.
     *
     * `null` when the request carries more ids than {@see self::MAX_MEDIA_IDS}:
     * each one is a lookup, so an uncapped list is an uncapped number of
     * queries. Rejected rather than truncated, because a caller that silently
     * lost attachments has no way to tell that from one that sent none.
     *
     * @param array<string, mixed> $body
     * @return list<string>|null
     */
    private function readableMediaIds(array $body, AgentApiContext $context): ?array
    {
        $raw = $body['media_ids'] ?? null;

        if (!is_array($raw)) {
            return [];
        }

        if (count($raw) > self::MAX_MEDIA_IDS) {
            return null;
        }

        $ids = [];

        foreach ($raw as $id) {
            if (is_string($id) && $id !== '' && $this->scope->isMediaReadable($id, $context->agentId())) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

}
