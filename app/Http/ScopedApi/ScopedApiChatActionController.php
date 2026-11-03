<?php

declare(strict_types=1);

namespace Spora\Http\ScopedApi;

use InvalidArgumentException;
use OpenApi\Attributes as OA;
use Spora\Http\JsonControllerHelpers;
use Spora\Services\ApiKeys\ApiKeyChatAccess;
use Spora\Services\ApiKeys\ApiKeyChatProjection;
use Spora\Services\ApiKeys\ApiKeyContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Letting an external caller unblock a chat it started, on the scoped
 * surfaces.
 *
 * An agent can park on `PENDING_APPROVAL` (a tool needs sign-off) or
 * `AWAITING_INPUT` (it asked a question), and from the outside both are dead
 * ends. These endpoints are what makes the API a closed loop rather than a
 * fire-and-forget integration — but they are also the widest thing a key
 * can do, which is why they act only on chats the key's reach contains.
 *
 * One implementation serves both the group surface and the global surface:
 * the OpenAPI tag is derived from the path, so each surface gets its own
 * page.
 */
final class ScopedApiChatActionController
{
    use JsonControllerHelpers;

    public function __construct(
        private readonly ApiKeyChatAccess $access,
        private readonly ApiKeyChatProjection $projection,
    ) {}

    #[OA\Post(summary: 'Approve pending tool calls')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['decisions'],
        properties: [new OA\Property(property: 'decisions', type: 'array', items: new OA\Items(type: 'object'))],
    ))]
    #[OA\Response(response: 202, description: 'Approval recorded; the chat is queued again.')]
    #[OA\Response(response: 404, description: ApiKeyChatAccess::ERR_NO_SUCH_CHAT)]
    #[OA\Response(response: 409, description: 'The chat is not pending approval.')]
    public function approve(Request $request, int $chatId): JsonResponse
    {
        $body = $this->safeDecodeJson($request);

        if ($body instanceof JsonResponse) {
            return $body;
        }

        $decisions = $body['decisions'] ?? null;

        if (!is_array($decisions) || $decisions === []) {
            return $this->unprocessable('VALIDATION_ERROR', 'decisions must be a non-empty array.');
        }

        $context = ApiKeyContext::fromRequest($request);

        return $this->act(
            $chatId,
            $context,
            fn(): int => $this->access->approve($context, $chatId, $decisions)->id,
        );
    }

    #[OA\Post(summary: 'Reject pending tool calls')]
    #[OA\RequestBody(content: new OA\JsonContent(
        properties: [new OA\Property(property: 'reason', type: 'string')],
    ))]
    #[OA\Response(response: 202, description: 'Rejection recorded.')]
    #[OA\Response(response: 404, description: ApiKeyChatAccess::ERR_NO_SUCH_CHAT)]
    #[OA\Response(response: 409, description: 'The chat is not pending approval.')]
    public function reject(Request $request, int $chatId): JsonResponse
    {
        $body = $this->safeDecodeJson($request);

        if ($body instanceof JsonResponse) {
            return $body;
        }

        $reason = $body['reason'] ?? null;
        $context = ApiKeyContext::fromRequest($request);

        return $this->act(
            $chatId,
            $context,
            fn(): int => $this->access->reject(
                $context,
                $chatId,
                is_string($reason) && trim($reason) !== '' ? trim($reason) : 'Rejected via the API.',
            )->id,
        );
    }

    #[OA\Post(summary: 'Answer the agent\'s question')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['tool_call_id', 'content'],
        properties: [
            new OA\Property(property: 'tool_call_id', type: 'string'),
            new OA\Property(property: 'content', type: 'string'),
        ],
    ))]
    #[OA\Response(response: 202, description: 'Answer recorded; the chat is queued again.')]
    #[OA\Response(response: 404, description: ApiKeyChatAccess::ERR_NO_SUCH_CHAT)]
    #[OA\Response(response: 409, description: 'The chat is not awaiting input.')]
    public function answer(Request $request, int $chatId): JsonResponse
    {
        $body = $this->safeDecodeJson($request);

        if ($body instanceof JsonResponse) {
            return $body;
        }

        $toolCallId = $body['tool_call_id'] ?? null;
        $content = $body['content'] ?? null;

        if (!is_string($toolCallId) || $toolCallId === '' || !is_string($content) || trim($content) === '') {
            return $this->unprocessable('VALIDATION_ERROR', 'tool_call_id and content are required.');
        }

        $context = ApiKeyContext::fromRequest($request);

        return $this->act(
            $chatId,
            $context,
            fn(): int => $this->access->answer(
                $context,
                $chatId,
                $toolCallId,
                trim($content),
            )->id,
        );
    }

    /**
     * Run one state transition and render the chat that came out of it.
     *
     * A 404 and a 409 are told apart by whether the chat is in the key's
     * reach at all, not by the exception's message.
     */
    private function act(int $chatId, ApiKeyContext $context, callable $transition): JsonResponse
    {
        try {
            $taskId = $transition();
        } catch (InvalidArgumentException $e) {
            $missing = $this->access->chatMissing($context, $chatId);

            return $missing
                ? $this->notFound('NOT_FOUND', ApiKeyChatAccess::ERR_NOT_FOUND)
                : $this->error('INVALID_STATE', $e->getMessage(), Response::HTTP_CONFLICT);
        }

        $task = $this->access->requireTask($context, $taskId);

        return new JsonResponse(['data' => ['chat' => $this->projection->status($task)]], Response::HTTP_ACCEPTED);
    }
}
