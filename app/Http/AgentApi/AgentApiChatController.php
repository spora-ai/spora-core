<?php

declare(strict_types=1);

namespace Spora\Http\AgentApi;

use InvalidArgumentException;
use OpenApi\Attributes as OA;
use Spora\Core\Config;
use Spora\Http\Exceptions\EffortValidationException;
use Spora\Http\JsonControllerHelpers;
use Spora\Http\TaskEffortRequestValidator;
use Spora\Models\Task;
use Spora\Services\ApiKeys\ApiKeyCallbackRecorder;
use Spora\Services\ApiKeys\ApiKeyChatAccess;
use Spora\Services\ApiKeys\ApiKeyChatProjection;
use Spora\Services\ApiKeys\ApiKeyContext;
use Spora\Services\ApiKeys\ApiKeyFileIngest;
use Spora\Services\ApiKeys\ApiKeyMediaAccess;
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
 * The feature gate is `AgentApiRouteDefinitions`; see there for why a disabled
 * instance answers 404 from the router rather than 403.
 */
#[OA\Tag(name: 'Agent-api', description: 'Per-agent API for external tools')]
final class AgentApiChatController
{
    use JsonControllerHelpers;

    /** Ceiling on `media_ids`, each of which costs a lookup to authorise. */
    private const MAX_MEDIA_IDS = 32;

    public function __construct(
        private readonly ApiKeyChatAccess $scope,
        private readonly ApiKeyChatProjection $projection,
        private readonly ApiKeyFileIngest $ingest,
        private readonly ApiKeyMediaAccess $media,
        private readonly ApiKeyCallbackRecorder $callbacks,
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

        $context = ApiKeyContext::fromRequest($request);
        $tasks = $this->scope->listChats(
            $context,
            $since,
            $this->queryInt($request, 'page'),
            $this->queryInt($request, 'per_page'),
        );
        return new JsonResponse([
            'data' => ['chats' => array_map(fn(Task $t): array => $this->projection->status($t), $tasks)],
        ]);
    }

    #[OA\Post(path: '/api/v1/agent-api/chats', summary: 'Start a chat with this agent', tags: ['Agent-api'])]
    // `required` can name only one field, and `text` is the one the endpoint
    // prefers; `prompt` is a fallback it also reads — see `requiredText()`.
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['text'],
        properties: [
            new OA\Property(property: 'text', type: 'string', description: 'The user message. `prompt` may be sent instead.'),
            new OA\Property(property: 'prompt', type: 'string', description: 'Alias for `text`.'),
            new OA\Property(property: 'media_ids', type: 'array', items: new OA\Items(type: 'string')),
            new OA\Property(property: 'files', type: 'array', items: new OA\Items(type: 'object'), description: 'Inline attachments as data URLs.'),
            new OA\Property(property: 'callback_url', type: 'string', format: 'uri'),
            new OA\Property(property: 'max_steps', type: 'integer'),
            new OA\Property(
                property: 'effort',
                type: 'string',
                nullable: true,
                enum: ['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max', ''],
                description: 'The reasoning effort for this message. Omit it and the configuration default applies; an empty string asks for the model default.',
            ),
        ],
    ))]
    #[OA\Response(response: 202, description: 'The chat was queued.')]
    #[OA\Response(response: 422, description: 'The request body was not usable.')]
    public function store(Request $request): JsonResponse
    {
        $context = ApiKeyContext::fromRequest($request);
        $body = $this->safeDecodeJson($request);

        // The unreadable body and `prepareStart`'s own refusal leave by the
        // same door, so the happy path below reads as the path.
        try {
            $prepared = $body instanceof JsonResponse ? $body : $this->prepareStart($body, $context);
        } catch (EffortValidationException $e) {
            return TaskEffortRequestValidator::errorResponse($e);
        }

        if ($prepared instanceof JsonResponse) {
            return $prepared;
        }

        $task = $this->scope->startChat(
            $context,
            $context->requireAgentId(),
            $prepared['text'],
            $this->boundedInt($this->intField($body, 'max_steps'), 1, 100),
            $prepared['mediaIds'],
            $prepared['effort'],
        );

        // Recorded after the chat exists: a failed insert leaves a queued chat
        // with no callback rather than rolling back work the agent has already
        // been handed.
        $this->callbacks->record($context->key, $context->requireAgentId(), $task, $prepared['callbackUrl']);

        return new JsonResponse(['data' => ['chat' => $this->projection->status($task)]], Response::HTTP_ACCEPTED);
    }

    /**
     * Everything about a start request that can be refused, plus the values
     * that survive the check.
     *
     * Inline files are ingested here rather than in {@see self::store()} so
     * that the refusal paths and the ingestion sit in one place — an
     * attachment is worth as much as the prompt, and a request that fails on
     * its body should not have written anything first.
     *
     * @param array<string, mixed> $body
     * @return JsonResponse|array{text: string, callbackUrl: ?string, mediaIds: list<string>, effort: ?string}
     * @throws EffortValidationException  When the body names an effort nobody knows
     */
    private function prepareStart(array $body, ApiKeyContext $context): JsonResponse|array
    {
        $problem = match (true) {
            $this->requiredText($body) === null => 'text is required.',
            $this->stringField($body, 'callback_url') !== null
                && $this->ssrf->rejectReason((string) $this->stringField($body, 'callback_url')) !== null
                => 'callback_url is not an acceptable https URL.',
            isset($body['files']) && !is_array($body['files']) => 'files must be an array.',
            default => null,
        };

        if ($problem !== null) {
            return $this->unprocessable('VALIDATION_ERROR', $problem);
        }

        $effort = TaskEffortRequestValidator::parse($body);

        $mediaIds = $this->readableMediaIds($body, $context);

        if ($mediaIds === null) {
            return $this->unprocessable('VALIDATION_ERROR', 'media_ids may carry at most ' . self::MAX_MEDIA_IDS . ' ids.');
        }

        $callbackUrl = $this->stringField($body, 'callback_url');

        foreach ($this->ingest->ingestInlineFiles(
            $context->requireAgent(),
            $context->requireOwnerUserId(),
            array_values($body['files'] ?? []),
        ) as $id) {
            $mediaIds[] = $id;
        }

        return [
            'text' => (string) $this->requiredText($body),
            'callbackUrl' => $callbackUrl,
            'mediaIds' => $mediaIds,
            'effort' => $effort,
        ];
    }

    #[OA\Get(path: '/api/v1/agent-api/chats/{chatId}/status', summary: 'Status and final answer', tags: ['Agent-api'])]
    #[OA\Response(response: 200, description: 'Status, answer, and any block.')]
    #[OA\Response(response: 404, description: ApiKeyChatAccess::ERR_NO_SUCH_CHAT)]
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
    #[OA\Response(response: 404, description: ApiKeyChatAccess::ERR_NO_SUCH_CHAT)]
    public function messages(Request $request, int $chatId): JsonResponse
    {
        $context = ApiKeyContext::fromRequest($request);
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
                    $context->scope,
                ),
            ],
        ]);
    }

    #[OA\Get(path: '/api/v1/agent-api/chats/{chatId}/transcript', summary: 'Everything the admin UI shows', tags: ['Agent-api'])]
    #[OA\Parameter(name: 'since_sequence', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Tool calls, thinking, usage totals.')]
    #[OA\Response(response: 404, description: ApiKeyChatAccess::ERR_NO_SUCH_CHAT)]
    public function transcript(Request $request, int $chatId): JsonResponse
    {

        $context = ApiKeyContext::fromRequest($request);

        try {
            $transcript = $this->scope->transcript(
                $context,
                $chatId,
                $this->queryInt($request, 'since_sequence'),
            );
        } catch (InvalidArgumentException) {
            return $this->notFound('NOT_FOUND', ApiKeyChatAccess::ERR_NOT_FOUND);
        }

        return new JsonResponse(['data' => ['chat' => $transcript]]);
    }

    #[OA\Post(path: '/api/v1/agent-api/chats/{chatId}/messages', summary: 'Continue a chat', tags: ['Agent-api'])]
    // The `text`/`prompt` pair is read by `requiredText()`; see the start
    // endpoint for why the spec names `text` as the required one.
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['text'],
        properties: [
            new OA\Property(property: 'text', type: 'string', description: 'The follow-up message. `prompt` may be sent instead.'),
            new OA\Property(property: 'prompt', type: 'string', description: 'Alias for `text`.'),
            new OA\Property(property: 'media_ids', type: 'array', items: new OA\Items(type: 'string')),
            new OA\Property(property: 'additional_steps', type: 'integer'),
            new OA\Property(
                property: 'effort',
                type: 'string',
                nullable: true,
                enum: ['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max', ''],
                description: 'The reasoning effort for this message. Omit it and the configuration default applies; an empty string asks for the model default.',
            ),
        ],
    ))]
    #[OA\Response(response: 202, description: 'The follow-up was queued.')]
    #[OA\Response(response: 404, description: ApiKeyChatAccess::ERR_NO_SUCH_CHAT)]
    #[OA\Response(response: 409, description: 'The chat is not in a state that can be continued.')]
    public function continueChat(Request $request, int $chatId): JsonResponse
    {
        $context = ApiKeyContext::fromRequest($request);
        $body = $this->safeDecodeJson($request);

        try {
            $prepared = $body instanceof JsonResponse ? $body : $this->prepareContinue($body, $context);
        } catch (EffortValidationException $e) {
            return TaskEffortRequestValidator::errorResponse($e);
        }

        if ($prepared instanceof JsonResponse) {
            return $prepared;
        }

        return $this->queueContinue($context, $chatId, $prepared);
    }

    /**
     * @param array{text: string, additionalSteps: ?int, mediaIds: list<string>, effort: ?string} $prepared
     */
    private function queueContinue(ApiKeyContext $context, int $chatId, array $prepared): JsonResponse
    {
        try {
            $task = $this->scope->continueChat(
                $context,
                $chatId,
                $prepared['text'],
                $prepared['additionalSteps'],
                $prepared['mediaIds'],
                $prepared['effort'],
            );
        } catch (InvalidArgumentException $e) {
            return $this->chatConflict($chatId, $context, $e);
        }

        return new JsonResponse(['data' => ['chat' => $this->projection->status($task)]], Response::HTTP_ACCEPTED);
    }

    /**
     * @param array<string, mixed> $body
     * @return JsonResponse|array{text: string, additionalSteps: ?int, mediaIds: list<string>, effort: ?string}
     * @throws EffortValidationException  When the body names an effort nobody knows
     */
    private function prepareContinue(array $body, ApiKeyContext $context): JsonResponse|array
    {
        $text = $this->requiredText($body);

        if ($text === null) {
            return $this->unprocessable('VALIDATION_ERROR', 'text is required.');
        }

        $effort = TaskEffortRequestValidator::parse($body);

        $mediaIds = $this->readableMediaIds($body, $context);

        if ($mediaIds === null) {
            return $this->unprocessable('VALIDATION_ERROR', 'media_ids may carry at most ' . self::MAX_MEDIA_IDS . ' ids.');
        }

        return [
            'text' => $text,
            'additionalSteps' => $this->boundedInt($this->intField($body, 'additional_steps'), 1, 100),
            'mediaIds' => $mediaIds,
            'effort' => $effort,
        ];
    }

    /**
     * A chat that is not this agent's is a 404; one that is, but is in a state
     * it cannot be continued from, is a 409. Both are the same exception, so
     * the distinction is made here rather than at each call site.
     */
    private function chatConflict(int $chatId, ApiKeyContext $context, InvalidArgumentException $e): JsonResponse
    {
        $missing = $this->scope->chatMissing($context, $chatId);

        return $this->error(
            $missing ? 'NOT_FOUND' : 'INVALID_STATE',
            $missing ? ApiKeyChatAccess::ERR_NOT_FOUND : $e->getMessage(),
            $missing ? Response::HTTP_NOT_FOUND : Response::HTTP_CONFLICT,
        );
    }

    /**
     * @return Task|JsonResponse the chat, or the 404 envelope
     */
    private function loadChat(Request $request, int $chatId): Task|JsonResponse
    {
        try {
            return $this->scope->requireTask(ApiKeyContext::fromRequest($request), $chatId);
        } catch (InvalidArgumentException) {
            return $this->notFound('NOT_FOUND', ApiKeyChatAccess::ERR_NOT_FOUND);
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
    private function readableMediaIds(array $body, ApiKeyContext $context): ?array
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
            if (is_string($id) && $id !== '' && $this->media->isMediaReadable($context, $id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

}
