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

    public function show(Request $request): JsonResponse
    {
        $agent = $this->findOrCreateAgent(AuthGuard::requireAuth($this->authService));

        return new JsonResponse(['data' => ['agent' => $this->agentResource($agent)]]);
    }

    public function update(Request $request): JsonResponse
    {
        $agent = $this->findOrCreateAgent(AuthGuard::requireAuth($this->authService));

        $body    = $this->decodeJson($request);
        $allowed = ['name', 'description', 'llm_provider', 'llm_model', 'llm_base_url', 'max_steps'];
        $data    = array_intersect_key($body, array_flip($allowed));

        if ($data !== []) {
            Capsule::table('agents')
                ->where('id', $agent->id)
                ->update(array_merge($data, ['updated_at' => date('Y-m-d H:i:s')]));
            $agent->refresh();
        }

        return new JsonResponse(['data' => ['agent' => $this->agentResource($agent)]]);
    }

    public function enableTool(Request $request): JsonResponse
    {
        $agent     = $this->findOrCreateAgent(AuthGuard::requireAuth($this->authService));
        $toolClass = (string) $request->attributes->get('toolClass', '');

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

    public function patchTool(Request $request): JsonResponse
    {
        $agent     = $this->findOrCreateAgent(AuthGuard::requireAuth($this->authService));
        $toolClass = (string) $request->attributes->get('toolClass', '');

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

    public function disableTool(Request $request): JsonResponse
    {
        $agent     = $this->findOrCreateAgent(AuthGuard::requireAuth($this->authService));
        $toolClass = (string) $request->attributes->get('toolClass', '');

        AgentTool::where('agent_id', $agent->id)
            ->where('tool_class', $toolClass)
            ->delete();

        $response = new JsonResponse(null, Response::HTTP_NO_CONTENT);
        $response->setContent('');

        return $response;
    }

    public function getOverride(Request $request): JsonResponse
    {
        $agent     = $this->findOrCreateAgent(AuthGuard::requireAuth($this->authService));
        $toolClass = (string) $request->attributes->get('toolClass', '');

        $settings = $this->toolConfigService->getEffectiveSettings($toolClass, (int) $agent->id);
        $masked   = $this->toolConfigService->maskForApi($settings, $toolClass);

        return new JsonResponse(['data' => ['settings' => $masked]]);
    }

    public function putOverride(Request $request): JsonResponse
    {
        $agent     = $this->findOrCreateAgent(AuthGuard::requireAuth($this->authService));
        $toolClass = (string) $request->attributes->get('toolClass', '');

        $body     = $this->decodeJson($request);
        $settings = isset($body['settings']) && is_array($body['settings']) ? $body['settings'] : $body;

        $this->toolConfigService->putAgentOverride($toolClass, (int) $agent->id, $settings);

        $effective = $this->toolConfigService->getEffectiveSettings($toolClass, (int) $agent->id);
        $masked    = $this->toolConfigService->maskForApi($effective, $toolClass);

        return new JsonResponse(['data' => ['settings' => $masked]]);
    }

    public function deleteOverride(Request $request): JsonResponse
    {
        $agent     = $this->findOrCreateAgent(AuthGuard::requireAuth($this->authService));
        $toolClass = (string) $request->attributes->get('toolClass', '');

        $this->toolConfigService->deleteAgentOverride($toolClass, (int) $agent->id);

        $response = new JsonResponse(null, Response::HTTP_NO_CONTENT);
        $response->setContent('');

        return $response;
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    private function findOrCreateAgent(int $userId): Agent
    {
        $agent = Agent::where('user_id', $userId)->first();

        if ($agent !== null) {
            return $agent;
        }

        $id = Capsule::table('agents')->insertGetId([
            'user_id'      => $userId,
            'name'         => 'My Assistant',
            'llm_provider' => 'openai_compatible',
            'llm_model'    => 'gpt-4o',
            'max_steps'    => 10,
            'is_active'    => 1,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        return Agent::find($id);
    }

    private function agentResource(Agent $agent): array
    {
        $tools = AgentTool::where('agent_id', $agent->id)->get();

        return [
            'id'           => (int) $agent->id,
            'name'         => $agent->name,
            'description'  => $agent->description,
            'recipe_id'    => $agent->recipe_id,
            'llm_provider' => $agent->llm_provider,
            'llm_model'    => $agent->llm_model,
            'llm_base_url' => $agent->llm_base_url,
            'max_steps'    => (int) $agent->max_steps,
            'is_active'    => (bool) $agent->is_active,
            'tools'        => $tools->map(fn(AgentTool $t) => $this->toolResource($t))->values()->toArray(),
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
}
