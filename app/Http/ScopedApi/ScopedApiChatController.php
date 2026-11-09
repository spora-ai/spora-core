<?php

declare(strict_types=1);

namespace Spora\Http\ScopedApi;

use InvalidArgumentException;
use OpenApi\Attributes as OA;
use Spora\Core\Config;
use Spora\Http\Exceptions\EffortValidationException;
use Spora\Http\JsonControllerHelpers;
use Spora\Http\TaskEffortRequestValidator;
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

    private const MSG_AGENT_NOT_FOUND = 'Agent not found.';

    private const MSG_NO_OWNER = 'The agent has no resolvable owner.';

    public function __construct(
        private readonly ApiKeyChatAccess $access,
        private readonly ApiKeyChatProjection $projection,
        private readonly ApiKeyFileIngest $ingest,
        private readonly ApiKeyCallbackRecorder $callbacks,
        private readonly ApiKeyReach $reach,
        private readonly SsrfGuard $ssrf,
        private readonly Config $config,
        private readonly ScopedApiChatBody $body,
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
    #[OA\Response(response: 404, description: 'No such agent, or not one this key may reach.')]
    #[OA\Response(response: 422, description: 'The request body was not usable.')]
    public function store(Request $request): JsonResponse
    {
        $context = ApiKeyContext::fromRequest($request);
        $body = $this->safeDecodeJson($request);
        $prepared = $body instanceof JsonResponse ? $body : $this->prepareStart($context, $body);

        if ($prepared instanceof JsonResponse) {
            return $prepared;
        }

        $task = $this->start($context, $prepared);

        if ($task === null) {
            return $this->notFound('AGENT_NOT_FOUND', self::MSG_AGENT_NOT_FOUND);
        }

        // Recorded after the chat exists: a failed insert leaves a queued chat
        // with no callback rather than rolling back work the agent has already
        // been handed.
        $this->callbacks->record($context->key, $prepared['agentId'], $task, $prepared['callbackUrl']);

        return new JsonResponse(['data' => ['chat' => $this->projection->status($task)]], Response::HTTP_ACCEPTED);
    }

    #[OA\Get(summary: 'Status and final answer')]
    #[OA\Response(response: 200, description: 'Status, answer, and any block.')]
    #[OA\Response(response: 404, description: ApiKeyChatAccess::ERR_NO_SUCH_CHAT)]
    public function status(Request $request, int $chatId): JsonResponse
    {
        $task = $this->loadChat(ApiKeyContext::fromRequest($request), $chatId);

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
        $context = ApiKeyContext::fromRequest($request);
        $task = $this->loadChat($context, $chatId);

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
        $prepared = $body instanceof JsonResponse ? $body : $this->prepareContinue($context, $chatId, $body);

        if ($prepared instanceof JsonResponse) {
            return $prepared;
        }

        $outcome = $this->continue($context, $chatId, $prepared);

        if ($outcome instanceof JsonResponse) {
            return $outcome;
        }

        return new JsonResponse(['data' => ['chat' => $this->projection->status($outcome)]], Response::HTTP_ACCEPTED);
    }

    /**
     * Everything about a start request that can be refused, plus the values
     * that survive the check.
     *
     * @param array<string, mixed> $body
     * @return JsonResponse|array{agentId: int, text: string, callbackUrl: ?string, maxSteps: ?int, mediaIds: list<string>, effort: ?string}
     */
    private function prepareStart(ApiKeyContext $context, array $body): JsonResponse|array
    {
        $agent = $this->targetAgent($context, $body);

        if ($agent instanceof JsonResponse) {
            return $agent;
        }

        $problem = $this->startProblem($body, $agent);

        if ($problem !== null) {
            return $problem;
        }

        try {
            $effort = TaskEffortRequestValidator::parse($body);
        } catch (EffortValidationException $e) {
            return TaskEffortRequestValidator::errorResponse($e);
        }

        return $this->startValues($body, $agent, $effort);
    }

    /**
     * The agent the chat is for, or the answer saying why there is none.
     *
     * @param array<string, mixed> $body
     * @return JsonResponse|Agent
     */
    private function targetAgent(ApiKeyContext $context, array $body): JsonResponse|Agent
    {
        $agentId = $this->body->int($body, 'agent_id');

        if ($agentId === null) {
            return $this->unprocessable('VALIDATION_ERROR', 'agent_id is required.');
        }

        $agent = $this->reach->targetAgent($context, $agentId);

        return $agent ?? $this->notFound('AGENT_NOT_FOUND', self::MSG_AGENT_NOT_FOUND);
    }

    /**
     * The first thing wrong with a start request, or null.
     *
     * @param array<string, mixed> $body
     */
    private function startProblem(array $body, Agent $agent): ?JsonResponse
    {
        $callbackUrl = $this->body->string($body, 'callback_url');

        $problem = match (true) {
            $this->body->text($body) === null => 'text is required.',
            $callbackUrl !== null && $this->ssrf->rejectReason($callbackUrl) !== null
                => 'callback_url is not an acceptable https URL.',
            isset($body['files']) && !is_array($body['files']) => 'files must be an array.',
            $this->body->tooManyMediaIds($body) => 'media_ids may carry at most ' . ScopedApiChatBody::MAX_MEDIA_IDS . ' ids.',
            $this->reach->ownerUserId($agent) === null => self::MSG_NO_OWNER,
            default => null,
        };

        return $problem === null ? null : $this->unprocessable('VALIDATION_ERROR', $problem);
    }

    /**
     * The values that survive the check.
     *
     * @param array<string, mixed> $body
     * @return array{agentId: int, text: string, callbackUrl: ?string, maxSteps: ?int, mediaIds: list<string>, effort: ?string}
     */
    private function startValues(array $body, Agent $agent, ?string $effort): array
    {
        $mediaIds = $this->body->readableMediaIds($body, (int) $agent->id);
        $ownerUserId = (int) $this->reach->ownerUserId($agent);

        foreach ($this->ingest->ingestInlineFiles($agent, $ownerUserId, array_values($body['files'] ?? [])) as $id) {
            $mediaIds[] = $id;
        }

        return [
            'agentId' => (int) $agent->id,
            'text' => (string) $this->body->text($body),
            'callbackUrl' => $this->body->string($body, 'callback_url'),
            'maxSteps' => $this->boundedInt($this->body->int($body, 'max_steps'), 1, 100),
            'mediaIds' => $mediaIds,
            'effort' => $effort,
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return JsonResponse|array{text: string, additionalSteps: ?int, mediaIds: list<string>, effort: ?string}
     */
    private function prepareContinue(ApiKeyContext $context, int $chatId, array $body): JsonResponse|array
    {
        $text = $this->body->text($body);

        if ($text === null) {
            return $this->unprocessable('VALIDATION_ERROR', 'text is required.');
        }

        $task = $this->loadChat($context, $chatId);

        if ($task instanceof JsonResponse) {
            return $task;
        }

        try {
            $effort = TaskEffortRequestValidator::parse($body);
        } catch (EffortValidationException $e) {
            return TaskEffortRequestValidator::errorResponse($e);
        }

        return [
            'text' => $text,
            'additionalSteps' => $this->boundedInt($this->body->int($body, 'additional_steps'), 1, 100),
            'mediaIds' => $this->body->readableMediaIds($body, (int) $task->agent_id),
            'effort' => $effort,
        ];
    }

    /**
     * @param array{text: string, callbackUrl: ?string, maxSteps: ?int, mediaIds: list<string>, agentId: int, effort: ?string} $prepared
     */
    private function start(ApiKeyContext $context, array $prepared): ?Task
    {
        try {
            return $this->access->startChat(
                $context,
                $prepared['agentId'],
                $prepared['text'],
                $prepared['maxSteps'],
                $prepared['mediaIds'],
                $prepared['effort'],
            );
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param array{text: string, additionalSteps: ?int, mediaIds: list<string>, effort: ?string} $prepared
     * @return Task|JsonResponse
     */
    private function continue(ApiKeyContext $context, int $chatId, array $prepared): Task|JsonResponse
    {
        try {
            return $this->access->continueChat(
                $context,
                $chatId,
                $prepared['text'],
                $prepared['additionalSteps'],
                $prepared['mediaIds'],
                $prepared['effort'],
            );
        } catch (InvalidArgumentException $e) {
            return $this->chatConflict($context, $chatId, $e);
        }
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
    private function loadChat(ApiKeyContext $context, int $chatId): Task|JsonResponse
    {
        try {
            return $this->access->requireTask($context, $chatId);
        } catch (InvalidArgumentException) {
            return $this->notFound('NOT_FOUND', ApiKeyChatAccess::ERR_NOT_FOUND);
        }
    }
}
