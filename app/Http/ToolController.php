<?php

declare(strict_types=1);

namespace Spora\Http;

use JsonException;
use ReflectionClass;
use Spora\Auth\AuthService;
use Spora\Services\ToolConfigService;
use Spora\Services\ToolIconResolver;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\Traits\HasOperations;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ToolController
{
    private const ERR_TOOL_NOT_FOUND_MSG = 'Tool not found.';

    private const ERR_TOOL_NOT_FOUND_CODE = 'NOT_FOUND';

    /**
     * @param string[] $toolClasses Registered tool class names (injected via container).
     */
    public function __construct(
        private readonly AuthService $authService,
        private readonly ToolConfigService $toolConfigService,
        private readonly array $toolClasses = [],
        private readonly ?ToolIconResolver $toolIconResolver = null,
    ) {}

    public function index(): JsonResponse
    {


        $tools = array_map(fn(string $class) => $this->toolSchemaResource($class), $this->toolClasses);

        return new JsonResponse(['data' => ['tools' => array_values($tools)]]);
    }

    public function getSettings(Request $request): JsonResponse
    {


        $toolClass = $this->resolveToolClassFromRequest($request);
        if ($toolClass === null) {
            return new JsonResponse(['error' => ['code' => self::ERR_TOOL_NOT_FOUND_CODE, 'message' => self::ERR_TOOL_NOT_FOUND_MSG]], 404);
        }

        $settings = $this->toolConfigService->getGlobalSettings($toolClass);
        $masked   = $this->toolConfigService->maskForApi($settings, $toolClass);

        return new JsonResponse(['data' => ['settings' => $masked]]);
    }

    public function putSettings(Request $request): JsonResponse
    {


        $toolClass = $this->resolveToolClassFromRequest($request);
        if ($toolClass === null) {
            return new JsonResponse(['error' => ['code' => self::ERR_TOOL_NOT_FOUND_CODE, 'message' => self::ERR_TOOL_NOT_FOUND_MSG]], 404);
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
            return new JsonResponse(['error' => ['code' => self::ERR_TOOL_NOT_FOUND_CODE, 'message' => self::ERR_TOOL_NOT_FOUND_MSG]], 404);
        }

        $this->toolConfigService->deleteGlobalSettings($toolClass);

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    public function getUserSettings(string $toolId): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $toolClass = $this->toolConfigService->resolveToolClass($toolId);

        if ($toolClass === null) {
            return new JsonResponse(['error' => ['code' => self::ERR_TOOL_NOT_FOUND_CODE, 'message' => self::ERR_TOOL_NOT_FOUND_MSG]], 404);
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
            return new JsonResponse(['error' => ['code' => self::ERR_TOOL_NOT_FOUND_CODE, 'message' => self::ERR_TOOL_NOT_FOUND_MSG]], 404);
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

    public function deleteUserSettings(string $toolId): Response
    {
        $userId = $this->authService->currentUserId();
        $toolClass = $this->toolConfigService->resolveToolClass($toolId);

        if ($toolClass === null) {
            return new JsonResponse(['error' => ['code' => self::ERR_TOOL_NOT_FOUND_CODE, 'message' => self::ERR_TOOL_NOT_FOUND_MSG]], 404);
        }

        $this->toolConfigService->deleteUserSettings($toolClass, $userId);

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    private function toolSchemaResource(string $toolClass): array
    {
        if (!class_exists($toolClass)) {
            // No class to resolve against — the resolver would have no tool.icon
            // attribute to read, so null on the wire is correct here.
            return ['tool_class' => $toolClass, 'tool_name' => basename(str_replace('\\', '/', $toolClass)), 'settings_schema' => [], 'operations' => [], 'icon' => null];
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
                'options'     => $setting->options,
                'expose_to_llm' => $setting->exposeToLlm,
            ];
        }

        $operations = [];
        $usesOperations = in_array(HasOperations::class, class_uses_recursive($toolClass), true);
        if ($usesOperations) {
            // Intentionally bypass the constructor to enumerate #[ToolOperation]
            // declarations for the tool's settings schema. The instance is held
            // only for attribute reflection via getOperations(), which is a pure
            // metadata reader on the class — it never performs work and never
            // requires injected dependencies. The orchestrator constructs real
            // tool instances via the DI container at execution time. Safe by
            // construction.
            $instance = $reflection->newInstanceWithoutConstructor(); // NOSONAR php:S3011 — see comment above
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
            'icon'            => $this->toolIconResolver?->resolve($toolClass),
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
