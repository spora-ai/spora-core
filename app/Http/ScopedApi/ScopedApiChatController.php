<?php

declare(strict_types=1);

namespace Spora\Http\ScopedApi;

use InvalidArgumentException;
use OpenApi\Attributes as OA;
use Spora\Core\Config;
use Spora\Http\JsonControllerHelpers;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Services\ApiKeys\ApiKeyCallbackRecorder;
use Spora\Services\ApiKeys\ApiKeyChatAccess;
use Spora\Services\ApiKeys\ApiKeyChatProjection;
use Spora\Services\ApiKeys\ApiKeyContext;
use Spora\Services\ApiKeys\ApiKeyFileIngest;
use Spora\Services\ApiKeys\ApiKeyReach;
use Spora\Services\SsrfGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The chat surface for the scoped API surfaces.
 *
 * One implementation serves both the group surface and the global surface:
 * the scope comes from the key, and the reach decides which chats exist
 * as far as the caller is concerned. The OpenAPI tag is therefore derived
 * from the path rather than declared, so each surface gets its own page.
 *
 * Unlike the agent surface, the agent a chat belongs to is named in the
 * body — a group key reaches many agents, and a global key reaches every
 * group-owned agent on the install.
 */
final class ScopedApiChatController
{
    use JsonControllerHelpers;

    /** Ceiling on `media_ids`, each of which costs a lookup to authorise. */
    private const MAX_MEDIA_IDS = 32;

    public function __construct(
        private readonly ApiKeyChatAccess $access,
        private readonly ApiKeyChatProjection $projection,
        private readonly ApiKeyFileIngest $ingest,
        private readonly ApiKeyCallbackRecorder $callbacks,
        private readonly ApiKeyReach $reach,
        private readonly SsrfGuard $ssrf,
        private readonly Config $config,
    ) {}

    #[OA\Get(summary: 'List the chats this key may reach')]
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
        $tasks = $this->access->listChats(
            $context,
            $since,
            $this->queryInt($request, 'page'),
            $this->queryInt($request, 'per_page'),
        );

        return new JsonResponse([
            'data' => ['chats' => array_map(fn(Task $t): array => $this->projection->status($t), $tasks)],
        ]);
    }

    #[OA\Post(summary: 'Start a chat with one of the agents this key reaches')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['agent_id', 'text'],
        properties: [
            new OA\Property(property: 'agent_id', type: 'integer', description: 'The agent to talk to.'),
            new OA\Property(property: 'text', type: 'string', description: 'The user message.'),
            new OA\Property(property: 'media_ids', type: 'array', items: new OA\Items(type: 'string')),
            new OA\Property(property: 'files', type: 'array', items: new OA\Items(type: 'object'), description: 'Inline attachments as data URLs.'),
            new OA\Property(property: 'callback_url', type: 'string', format: 'uri'),
            new OA\Property(property: 'max_steps', type: 'integer'),
        ],
    ))]
    #[OA\Response(response: 202, description: 'The chat was queued.')]
    #[OA\Response(response: 404, description: 'No such agent, or not one this key may reach.')]
    #[OA\Response(response: 422, description: 'The request body was not usable.')]
    public function store(Request $request): JsonResponse
    {
        $context = ApiKeyContext::fromRequest($request);
        $body = $this->safeDecodeJson($request);

        if ($body instanceof JsonResponse) {
            return $body;
        }

        $agentId = $body['agent_id'] ?? null;

        if (!is_numeric($agentId)) {
            return $this->unprocessable('VALIDATION_ERROR', 'agent_id is required.');
        }

        $agent = $this->reach->targetAgent($context, (int) $agentId);

        if ($agent === null) {
            return $this->notFound('AGENT_NOT_FOUND', 'Agent not found.');
        }

        $prepared = $this->prepareStart($body, $agent);

        if ($prepared instanceof JsonResponse) {
            return $prepared;
        }

        try {
            $task = $this->access->startChat(
                $context,
                (int) $agent->id,
                $prepared['text'],
                $this->boundedInt($this->intField($body, 'max_steps'), 1, 100),
                $prepared['mediaIds'],
            );
        } catch (InvalidArgumentException) {
            return $this->notFound('AGENT_NOT_FOUND', 'Agent not found.');
        }

        // Recorded after the chat exists: a failed insert leaves a queued chat
        // with no callback rather than rolling back work the agent has already
        // been handed.
        $this->callbacks->record($context->key, (int) $agent->id, $task, $prepared['callbackUrl']);

        return new JsonResponse(['data' => ['chat' => $this->projection->status($task)]], Response::HTTP_ACCEPTED);
    }

    #[OA\Get(summary: 'Status and final answer')]
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

    #[OA\Get(summary: 'User messages and answers only')]
    #[OA\Parameter(name: 'since_sequence', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'The conversation, tool calls omitted.')]
    #[OA\Response(response: 404, description: ApiKeyChatAccess::ERR_NO_SUCH_CHAT)]
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

    #[OA\Get(summary: 'Everything the admin UI shows')]
    #[OA\Parameter(name: 'since_sequence', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Tool calls, thinking, usage totals.')]
    #[OA\Response(response: 404, description: ApiKeyChatAccess::ERR_NO_SUCH_CHAT)]
    public function transcript(Request $request, int $chatId): JsonResponse
    {
        $context = ApiKeyContext::fromRequest($request);

        try {
            $transcript = $this->access->transcript(
                $context,
                $chatId,
                $this->queryInt($request, 'since_sequence'),
            );
        } catch (InvalidArgumentException) {
            return $this->notFound('NOT_FOUND', ApiKeyChatAccess::ERR_NOT_FOUND);
        }

        return new JsonResponse(['data' => ['chat' => $transcript]]);
    }

    #[OA\Post(summary: 'Continue a chat')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['text'],
        properties: [
            new OA\Property(property: 'text', type: 'string'),
            new OA\Property(property: 'media_ids', type: 'array', items: new OA\Items(type: 'string')),
            new OA\Property(property: 'additional_steps', type: 'integer'),
        ],
    ))]
    #[OA\Response(response: 202, description: 'The follow-up was queued.')]
    #[OA\Response(response: 404, description: ApiKeyChatAccess::ERR_NO_SUCH_CHAT)]
    #[OA\Response(response: 409, description: 'The chat is not in a state that can be continued.')]
    public function continueChat(Request $request, int $chatId): JsonResponse
    {
        $context = ApiKeyContext::fromRequest($request);
        $body = $this->safeDecodeJson($request);

        if ($body instanceof JsonResponse) {
            return $body;
        }

        $text = $this->requiredText($body);

        if ($text === null) {
            return $this->unprocessable('VALIDATION_ERROR', 'text is required.');
        }

        $task = $this->loadChat($request, $chatId);

        if ($task instanceof JsonResponse) {
            return $task;
        }

        $mediaIds = $this->readableMediaIds($body, (int) $task->agent_id);

        if ($mediaIds === null) {
            return $this->unprocessable('VALIDATION_ERROR', 'media_ids may carry at most ' . self::MAX_MEDIA_IDS . ' ids.');
        }

        try {
            $task = $this->access->continueChat(
                $context,
                $chatId,
                $text,
                $this->boundedInt($this->intField($body, 'additional_steps'), 1, 100),
                $mediaIds,
            );
        } catch (InvalidArgumentException $e) {
            return $this->chatConflict($context, $chatId, $e);
        }

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
     * @return JsonResponse|array{text: string, callbackUrl: ?string, mediaIds: list<string>}
     */
    private function prepareStart(array $body, Agent $agent): JsonResponse|array
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

        $mediaIds = $this->readableMediaIds($body, (int) $agent->id);

        if ($mediaIds === null) {
            return $this->unprocessable('VALIDATION_ERROR', 'media_ids may carry at most ' . self::MAX_MEDIA_IDS . ' ids.');
        }

        $callbackUrl = $this->stringField($body, 'callback_url');
        $ownerUserId = $this->reach->ownerUserId($agent);

        if ($ownerUserId === null) {
            return $this->unprocessable('VALIDATION_ERROR', 'The agent has no resolvable owner.');
        }

        foreach ($this->ingest->ingestInlineFiles($agent, $ownerUserId, array_values($body['files'] ?? [])) as $id) {
            $mediaIds[] = $id;
        }

        return [
            'text' => (string) $this->requiredText($body),
            'callbackUrl' => $callbackUrl,
            'mediaIds' => $mediaIds,
        ];
    }

    /**
     * A chat that is not reachable is a 404; one that is, but is in a state
     * it cannot be continued from, is a 409. Both are the same exception, so
     * the distinction is made here rather than at each call site.
     */
    private function chatConflict(ApiKeyContext $context, int $chatId, InvalidArgumentException $e): JsonResponse
    {
        $missing = $this->access->chatMissing($context, $chatId);

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
            return $this->access->requireTask(ApiKeyContext::fromRequest($request), $chatId);
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
     * Only ids the target agent can read are forwarded, so a caller cannot
     * attach an asset id it observed somewhere else.
     *
     * `null` when the request carries more ids than {@see self::MAX_MEDIA_IDS}:
     * each one is a lookup, so an uncapped list is an uncapped number of
     * queries. Rejected rather than truncated, because a caller that silently
     * lost attachments has no way to tell that from one that sent none.
     *
     * @param array<string, mixed> $body
     * @return list<string>|null
     */
    private function readableMediaIds(array $body, int $agentId): ?array
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
            if (is_string($id) && $id !== '' && $this->access->isMediaReadableForAgent($agentId, $id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
