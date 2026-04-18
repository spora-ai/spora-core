<?php

declare(strict_types=1);

namespace Spora\Http;

use Illuminate\Database\Capsule\Manager as Capsule;
use JsonException;
use Spora\Auth\AuthService;
use Spora\Http\Middleware\AuthGuard;
use Spora\Models\Agent;
use Spora\Models\AgentPromptTemplate;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PromptTemplateController
{
    public function __construct(
        private readonly AuthService $authService,
    ) {}

    /**
     * GET /api/v1/agents/{agentId}/templates
     */
    public function index(Request $request): JsonResponse
    {
        $userId = AuthGuard::requireAuth($this->authService);
        $agent  = $this->findAgent((int) $request->attributes->get('id', 0), $userId);

        if ($agent === null) {
            return $this->notFound();
        }

        $templates = AgentPromptTemplate::where('agent_id', $agent->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn(AgentPromptTemplate $t) => $this->resource($t));

        return new JsonResponse(['data' => ['templates' => $templates->all()]]);
    }

    /**
     * POST /api/v1/agents/{agentId}/templates
     */
    public function store(Request $request): JsonResponse
    {
        $userId = AuthGuard::requireAuth($this->authService);
        $agentIdParam = (int) $request->attributes->get('id', 0);
        $agent  = $this->findAgent($agentIdParam, $userId);

        if ($agent === null) {
            return $this->notFound();
        }

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

        $id = Capsule::table('agent_prompt_templates')->insertGetId([
            'agent_id'         => $agent->id,
            'name'             => $name,
            'description'      => isset($body['description']) ? trim((string) $body['description']) : null,
            'prompt_template'  => $promptTemplate,
            'variables'        => isset($body['variables']) && is_array($body['variables']) ? json_encode($body['variables']) : null,
            'max_steps'        => isset($body['max_steps']) ? (int) $body['max_steps'] : null,
            'is_active'        => isset($body['is_active']) ? ($body['is_active'] ? 1 : 0) : 1,
            'created_at'       => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);

        $template = AgentPromptTemplate::findOrFail($id);

        return new JsonResponse(
            ['data' => ['template' => $this->resource($template)]],
            Response::HTTP_CREATED,
        );
    }

    /**
     * GET /api/v1/agents/{agentId}/templates/{templateId}
     */
    public function show(Request $request): JsonResponse
    {
        $userId = AuthGuard::requireAuth($this->authService);
        $agent  = $this->findAgent((int) $request->attributes->get('id', 0), $userId);

        if ($agent === null) {
            return $this->notFound();
        }

        $template = $this->findTemplate((int) $request->attributes->get('templateId', 0), $agent->id);

        if ($template === null) {
            return $this->notFound();
        }

        return new JsonResponse(['data' => ['template' => $this->resource($template)]]);
    }

    /**
     * PUT /api/v1/agents/{agentId}/templates/{templateId}
     */
    public function update(Request $request): JsonResponse
    {
        $userId = AuthGuard::requireAuth($this->authService);
        $agent  = $this->findAgent((int) $request->attributes->get('id', 0), $userId);

        if ($agent === null) {
            return $this->notFound();
        }

        $template = $this->findTemplate((int) $request->attributes->get('templateId', 0), $agent->id);

        if ($template === null) {
            return $this->notFound();
        }

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }

        $allowed = ['name', 'description', 'prompt_template', 'variables', 'max_steps', 'is_active'];
        $data    = array_intersect_key($body, array_flip($allowed));

        if ($data !== []) {
            if (array_key_exists('variables', $data) && is_array($data['variables'])) {
                $data['variables'] = json_encode($data['variables']);
            }
            if (isset($data['is_active'])) {
                $data['is_active'] = $data['is_active'] ? 1 : 0;
            }
            Capsule::table('agent_prompt_templates')
                ->where('id', $template->id)
                ->update(array_merge($data, ['updated_at' => date('Y-m-d H:i:s')]));
            $template->refresh();
        }

        return new JsonResponse(['data' => ['template' => $this->resource($template)]]);
    }

    /**
     * DELETE /api/v1/agents/{agentId}/templates/{templateId}
     */
    public function destroy(Request $request): Response
    {
        $userId = AuthGuard::requireAuth($this->authService);
        $agent  = $this->findAgent((int) $request->attributes->get('id', 0), $userId);

        if ($agent === null) {
            return $this->notFound();
        }

        $template = $this->findTemplate((int) $request->attributes->get('templateId', 0), $agent->id);

        if ($template === null) {
            return $this->notFound();
        }

        Capsule::table('agent_prompt_templates')->where('id', $template->id)->delete();

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    private function findAgent(int $id, int $userId): ?Agent
    {
        return Agent::where('id', $id)->where('user_id', $userId)->first();
    }

    private function findTemplate(int $id, int $agentId): ?AgentPromptTemplate
    {
        return AgentPromptTemplate::where('id', $id)->where('agent_id', $agentId)->first();
    }

    private function resource(AgentPromptTemplate $template): array
    {
        return [
            'id'              => (int) $template->id,
            'agent_id'        => (int) $template->agent_id,
            'name'            => $template->name,
            'description'     => $template->description,
            'prompt_template' => $template->prompt_template,
            'variables'       => $template->variables ?? [],
            'max_steps'       => $template->max_steps,
            'is_active'       => (bool) $template->is_active,
            'created_at'      => $template->created_at->toIso8601String(),
            'updated_at'      => $template->updated_at->toIso8601String(),
        ];
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
