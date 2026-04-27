<?php

declare(strict_types=1);

namespace Spora\Http;

use Illuminate\Database\Capsule\Manager as Capsule;
use JsonException;
use ReflectionClass;
use Spora\Auth\AuthService;
use Spora\Http\Middleware\AuthGuard;
use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Models\AgentToolOperationOverride;
use Spora\Models\AgentToolOverride;
use Spora\Models\LLMDriverConfiguration;
use Spora\Services\LLMConfigService;
use Spora\Services\ToolConfigService;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Traits\HasOperations;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class AgentController
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly ToolConfigService $toolConfigService,
        private readonly LLMConfigService $llmConfigService,
    ) {}

    /**
     * GET /api/v1/agents
     */
    public function index(Request $request): JsonResponse
    {
        $userId = AuthGuard::requireAuth($this->authService);

        $agents = Agent::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn(Agent $a) => $this->agentResource($a));

        return new JsonResponse(['data' => ['agents' => $agents->all()]]);
    }

    /**
     * POST /api/v1/agents
     */
    public function store(Request $request): JsonResponse
    {
        $userId = AuthGuard::requireAuth($this->authService);

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return $this->error('VALIDATION_ERROR', 'name is required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $id = Capsule::table('agents')->insertGetId([
            'user_id'       => $userId,
            'name'          => $name,
            'description'   => trim((string) ($body['description'] ?? '')) ?: null,
            'system_prompt' => trim((string) ($body['system_prompt'] ?? '')) ?: null,
            'llm_driver_config_id' => isset($body['llm_driver_config_id']) ? (int) $body['llm_driver_config_id'] : null,
            'max_steps'     => (int) ($body['max_steps'] ?? 10),
            'is_active'     => 1,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        $agent = Agent::find($id);

        return new JsonResponse(
            ['data' => ['agent' => $this->agentResource($agent)]],
            Response::HTTP_CREATED,
        );
    }

    /**
     * GET /api/v1/agents/{id}
     */
    public function show(Request $request): JsonResponse
    {
        $userId = AuthGuard::requireAuth($this->authService);
        $agent  = $this->findAgent((int) $request->attributes->get('id', 0), $userId);

        if ($agent === null) {
            return $this->notFound();
        }

        return new JsonResponse(['data' => ['agent' => $this->agentResource($agent)]]);
    }

    /**
     * PATCH /api/v1/agents/{id}
     */
    public function update(Request $request): JsonResponse
    {
        $userId = AuthGuard::requireAuth($this->authService);
        $agent  = $this->findAgent((int) $request->attributes->get('id', 0), $userId);

        if ($agent === null) {
            return $this->notFound();
        }

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }

        $allowed = ['name', 'description', 'system_prompt', 'llm_driver_config_id', 'max_steps', 'retry_after_minutes', 'max_retries'];
        $data    = array_intersect_key($body, array_flip($allowed));

        if ($data !== []) {
            Capsule::table('agents')
                ->where('id', $agent->id)
                ->update(array_merge($data, ['updated_at' => date('Y-m-d H:i:s')]));
            $agent->refresh();
        }

        return new JsonResponse(['data' => ['agent' => $this->agentResource($agent)]]);
    }

    /**
     * DELETE /api/v1/agents/{id}
     */
    public function destroy(Request $request): JsonResponse
    {
        $userId = AuthGuard::requireAuth($this->authService);
        $agent  = $this->findAgent((int) $request->attributes->get('id', 0), $userId);

        if ($agent === null) {
            return $this->notFound();
        }

        Capsule::table('agents')->where('id', $agent->id)->delete();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * POST /api/v1/agents/{id}/tools/{toolClass}/enable
     */
    public function enableTool(Request $request): JsonResponse
    {
        $userId    = AuthGuard::requireAuth($this->authService);
        $agent     = $this->findAgent((int) $request->attributes->get('id', 0), $userId);
        $toolClass = $this->resolveToolClassFromRequest($request);

        if ($agent === null) {
            return $this->notFound();
        }
        if ($toolClass === null) {
            return $this->error('VALIDATION_ERROR', 'toolClass is required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $existing = AgentTool::where('agent_id', $agent->id)
            ->where('tool_class', $toolClass)
            ->first();

        if ($existing !== null) {
            return new JsonResponse(['data' => ['tool' => $this->toolResource($existing)]], Response::HTTP_OK);
        }

        // Determine auto_approve default based on tool operations.
        // If all operations have requiresApprovalByDefault: false, auto_approve = true.
        // Otherwise null (per-operation approval is used).
        $autoApprove = null;
        if (class_exists($toolClass)) {
            $ref = new ReflectionClass($toolClass);
            if (in_array(HasOperations::class, class_uses_recursive($toolClass), true)) {
                $instance = $ref->newInstanceWithoutConstructor();
                $operations = $instance->getOperations();
                if ($operations !== [] && array_all($operations, fn($op) => $op->requiresApprovalByDefault === false)) {
                    $autoApprove = true;
                }
            }
        }

        Capsule::table('agent_tools')->insert([
            'agent_id'     => $agent->id,
            'tool_class'   => $toolClass,
            'tool_name'    => $this->resolveToolName($toolClass),
            'auto_approve' => $autoApprove,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        // Seed schema defaults if there is no global config AND no agent override for this tool.
        // This gives the agent meaningful defaults immediately on enable.
        $globalSettings = $this->toolConfigService->getGlobalSettings($toolClass);
        $hasAgentOverride = AgentToolOverride::where('agent_id', $agent->id)
            ->where('tool_class', $toolClass)
            ->exists();

        if (!empty($globalSettings) || $hasAgentOverride) {
            // Nothing to seed — global config or override already exists
        } else {
            $defaults = $this->toolConfigService->getSchemaDefaults($toolClass);
            if ($defaults !== []) {
                $this->toolConfigService->putAgentOverride($toolClass, $agent->id, $defaults);
            }
        }

        $tool = AgentTool::where('agent_id', $agent->id)->where('tool_class', $toolClass)->first();

        // Check if required settings are missing
        $effective = $this->toolConfigService->getEffectiveSettings($toolClass, $agent->id);
        $missing   = $this->toolConfigService->getMissingRequiredSettings($toolClass, $effective);

        $responseData = ['tool' => $this->toolResource($tool)];
        if (!empty($missing)) {
            $responseData['warning'] = 'Required settings are missing. The tool may not work until credentials are configured.';
            $responseData['missing_required'] = $missing;
        }

        return new JsonResponse(['data' => $responseData], Response::HTTP_CREATED);
    }

    /**
     * PATCH /api/v1/agents/{id}/tools/{toolClass}
     */
    public function patchTool(Request $request): JsonResponse
    {
        $userId    = AuthGuard::requireAuth($this->authService);
        $agent     = $this->findAgent((int) $request->attributes->get('id', 0), $userId);
        $toolClass = $this->resolveToolClassFromRequest($request);

        if ($agent === null || $toolClass === null) {
            return $this->notFound();
        }

        $tool = AgentTool::where('agent_id', $agent->id)
            ->where('tool_class', $toolClass)
            ->first();

        if ($tool === null) {
            return $this->error('NOT_FOUND', 'Tool is not enabled for this agent.', Response::HTTP_NOT_FOUND);
        }

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }

        if (array_key_exists('auto_approve', $body)) {
            $raw     = $body['auto_approve'];
            $dbValue = $raw === null ? null : ($raw ? 1 : 0);

            Capsule::table('agent_tools')
                ->where('id', $tool->id)
                ->update(['auto_approve' => $dbValue, 'updated_at' => date('Y-m-d H:i:s')]);
            $tool->refresh();
        }

        return new JsonResponse(['data' => ['tool' => $this->toolResource($tool)]]);
    }

    /**
     * GET /api/v1/agents/{id}/tools/{toolId}/status
     */
    public function getToolStatus(Request $request): JsonResponse
    {
        $userId = AuthGuard::requireAuth($this->authService);
        $agent  = $this->findAgent((int) $request->attributes->get('id', 0), $userId);
        $toolId = (string) $request->attributes->get('toolId', '');
        $toolClass = $this->toolConfigService->resolveToolClass($toolId);

        if ($agent === null || $toolClass === null) {
            return $this->notFound();
        }

        $isEnabled = AgentTool::where('agent_id', $agent->id)
            ->where('tool_class', $toolClass)
            ->exists();

        $effective = $this->toolConfigService->getEffectiveSettings($toolClass, $agent->id);
        $missing   = $this->toolConfigService->getMissingRequiredSettings($toolClass, $effective);

        return new JsonResponse(['data' => [
            'tool_class'      => $toolClass,
            'is_enabled'      => $isEnabled,
            'missing_required' => $missing,
            'can_enable'      => empty($missing),
        ]]);
    }

    /**
     * GET /api/v1/agents/{id}/tools/status
     *
     * Returns status for every registered tool class in a single response.
     * Used by AgentSettingsPage to replace N parallel per-tool status calls.
     */
    public function getToolsStatus(Request $request): JsonResponse
    {
        $userId = AuthGuard::requireAuth($this->authService);
        $agent  = $this->findAgent((int) $request->attributes->get('id', 0), $userId);

        if ($agent === null) {
            return $this->notFound();
        }

        $toolClasses = $this->toolConfigService->getRegisteredToolClasses();
        $statuses    = [];

        $enabledTools = AgentTool::where('agent_id', $agent->id)
            ->pluck('tool_class')
            ->flip()
            ->toArray();

        foreach ($toolClasses as $toolClass) {
            $isEnabled = isset($enabledTools[$toolClass]);
            $effective = $this->toolConfigService->getEffectiveSettings($toolClass, $agent->id);
            $missing   = $this->toolConfigService->getMissingRequiredSettings($toolClass, $effective);

            $statuses[] = [
                'tool_class'      => $toolClass,
                'is_enabled'      => $isEnabled,
                'missing_required' => $missing,
                'can_enable'      => empty($missing),
            ];
        }

        return new JsonResponse(['data' => ['statuses' => $statuses]]);
    }

    /**
     * GET /api/v1/agents/{id}/tools/operations
     *
     * Returns operation override state for every operation of every enabled tool.
     * Used by AgentSettingsPage to replace N×M parallel per-operation calls.
     */
    public function getToolsOperations(Request $request): JsonResponse
    {
        $userId = AuthGuard::requireAuth($this->authService);
        $agent  = $this->findAgent((int) $request->attributes->get('id', 0), $userId);

        if ($agent === null) {
            return $this->notFound();
        }

        $enabledTools = AgentTool::where('agent_id', $agent->id)->get();
        $operations  = [];

        // Fetch all overrides for this agent in one query
        $overrides = AgentToolOperationOverride::where('agent_id', $agent->id)
            ->get()
            ->keyBy(fn($row) => $row->tool_class . '::' . $row->operation);

        foreach ($enabledTools as $tool) {
            if (!class_exists($tool->tool_class)) {
                continue;
            }
            if (!in_array(HasOperations::class, class_uses_recursive($tool->tool_class), true)) {
                continue;
            }

            $instance = $this->resolveToolInstance($tool->tool_class);
            if ($instance === null) {
                continue;
            }

            foreach ($instance->getOperations() as $op) {
                $key = $tool->tool_class . '::' . $op->name;
                $row = $overrides->get($key);

                $effectiveEnabled         = $this->resolveOperationEffectiveEnabled($tool->tool_class, $op->name, $agent->id);
                $effectiveRequiresApproval = $this->resolveOperationEffectiveRequiresApproval($tool->tool_class, $op->name, $agent->id);

                $operations[] = [
                    'tool_class'                  => $tool->tool_class,
                    'operation'                   => $op->name,
                    'enabled'                     => ($row !== null && $row->getRawOriginal('enabled') !== null) ? (int) $row->getRawOriginal('enabled') === 1 : null,
                    'default_requires_approval'  => ($row !== null && $row->getRawOriginal('default_requires_approval') !== null) ? (int) $row->getRawOriginal('default_requires_approval') === 1 : null,
                    'effective_enabled'           => $effectiveEnabled,
                    'effective_requires_approval' => $effectiveRequiresApproval,
                ];
            }
        }

        return new JsonResponse(['data' => ['operations' => $operations]]);
    }

    /**
     * DELETE /api/v1/agents/{id}/tools/{toolClass}/enable
     */
    public function disableTool(Request $request): JsonResponse
    {
        $userId    = AuthGuard::requireAuth($this->authService);
        $agent     = $this->findAgent((int) $request->attributes->get('id', 0), $userId);
        $toolClass = $this->resolveToolClassFromRequest($request);

        if ($agent === null || $toolClass === null) {
            return $this->notFound();
        }

        AgentTool::where('agent_id', $agent->id)
            ->where('tool_class', $toolClass)
            ->delete();

        $response = new JsonResponse(null, Response::HTTP_NO_CONTENT);
        $response->setContent('');

        return $response;
    }

    /**
     * GET /api/v1/agents/{id}/tools/{toolClass}/override
     *
     * Query params:
     *   ?raw=true  — return only the raw agent override (no global merge)
     *   Otherwise — return effective settings with source annotation per field
     */
    public function getOverride(Request $request): JsonResponse
    {
        $userId = AuthGuard::requireAuth($this->authService);
        $agent  = $this->findAgent((int) $request->attributes->get('id', 0), $userId);

        if ($agent === null) {
            return $this->notFound();
        }

        $toolId    = (string) $request->attributes->get('toolId', '');
        $toolClass = $this->toolConfigService->resolveToolClass($toolId);
        $rawOnly   = $request->query->get('raw') === 'true';

        // llm_configuration is not a registered tool class, so resolveToolClass
        // returns null. Handle it as a special case: fall back to LLMDriverConfiguration.
        if ($toolId === 'llm_configuration') {
            $config = LLMDriverConfiguration::where('user_id', $userId)->where('is_default', true)->first();
            try {
                $settings = $config !== null
                    ? $this->llmConfigService->decryptSettings($config->getRawOriginal('settings'))
                    : [];
            } catch (Throwable) {
                $settings = [];
            }

            // Mask password fields using the driver's schema
            $drivers = $this->llmConfigService->getDrivers();
            $schema  = null;
            foreach ($drivers as $driver) {
                if ($config !== null && $driver['driver_class'] === $config->driver_class) {
                    $schema = $driver['settings_schema'];
                    break;
                }
            }
            $masked = $schema !== null ? $this->llmConfigService->maskForApi($settings, $schema) : $settings;

            return new JsonResponse(['data' => ['settings' => $masked]]);
        }

        if ($toolClass === null) {
            return $this->notFound();
        }

        if ($rawOnly) {
            // Return only the raw agent override (no global merge)
            $settings = $this->toolConfigService->getRawAgentOverride($toolClass, $agent->id);
            $masked   = $this->toolConfigService->maskForApi($settings, $toolClass);

            return new JsonResponse(['data' => ['settings' => $masked]]);
        }

        // Return effective settings with source annotation
        $annotated = $this->toolConfigService->getEffectiveSettingsWithSource($toolClass, $agent->id);
        $masked    = [];

        foreach ($annotated as $key => $item) {
            $masked[$key] = [
                'value'  => $item['value'],
                'source' => $item['source'],
            ];
            // Mask password values
            if ($item['value'] !== null && $item['value'] !== '') {
                $passwordKeys = $this->getToolPasswordKeys($toolClass);
                if (in_array($key, $passwordKeys, true)) {
                    $masked[$key]['value'] = '***';
                }
            }
        }

        return new JsonResponse(['data' => ['settings' => $masked]]);
    }

    /**
     * PUT /api/v1/agents/{id}/tools/{toolId}/override
     */
    public function putOverride(Request $request): JsonResponse
    {
        $userId    = AuthGuard::requireAuth($this->authService);
        $agent     = $this->findAgent((int) $request->attributes->get('id', 0), $userId);
        $toolClass = $this->resolveToolClassFromRequest($request);

        if ($agent === null || $toolClass === null) {
            return $this->notFound();
        }

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }

        $settings = isset($body['settings']) && is_array($body['settings']) ? $body['settings'] : $body;

        $this->toolConfigService->putAgentOverride($toolClass, $agent->id, $settings);

        $effective = $this->toolConfigService->getEffectiveSettings($toolClass, $agent->id);
        $masked    = $this->toolConfigService->maskForApi($effective, $toolClass);

        return new JsonResponse(['data' => ['settings' => $masked]]);
    }

    /**
     * DELETE /api/v1/agents/{id}/tools/{toolId}/override
     */
    public function deleteOverride(Request $request): JsonResponse
    {
        $userId    = AuthGuard::requireAuth($this->authService);
        $agent     = $this->findAgent((int) $request->attributes->get('id', 0), $userId);
        $toolClass = $this->resolveToolClassFromRequest($request);

        if ($agent === null || $toolClass === null) {
            return $this->notFound();
        }

        $this->toolConfigService->deleteAgentOverride($toolClass, $agent->id);

        $response = new JsonResponse(null, Response::HTTP_NO_CONTENT);
        $response->setContent('');

        return $response;
    }

    /**
     * GET /api/v1/agents/{id}/tools/{toolClass}/operations/{operation}
     */
    public function getOperationOverride(Request $request): JsonResponse
    {
        $userId     = AuthGuard::requireAuth($this->authService);
        $agent      = $this->findAgent((int) $request->attributes->get('id', 0), $userId);
        $toolClass  = $this->resolveToolClassFromRequest($request);
        $operation  = (string) $request->attributes->get('operation', '');

        if ($agent === null || $toolClass === null || $operation === '') {
            return $this->notFound();
        }

        /** @var AgentToolOperationOverride|null $row */
        $row = AgentToolOperationOverride::where('agent_id', $agent->id)
            ->where('tool_class', $toolClass)
            ->where('operation', $operation)
            ->first();

        $effectiveEnabled  = $this->resolveOperationEffectiveEnabled($toolClass, $operation, $agent->id);
        $effectiveRequiresApproval = $this->resolveOperationEffectiveRequiresApproval($toolClass, $operation, $agent->id);

        return new JsonResponse(['data' => [
            'operation'                  => $operation,
            'tool_class'                 => $toolClass,
            'enabled'                    => ($row !== null && $row->getRawOriginal('enabled') !== null) ? (int) $row->getRawOriginal('enabled') === 1 : null,
            'default_requires_approval'  => ($row !== null && $row->getRawOriginal('default_requires_approval') !== null) ? (int) $row->getRawOriginal('default_requires_approval') === 1 : null,
            'effective_enabled'          => $effectiveEnabled,
            'effective_requires_approval' => $effectiveRequiresApproval,
        ]]);
    }

    /**
     * PATCH /api/v1/agents/{id}/tools/{toolClass}/operations/{operation}
     */
    public function patchOperationOverride(Request $request): JsonResponse
    {
        $userId     = AuthGuard::requireAuth($this->authService);
        $agent      = $this->findAgent((int) $request->attributes->get('id', 0), $userId);
        $toolClass  = $this->resolveToolClassFromRequest($request);
        $operation  = (string) $request->attributes->get('operation', '');

        if ($agent === null || $toolClass === null || $operation === '') {
            return $this->notFound();
        }

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }

        $enabled = array_key_exists('enabled', $body)
            ? ($body['enabled'] === null ? null : (filter_var($body['enabled'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0))
            : null;
        $defaultRequiresApproval = array_key_exists('default_requires_approval', $body)
            ? ($body['default_requires_approval'] === null ? null : (filter_var($body['default_requires_approval'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0))
            : null;

        /** @var AgentToolOperationOverride|null $existing */
        $existing = AgentToolOperationOverride::where('agent_id', $agent->id)
            ->where('tool_class', $toolClass)
            ->where('operation', $operation)
            ->first();

        if ($existing !== null) {
            $updateData = [];
            if ($enabled !== null) {
                $updateData['enabled'] = $enabled;
            }
            if ($defaultRequiresApproval !== null) {
                $updateData['default_requires_approval'] = $defaultRequiresApproval;
            }
            if ($updateData !== []) {
                $updateData['updated_at'] = date('Y-m-d H:i:s');
                Capsule::table('agent_tool_operation_overrides')
                    ->where('id', $existing->id)
                    ->update($updateData);
            }
        } else {
            $insertData = [
                'agent_id' => $agent->id,
                'tool_class' => $toolClass,
                'operation' => $operation,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if ($enabled !== null) {
                $insertData['enabled'] = $enabled;
            }
            if ($defaultRequiresApproval !== null) {
                $insertData['default_requires_approval'] = $defaultRequiresApproval;
            }
            Capsule::table('agent_tool_operation_overrides')->insert($insertData);
        }

        return $this->getOperationOverride($request);
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    private function resolveOperationEffectiveEnabled(string $toolClass, string $operation, int $agentId): bool
    {
        $override = AgentToolOperationOverride::where('agent_id', $agentId)
            ->where('tool_class', $toolClass)
            ->where('operation', $operation)
            ->first();

        if ($override !== null) {
            $raw = $override->getRawOriginal('enabled');
            if ($raw !== null) {
                return (bool) $raw;
            }
        }

        if (class_exists($toolClass)) {
            $instance = $this->resolveToolInstance($toolClass);
            if ($instance !== null && in_array(HasOperations::class, class_uses_recursive($toolClass), true)) {
                return $instance->isEnabledByDefault($operation);
            }
        }

        return true;
    }

    private function resolveOperationEffectiveRequiresApproval(string $toolClass, string $operation, int $agentId): bool
    {
        $override = AgentToolOperationOverride::where('agent_id', $agentId)
            ->where('tool_class', $toolClass)
            ->where('operation', $operation)
            ->first();

        if ($override !== null) {
            $raw = $override->getRawOriginal('default_requires_approval');
            if ($raw !== null) {
                return (bool) $raw;
            }
        }

        if (class_exists($toolClass)) {
            $instance = $this->resolveToolInstance($toolClass);
            if ($instance !== null && in_array(HasOperations::class, class_uses_recursive($toolClass), true)) {
                return $instance->requiresApprovalByDefault($operation);
            }
        }

        return true;
    }

    private function resolveToolInstance(string $toolClass): ?object
    {
        static $instances = [];
        if (!class_exists($toolClass)) {
            return null;
        }
        if (!isset($instances[$toolClass])) {
            try {
                $instances[$toolClass] = (new ReflectionClass($toolClass))->newInstanceWithoutConstructor();
            } catch (Throwable $e) {
                return null;
            }
        }
        return $instances[$toolClass];
    }

    private function findAgent(int $id, int $userId): ?Agent
    {
        return Agent::where('id', $id)->where('user_id', $userId)->first();
    }

    private function agentResource(Agent $agent): array
    {
        $tools = AgentTool::where('agent_id', $agent->id)->get();

        return [
            'id'            => (int) $agent->id,
            'name'          => $agent->name,
            'description'   => $agent->description,
            'recipe_id'     => $agent->recipe_id,
            'system_prompt' => $agent->system_prompt,
            'llm_driver_config_id' => $agent->llm_driver_config_id,
            'max_steps'     => (int) $agent->max_steps,
            'is_active'     => (bool) $agent->is_active,
            'retry_after_minutes' => (int) ($agent->retry_after_minutes ?? 0),
            'max_retries'   => (int) ($agent->max_retries ?? 0),
            'tools'         => $tools->map(fn(AgentTool $t) => $this->toolResource($t))->values()->toArray(),
        ];
    }

    private function toolResource(AgentTool $tool): array
    {
        $raw = $tool->getRawOriginal('auto_approve');

        return [
            'tool_class'   => $tool->tool_class,
            'tool_name'    => $tool->tool_name,
            'auto_approve' => $raw === null ? null : (bool) $raw,
        ];
    }

    private function resolveToolName(string $toolClass): string
    {
        if (!class_exists($toolClass)) {
            return basename(str_replace('\\', '/', $toolClass));
        }

        $reflection = new ReflectionClass($toolClass);
        $attrs      = $reflection->getAttributes(Tool::class);

        if ($attrs !== []) {
            return $attrs[0]->newInstance()->name;
        }

        return $reflection->getShortName();
    }

    /**
     * Resolve the {toolId} route parameter to a fully-qualified PHP class name.
     * Returns null if the tool identifier is not registered.
     */
    private function resolveToolClassFromRequest(Request $request): ?string
    {
        $toolId = (string) $request->attributes->get('toolId', '');

        if ($toolId === '') {
            return null;
        }

        return $this->toolConfigService->resolveToolClass($toolId);
    }

    /**
     * Return keys of all #[ToolSetting] attributes where type === 'password' on a given class.
     *
     * @return list<string>
     */
    private function getToolPasswordKeys(string $toolClass): array
    {
        if (!class_exists($toolClass)) {
            return [];
        }

        $keys = [];
        foreach ((new ReflectionClass($toolClass))->getAttributes(\Spora\Tools\Attributes\ToolSetting::class) as $attr) {
            /** @var \Spora\Tools\Attributes\ToolSetting $instance */
            $instance = $attr->newInstance();
            if ($instance->type === 'password') {
                $keys[] = $instance->key;
            }
        }

        return $keys;
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
            ['error' => ['code' => 'NOT_FOUND', 'message' => 'Agent not found.']],
            Response::HTTP_NOT_FOUND,
        );
    }
}
