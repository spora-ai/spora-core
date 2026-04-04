<?php

declare(strict_types=1);

namespace Spora\Http;

use Illuminate\Database\Capsule\Manager as Capsule;
use ReflectionClass;
use Spora\Auth\AuthService;
use Spora\Http\Middleware\AuthGuard;
use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Services\ToolConfigService;
use Spora\Tools\Attributes\Tool;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AgentController
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly ToolConfigService $toolConfigService,
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
        $body   = $this->decodeJson($request);

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return $this->error('VALIDATION_ERROR', 'name is required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $id = Capsule::table('agents')->insertGetId([
            'user_id'       => $userId,
            'name'          => $name,
            'description'   => trim((string) ($body['description'] ?? '')) ?: null,
            'system_prompt' => trim((string) ($body['system_prompt'] ?? '')) ?: null,
            'llm_provider'  => $body['llm_provider'] ?? 'openai_compatible',
            'llm_model'     => $body['llm_model'] ?? 'gpt-4o',
            'llm_base_url'  => $body['llm_base_url'] ?? null,
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

        $body    = $this->decodeJson($request);
        $allowed = ['name', 'description', 'system_prompt', 'llm_provider', 'llm_model', 'llm_base_url', 'max_steps'];
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
        $toolClass = (string) $request->attributes->get('toolClass', '');

        if ($agent === null) {
            return $this->notFound();
        }
        if ($toolClass === '') {
            return $this->error('VALIDATION_ERROR', 'toolClass is required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $existing = AgentTool::where('agent_id', $agent->id)
            ->where('tool_class', $toolClass)
            ->first();

        if ($existing !== null) {
            return new JsonResponse(['data' => ['tool' => $this->toolResource($existing)]], Response::HTTP_OK);
        }

        Capsule::table('agent_tools')->insert([
            'agent_id'     => $agent->id,
            'tool_class'   => $toolClass,
            'tool_name'    => $this->resolveToolName($toolClass),
            'auto_approve' => null,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        $tool = AgentTool::where('agent_id', $agent->id)->where('tool_class', $toolClass)->first();

        return new JsonResponse(['data' => ['tool' => $this->toolResource($tool)]], Response::HTTP_CREATED);
    }

    /**
     * PATCH /api/v1/agents/{id}/tools/{toolClass}
     */
    public function patchTool(Request $request): JsonResponse
    {
        $userId    = AuthGuard::requireAuth($this->authService);
        $agent     = $this->findAgent((int) $request->attributes->get('id', 0), $userId);
        $toolClass = (string) $request->attributes->get('toolClass', '');

        if ($agent === null) {
            return $this->notFound();
        }

        $tool = AgentTool::where('agent_id', $agent->id)
            ->where('tool_class', $toolClass)
            ->first();

        if ($tool === null) {
            return $this->error('NOT_FOUND', 'Tool is not enabled for this agent.', Response::HTTP_NOT_FOUND);
        }

        $body = $this->decodeJson($request);

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
     * DELETE /api/v1/agents/{id}/tools/{toolClass}/enable
     */
    public function disableTool(Request $request): JsonResponse
    {
        $userId    = AuthGuard::requireAuth($this->authService);
        $agent     = $this->findAgent((int) $request->attributes->get('id', 0), $userId);
        $toolClass = (string) $request->attributes->get('toolClass', '');

        if ($agent === null) {
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
     */
    public function getOverride(Request $request): JsonResponse
    {
        $userId    = AuthGuard::requireAuth($this->authService);
        $agent     = $this->findAgent((int) $request->attributes->get('id', 0), $userId);
        $toolClass = (string) $request->attributes->get('toolClass', '');

        if ($agent === null) {
            return $this->notFound();
        }

        $settings = $this->toolConfigService->getEffectiveSettings($toolClass, $agent->id);
        $masked   = $this->toolConfigService->maskForApi($settings, $toolClass);

        return new JsonResponse(['data' => ['settings' => $masked]]);
    }

    /**
     * PUT /api/v1/agents/{id}/tools/{toolClass}/override
     */
    public function putOverride(Request $request): JsonResponse
    {
        $userId    = AuthGuard::requireAuth($this->authService);
        $agent     = $this->findAgent((int) $request->attributes->get('id', 0), $userId);
        $toolClass = (string) $request->attributes->get('toolClass', '');

        if ($agent === null) {
            return $this->notFound();
        }

        $body     = $this->decodeJson($request);
        $settings = isset($body['settings']) && is_array($body['settings']) ? $body['settings'] : $body;

        $this->toolConfigService->putAgentOverride($toolClass, $agent->id, $settings);

        $effective = $this->toolConfigService->getEffectiveSettings($toolClass, $agent->id);
        $masked    = $this->toolConfigService->maskForApi($effective, $toolClass);

        return new JsonResponse(['data' => ['settings' => $masked]]);
    }

    /**
     * DELETE /api/v1/agents/{id}/tools/{toolClass}/override
     */
    public function deleteOverride(Request $request): JsonResponse
    {
        $userId    = AuthGuard::requireAuth($this->authService);
        $agent     = $this->findAgent((int) $request->attributes->get('id', 0), $userId);
        $toolClass = (string) $request->attributes->get('toolClass', '');

        if ($agent === null) {
            return $this->notFound();
        }

        $this->toolConfigService->deleteAgentOverride($toolClass, $agent->id);

        $response = new JsonResponse(null, Response::HTTP_NO_CONTENT);
        $response->setContent('');

        return $response;
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

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
            'llm_provider'  => $agent->llm_provider,
            'llm_model'     => $agent->llm_model,
            'llm_base_url'  => $agent->llm_base_url,
            'max_steps'     => (int) $agent->max_steps,
            'is_active'     => (bool) $agent->is_active,
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

    private function decodeJson(Request $request): array
    {
        $content = $request->getContent();
        $decoded = $content !== '' ? json_decode($content, true) : null;

        return is_array($decoded) ? $decoded : [];
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
