<?php

declare(strict_types=1);

namespace Spora\Http\AgentApi;

use InvalidArgumentException;
use OpenApi\Attributes as OA;
use Spora\Http\JsonControllerHelpers;
use Spora\Services\AgentApi\AgentApiChatProjection;
use Spora\Services\AgentApi\AgentApiContext;
use Spora\Services\AgentApi\AgentApiScope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Letting an external caller unblock a chat it started.
 *
 * An agent can park on `PENDING_APPROVAL` (a tool needs sign-off) or
 * `AWAITING_INPUT` (it asked a question), and from the outside both are dead
 * ends. These endpoints are what makes the Agent API a closed loop rather than
 * a fire-and-forget integration — but they are also the widest thing a key
 * can do, which is why they act only on chats the key's own agent owns.
 *
 * Split from {@see AgentApiChatController} to stay clear of the method-count
 * ceiling and to keep the read surface reviewable on its own.
 */
#[OA\Tag(name: 'Agent-api', description: 'Per-agent API for external tools')]
final class AgentApiChatActionController
{
    use JsonControllerHelpers;

    public function __construct(
        private readonly AgentApiScope $scope,
        private readonly AgentApiChatProjection $projection,
    ) {}

    #[OA\Post(path: '/api/v1/agent-api/chats/{chatId}/approve', summary: 'Approve pending tool calls', tags: ['Agent-api'])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['decisions'],
        properties: [new OA\Property(property: 'decisions', type: 'array', items: new OA\Items(type: 'object'))],
    ))]
    #[OA\Response(response: 202, description: 'Approval recorded; the chat is queued again.')]
    #[OA\Response(response: 404, description: AgentApiScope::ERR_NO_SUCH_CHAT)]
    #[OA\Response(response: 409, description: 'The chat is not pending approval.')]
    public function approve(Request $request, int $chatId): JsonResponse
    {

        $body = $this->jsonBody($request);

        if ($body instanceof JsonResponse) {
            return $body;
        }

        $decisions = $body['decisions'] ?? null;

        if (!is_array($decisions) || $decisions === []) {
            return $this->unprocessable('VALIDATION_ERROR', 'decisions must be a non-empty array.');
        }

        $context = AgentApiContext::fromRequest($request);

        return $this->act(
            $chatId,
            $context,
            fn(): int => $this->scope->approve($chatId, $context->agentId(), $context->ownerUserId, $decisions)->id,
        );
    }

    #[OA\Post(path: '/api/v1/agent-api/chats/{chatId}/reject', summary: 'Reject pending tool calls', tags: ['Agent-api'])]
    #[OA\RequestBody(content: new OA\JsonContent(
        properties: [new OA\Property(property: 'reason', type: 'string')],
    ))]
    #[OA\Response(response: 202, description: 'Rejection recorded.')]
    #[OA\Response(response: 404, description: AgentApiScope::ERR_NO_SUCH_CHAT)]
    #[OA\Response(response: 409, description: 'The chat is not pending approval.')]
    public function reject(Request $request, int $chatId): JsonResponse
    {

        $body = $this->jsonBody($request);

        if ($body instanceof JsonResponse) {
            return $body;
        }

        $reason = $body['reason'] ?? null;
        $context = AgentApiContext::fromRequest($request);

        return $this->act(
            $chatId,
            $context,
            fn(): int => $this->scope->reject(
                $chatId,
                $context->agentId(),
                $context->ownerUserId,
                is_string($reason) && trim($reason) !== '' ? trim($reason) : 'Rejected via the Agent API.',
            )->id,
        );
    }

    #[OA\Post(path: '/api/v1/agent-api/chats/{chatId}/answer', summary: 'Answer the agent\'s question', tags: ['Agent-api'])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['tool_call_id', 'content'],
        properties: [
            new OA\Property(property: 'tool_call_id', type: 'string'),
            new OA\Property(property: 'content', type: 'string'),
        ],
    ))]
    #[OA\Response(response: 202, description: 'Answer recorded; the chat is queued again.')]
    #[OA\Response(response: 404, description: AgentApiScope::ERR_NO_SUCH_CHAT)]
    #[OA\Response(response: 409, description: 'The chat is not awaiting input.')]
    public function answer(Request $request, int $chatId): JsonResponse
    {

        $body = $this->jsonBody($request);

        if ($body instanceof JsonResponse) {
            return $body;
        }

        $toolCallId = $body['tool_call_id'] ?? null;
        $content = $body['content'] ?? null;

        if (!is_string($toolCallId) || $toolCallId === '' || !is_string($content) || trim($content) === '') {
            return $this->unprocessable('VALIDATION_ERROR', 'tool_call_id and content are required.');
        }

        $context = AgentApiContext::fromRequest($request);

        return $this->act(
            $chatId,
            $context,
            fn(): int => $this->scope->answer(
                $chatId,
                $context->agentId(),
                $context->ownerUserId,
                $toolCallId,
                trim($content),
            )->id,
        );
    }

    /**
     * Run one state transition and render the chat that came out of it.
     *
     * A 404 and a 409 are told apart by whether the chat is on this agent at
     * all, not by the exception's message.
     */
    private function act(int $chatId, AgentApiContext $context, callable $transition): JsonResponse
    {
        try {
            $taskId = $transition();
        } catch (InvalidArgumentException $e) {
            $missing = $this->scope->chatMissing($chatId, $context->agentId());

            return $missing
                ? $this->notFound('NOT_FOUND', AgentApiScope::ERR_NOT_FOUND)
                : $this->error('INVALID_STATE', $e->getMessage(), Response::HTTP_CONFLICT);
        }

        $task = $this->scope->requireTask($taskId, $context->agentId());

        return new JsonResponse(['data' => ['chat' => $this->projection->status($task)]], Response::HTTP_ACCEPTED);
    }

}
