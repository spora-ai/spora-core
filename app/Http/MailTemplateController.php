<?php

declare(strict_types=1);

namespace Spora\Http;

use JsonException;
use Spora\Services\MailTemplateServiceInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Manages customizable mail templates (CRUD, preview, system template protection).
 */
final class MailTemplateController
{
    private const ERR_MAIL_TEMPLATE_NOT_FOUND = 'Mail template not found.';

    public function __construct(
        private readonly MailTemplateServiceInterface $mailTemplateService,
    ) {}

    public function index(): JsonResponse
    {

        $templates = $this->mailTemplateService->getAllTemplates();

        return new JsonResponse([
            'data' => ['mail_templates' => $templates],
        ], Response::HTTP_OK);
    }

    public function store(Request $request): JsonResponse
    {

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }

        if ($this->missingFields($body, ['name', 'subject'])) {
            return $this->error('VALIDATION_ERROR', 'The fields "name" and "subject" are required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = $this->mailTemplateService->createTemplate($body);

        return new JsonResponse([
            'data' => $result,
        ], Response::HTTP_CREATED);
    }

    public function show(Request $request, int $id): JsonResponse
    {

        $result = $this->mailTemplateService->getTemplate($id);

        if ($result === null) {
            return $this->error('NOT_FOUND', self::ERR_MAIL_TEMPLATE_NOT_FOUND, Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'data' => $result,
        ], Response::HTTP_OK);
    }

    public function update(Request $request, int $id): JsonResponse
    {

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }

        $result = $this->mailTemplateService->updateTemplate($id, $body);

        if ($result === null) {
            return $this->error('NOT_FOUND', self::ERR_MAIL_TEMPLATE_NOT_FOUND, Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'data' => $result,
        ], Response::HTTP_OK);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {

        // Check if it's a system template first
        $template = \Spora\Models\MailTemplate::find($id);
        if ($template !== null && in_array($template->name, ['email_verification', 'password_reset', 'welcome'], true)) {
            return $this->error(
                'CANNOT_DELETE_SYSTEM_TEMPLATE',
                'System templates cannot be deleted.',
                Response::HTTP_CONFLICT,
            );
        }

        $deleted = $this->mailTemplateService->deleteTemplate($id);

        if (!$deleted) {
            return $this->error('NOT_FOUND', self::ERR_MAIL_TEMPLATE_NOT_FOUND, Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    public function preview(Request $request, string $name): JsonResponse
    {

        // Collect variables from query parameters
        $variables = [];
        foreach ($request->query->all() as $key => $value) {
            $variables[$key] = (string) $value;
        }

        try {
            $result = $this->mailTemplateService->previewTemplate($name, $variables);
        } catch (Throwable) {
            return $this->error('NOT_FOUND', self::ERR_MAIL_TEMPLATE_NOT_FOUND, Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'data' => $result,
        ], Response::HTTP_OK);
    }

    private function decodeJson(Request $request): array
    {
        $content = $request->getContent();
        if ($content === '') {
            return [];
        }

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }

    private function missingFields(array $body, array $fields): bool
    {
        foreach ($fields as $field) {
            if (($body[$field] ?? '') === '') {
                return true;
            }
        }

        return false;
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
