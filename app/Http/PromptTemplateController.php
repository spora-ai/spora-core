<?php

declare(strict_types=1);

namespace Spora\Http;

use JsonException;
use RuntimeException;
use Spora\Auth\AuthService;
use Spora\Services\PromptTemplateServiceInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PromptTemplateController
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly PromptTemplateServiceInterface $promptTemplateService,
    ) {}

    /**
     * GET /api/v1/agents/{agentId}/templates
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $agentId = (int) $request->attributes->get('id', 0);

        $templates = $this->promptTemplateService->getTemplatesForAgent($agentId, $userId);

        if ($templates === null) {
            return $this->notFound();
        }

        return new JsonResponse(['data' => ['templates' => $templates]]);
    }

    /**
     * POST /api/v1/agents/{agentId}/templates
     */
    public function store(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $agentId = (int) $request->attributes->get('id', 0);

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return $this->error('VALIDATION_ERROR', 'name is required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $promptTemplate = trim((string) ($body['prompt_template'] ?? ''));
        if ($promptTemplate === '') {
            return $this->error('VALIDATION_ERROR', 'prompt_template is required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = $this->promptTemplateService->createTemplate($agentId, $userId, $body);
            return new JsonResponse(
                ['data' => $result],
                Response::HTTP_CREATED,
            );
        } catch (RuntimeException) {
            return $this->notFound();
        }
    }

    /**
     * GET /api/v1/agents/{agentId}/templates/{templateId}
     */
    public function show(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $agentId = (int) $request->attributes->get('id', 0);
        $templateId = (int) $request->attributes->get('templateId', 0);

        $result = $this->promptTemplateService->getTemplate($templateId, $agentId, $userId);

        if ($result === null) {
            return $this->notFound();
        }

        return new JsonResponse(['data' => $result]);
    }

    /**
     * PUT /api/v1/agents/{agentId}/templates/{templateId}
     */
    public function update(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $agentId = (int) $request->attributes->get('id', 0);
        $templateId = (int) $request->attributes->get('templateId', 0);

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }

        $result = $this->promptTemplateService->updateTemplate($templateId, $agentId, $userId, $body);

        if ($result === null) {
            return $this->notFound();
        }

        return new JsonResponse(['data' => $result]);
    }

    /**
     * DELETE /api/v1/agents/{agentId}/templates/{templateId}
     */
    public function destroy(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $agentId = (int) $request->attributes->get('id', 0);
        $templateId = (int) $request->attributes->get('templateId', 0);

        $deleted = $this->promptTemplateService->deleteTemplate($templateId, $agentId, $userId);

        if (!$deleted) {
            return $this->notFound();
        }

        return new JsonResponse(['data' => ['deleted' => true]]);
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

    private function notFound(): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'NOT_FOUND', 'message' => 'Template not found.']],
            Response::HTTP_NOT_FOUND,
        );
    }
}
