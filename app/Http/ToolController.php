<?php

declare(strict_types=1);

namespace Spora\Http;

use ReflectionClass;
use Spora\Auth\AuthService;
use Spora\Http\Middleware\AuthGuard;
use Spora\Services\ToolConfigService;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolSetting;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

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
        AuthGuard::requireAuth($this->authService);

        $tools = array_map(fn (string $class) => $this->toolSchemaResource($class), $this->toolClasses);

        return new JsonResponse(['data' => ['tools' => array_values($tools)]]);
    }

    public function getSettings(Request $request): JsonResponse
    {
        AuthGuard::requireAuth($this->authService);

        $toolClass = (string) $request->attributes->get('toolClass', '');
        $settings  = $this->toolConfigService->getGlobalSettings($toolClass);
        $masked    = $this->toolConfigService->maskForApi($settings, $toolClass);

        return new JsonResponse(['data' => ['settings' => $masked]]);
    }

    public function putSettings(Request $request): JsonResponse
    {
        AuthGuard::requireAuth($this->authService);

        $toolClass = (string) $request->attributes->get('toolClass', '');
        $body      = $this->decodeJson($request);
        $settings  = isset($body['settings']) && is_array($body['settings']) ? $body['settings'] : $body;

        $this->toolConfigService->putGlobalSettings($toolClass, $settings);

        $saved  = $this->toolConfigService->getGlobalSettings($toolClass);
        $masked = $this->toolConfigService->maskForApi($saved, $toolClass);

        return new JsonResponse(['data' => ['settings' => $masked]]);
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    private function toolSchemaResource(string $toolClass): array
    {
        if (!class_exists($toolClass)) {
            return ['tool_class' => $toolClass, 'tool_name' => basename(str_replace('\\', '/', $toolClass)), 'settings_schema' => []];
        }

        $reflection = new ReflectionClass($toolClass);

        $toolAttrs = $reflection->getAttributes(Tool::class);
        $toolName  = $toolAttrs !== [] ? $toolAttrs[0]->newInstance()->name : $reflection->getShortName();

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
            ];
        }

        return [
            'tool_class'      => $toolClass,
            'tool_name'       => $toolName,
            'settings_schema' => $schema,
        ];
    }

    private function decodeJson(Request $request): array
    {
        $content = $request->getContent();
        $decoded = $content !== '' ? json_decode($content, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}
