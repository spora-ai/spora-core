<?php

declare(strict_types=1);

namespace Spora\Http;

use JsonException;
use Spora\Auth\AuthService;
use Spora\I18n\CatalogLoader;
use Spora\I18n\Translator;
use Spora\Services\ToolConfigService;
use Spora\Services\ToolIconResolver;
use Spora\Services\ToolsRecommendsSkillsValidator;
use Spora\Tools\SharedSettingsResolver;
use Spora\Tools\ToolSchemaPresenter;
use Spora\Tools\ToolSettingSchema;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ToolController
{
    private const ERR_TOOL_NOT_FOUND_MSG = 'Tool not found.';

    private const ERR_TOOL_NOT_FOUND_CODE = 'NOT_FOUND';

    private readonly Translator $translator;

    /**
     * @param string[] $toolClasses Registered tool class names (injected via container).
     * @param ?SharedSettingsResolver $sharedSettings Plugin-declared credential
     *        groups. Null (the default, and every host that ships no group) means
     *        `inherited` is always `[]`.
     */
    public function __construct(
        private readonly AuthService $authService,
        private readonly ToolConfigService $toolConfigService,
        private readonly array $toolClasses = [],
        private readonly ?ToolIconResolver $toolIconResolver = null,
        private readonly ?ToolsRecommendsSkillsValidator $recommendsSkillsValidator = null,
        private readonly ?SharedSettingsResolver $sharedSettings = null,
        ?Translator $translator = null,
    ) {
        $this->translator = $translator ?? new Translator(new CatalogLoader());
    }

    public function index(): JsonResponse
    {
        $violations = $this->recommendsSkillsValidator?->validate() ?? [];
        if ($violations !== []) {
            return new JsonResponse([
                'error' => [
                    'code'    => 'TOOLS_RECOMMENDS_SKILLS_MISSING',
                    'message' => sprintf(
                        '%d tool(s) declare recommendsSkills slugs that are not on disk. See details for offenders.',
                        count($violations),
                    ),
                    'details' => ['violations' => $violations],
                ],
            ], 500);
        }

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

        return new JsonResponse([
            'data' => [
                'settings'  => $masked,
                'inherited' => $this->sharedInheritedSettings($toolClass),
            ],
        ]);
    }

    /**
     * The shared-group values this tool would fall back to, for the form to
     * render read-only as "inherited from <group>".
     *
     * This endpoint is authenticated, not admin-gated, so `inherited` carries
     * the same credential every logged-in user would be able to read once the
     * masking is gone. The masking is therefore not optional and is keyed off
     * the group's own schema rather than the tool's: the value was decrypted
     * under the shared group's `#[ToolSetting(type: 'password')]` list, so a
     * tool that misdeclares the key as plain text would otherwise publish the
     * plugin credential in clear.
     *
     * @return array<string, mixed>
     */
    private function sharedInheritedSettings(string $toolClass): array
    {
        if ($this->sharedSettings === null) {
            return [];
        }

        $inherited = $this->sharedSettings->inheritedValues(
            $toolClass,
            $this->toolConfigService->getGlobalSettings(...),
        );

        $groupClass = $this->sharedSettings->groupClassFor($toolClass);

        return $groupClass === null
            ? []
            : $this->toolConfigService->maskForApi($inherited, $groupClass);
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

        $principalId = $this->resolveCallerPrincipalId($userId);
        $settings = $this->toolConfigService->getPrincipalSettings($toolClass, $principalId);
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

        $principalId = $this->resolveCallerPrincipalId($userId);
        $saved = $this->toolConfigService->putPrincipalSettings($toolClass, $principalId, $settings);
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

        $principalId = $this->resolveCallerPrincipalId($userId);
        $this->toolConfigService->deletePrincipalSettings($toolClass, $principalId);

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    /**
     * Resolve the caller's user-principal id. The user-settings endpoint
     * is bound to the caller's own principal; an admin targeting a
     * group-principal should use the GroupController endpoints instead.
     */
    private function resolveCallerPrincipalId(?int $userId): int
    {
        if ($userId === null) {
            return 0;
        }
        $principal = \Spora\Models\Principal::where('type', \Spora\Models\Principal::TYPE_USER)
            ->where('user_id', $userId)
            ->first();
        return $principal !== null ? (int) $principal->id : 0;
    }

    private function toolSchemaResource(string $toolClass): array
    {
        // Per-class metadata + per-operation declarations are extracted from
        // #[Tool] / #[ToolOperation] by the shared presenter — keeps this
        // controller focused on the form-side settings schema. AgentTool
        // reuses the same presenter so the two callers stay in lockstep.
        $summary = ToolSchemaPresenter::summarize(
            $toolClass,
            $this->toolIconResolver?->resolve($toolClass),
        );

        $schema = [];
        // Schema rows for the form; `expose_to_llm`, `data_source`, and
        // `scope` are the only fields the controller needs that the
        // inspectors don't produce.
        foreach (ToolSettingSchema::collect($toolClass) as $setting) {
            // `label` and `options[].label` are never translated — see
            // {@see \Spora\Build\CatalogHarvester} — and some of them enter
            // the model's prompt through `expose_to_llm`.
            $schema[] = [
                'key'           => $setting->key,
                'label'         => $setting->label,
                'type'          => $setting->type,
                'description'   => $this->translator->trans($setting->description),
                'default'       => $setting->default,
                'required'      => $setting->required,
                'options'       => $setting->options,
                'expose_to_llm' => $setting->exposeToLlm,
                'data_source'   => $setting->dataSource,
                'scope'         => $setting->scope,
            ];
        }

        return [
            'tool_class'        => $summary['tool_class'],
            'tool_name'         => $summary['tool_name'],
            'display_name'      => $this->translator->trans($summary['display_name']),
            // `description` and `operations` stay English: they are the
            // function payload and the `AgentManifest` tool block, and the
            // operator's view of them is the model's view.
            'description'       => $summary['description'],
            'category'          => $this->translator->trans($summary['category']),
            'icon'              => $summary['icon'],
            'settings_schema'   => $schema,
            'operations'        => $summary['operations'],
            'recommends_skills' => $summary['recommends_skills'],
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
