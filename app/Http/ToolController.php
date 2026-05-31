<?php

declare(strict_types=1);

namespace Spora\Http;

use JsonException;
use ReflectionClass;
use Spora\Auth\AuthService;
use Spora\Services\ToolConfigService;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\Traits\HasOperations;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ToolController
{
    /**
     * @param string[] $toolClasses Registered tool class names (injected via container).
     */
    public function __construct(
        private readonly AuthService $authService,
        private readonly ToolConfigService $toolConfigService,
        private readonly array $toolClasses = [],
    ) {}

    public function index(Request $request): JsonResponse
    {


        $tools = array_map(fn(string $class) => $this->toolSchemaResource($class), $this->toolClasses);

        return new JsonResponse(['data' => ['tools' => array_values($tools)]]);
    }

    public function getSettings(Request $request): JsonResponse
    {


        $toolClass = $this->resolveToolClassFromRequest($request);
        if ($toolClass === null) {
            return new JsonResponse(['error' => ['code' => 'NOT_FOUND', 'message' => 'Tool not found.']], 404);
        }

        $settings = $this->toolConfigService->getGlobalSettings($toolClass);
        $masked   = $this->toolConfigService->maskForApi($settings, $toolClass);

        return new JsonResponse(['data' => ['settings' => $masked]]);
    }

    public function putSettings(Request $request): JsonResponse
    {


        $toolClass = $this->resolveToolClassFromRequest($request);
        if ($toolClass === null) {
            return new JsonResponse(['error' => ['code' => 'NOT_FOUND', 'message' => 'Tool not found.']], 404);
        }

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return new JsonResponse(
                ['error' => ['code' => 'INVALID_JSON', 'message' => 'Request body must be valid JSON.']],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $settings = isset($body['settings']) && is_array($body['settings']) ? $body['settings'] : $body;

        $this->toolConfigService->putGlobalSettings($toolClass, $settings);

        $saved  = $this->toolConfigService->getGlobalSettings($toolClass);
        $masked = $this->toolConfigService->maskForApi($saved, $toolClass);

        return new JsonResponse(['data' => ['settings' => $masked]]);
    }

    public function deleteSettings(Request $request): JsonResponse
    {


        $toolClass = $this->resolveToolClassFromRequest($request);
        if ($toolClass === null) {
            return new JsonResponse(['error' => ['code' => 'NOT_FOUND', 'message' => 'Tool not found.']], 404);
        }

        $this->toolConfigService->deleteGlobalSettings($toolClass);

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    public function getUserSettings(Request $request, string $toolId): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $toolClass = $this->toolConfigService->resolveToolClass($toolId);

        if ($toolClass === null) {
            return new JsonResponse(['error' => ['code' => 'NOT_FOUND', 'message' => 'Tool not found.']], 404);
        }

        $settings = $this->toolConfigService->getUserSettings($toolClass, $userId);
        $masked = $this->toolConfigService->maskForApi($settings, $toolClass);

        return new JsonResponse(['data' => ['settings' => $masked]]);
    }

    public function putUserSettings(Request $request, string $toolId): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $toolClass = $this->toolConfigService->resolveToolClass($toolId);

        if ($toolClass === null) {
            return new JsonResponse(['error' => ['code' => 'NOT_FOUND', 'message' => 'Tool not found.']], 404);
        }

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return new JsonResponse(
                ['error' => ['code' => 'INVALID_JSON', 'message' => 'Request body must be valid JSON.']],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $settings = isset($body['settings']) && is_array($body['settings']) ? $body['settings'] : [];

        $saved = $this->toolConfigService->putUserSettings($toolClass, $userId, $settings);
        $masked = $this->toolConfigService->maskForApi($saved, $toolClass);

        return new JsonResponse(['data' => ['settings' => $masked]]);
    }

    public function deleteUserSettings(Request $request, string $toolId): Response
    {
        $userId = $this->authService->currentUserId();
        $toolClass = $this->toolConfigService->resolveToolClass($toolId);

        if ($toolClass === null) {
            return new JsonResponse(['error' => ['code' => 'NOT_FOUND', 'message' => 'Tool not found.']], 404);
        }

        $this->toolConfigService->deleteUserSettings($toolClass, $userId);

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    private function toolSchemaResource(string $toolClass): array
    {
        if (!class_exists($toolClass)) {
            return ['tool_class' => $toolClass, 'tool_name' => basename(str_replace('\\', '/', $toolClass)), 'settings_schema' => [], 'operations' => []];
        }

        $reflection = new ReflectionClass($toolClass);

        $toolAttrs = $reflection->getAttributes(Tool::class);
        $toolAttr  = $toolAttrs !== [] ? $toolAttrs[0]->newInstance() : null;
        $toolName  = $toolAttr->name ?? $reflection->getShortName();
        $displayName = $toolAttr->displayName ?? $toolName;
        $category = $toolAttr->category ?? 'general';

        $schema = [];
        foreach ($reflection->getAttributes(ToolSetting::class) as $attribute) {
            $setting  = $attribute->newInstance();
            $schema[] = [
                'key'         => $setting->key,
                'label'       => $setting->label,
                'type'        => $setting->type,
                'description' => $setting->description,
                'default'     => $setting->default,
                'required'    => $setting->required,
                'scope'       => $setting->scope,
                'options'     => $setting->options,
                'expose_to_llm' => $setting->exposeToLlm,
            ];
        }

        $operations = [];
        $usesOperations = in_array(HasOperations::class, class_uses_recursive($toolClass), true);
        if ($usesOperations) {
            $instance = $reflection->newInstanceWithoutConstructor();
            foreach ($instance->getOperations() as $op) {
                $operations[] = [
                    'name'                          => $op->name,
                    'description'                   => $op->description,
                    'enabledByDefault'              => $op->enabledByDefault,
                    'requiresApprovalByDefault'      => $op->requiresApprovalByDefault,
                    'discriminatorKey'              => $op->discriminatorKey,
                ];
            }
        }

        return [
            'tool_class'      => $toolClass,
            'tool_name'       => $toolName,
            'display_name'    => $displayName,
            'category'        => $category,
            'settings_schema' => $schema,
            'operations'      => $operations,
        ];
    }

    private function resolveToolClassFromRequest(Request $request): ?string
    {
        $toolId = (string) $request->attributes->get('toolId', '');

        if ($toolId === '') {
            return null;
        }

        return $this->toolConfigService->resolveToolClass($toolId);
    }

    private function decodeJson(Request $request): array
    {
        $content = $request->getContent();
        if ($content === '') {
            return [];
        }

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }
}
