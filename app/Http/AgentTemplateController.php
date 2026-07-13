<?php

declare(strict_types=1);

namespace Spora\Http;

use JsonException;
use Spora\AgentTemplates\AgentTemplate;
use Spora\AgentTemplates\AgentTemplateExporter;
use Spora\AgentTemplates\AgentTemplateImporter;
use Spora\AgentTemplates\AgentTemplateScanner;
use Spora\AgentTemplates\AgentTemplateValidator;
use Spora\Auth\AuthService;
use Spora\Services\AgentServiceInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Agent Template endpoints:
 *
 * - GET   /api/v1/agent-templates             list built-in + plugin templates
 * - GET   /api/v1/agent-templates/{id}        get one template (full payload + warnings)
 * - POST  /api/v1/agent-templates/validate    validate a raw payload without importing
 * - POST  /api/v1/agent-templates/import      create an agent from a payload
 * - GET   /api/v1/agents/{id}/export          export an agent as a template JSON
 *
 * Replaces the previous stub `RecipeController` + `/api/v1/recipes`.
 */
final class AgentTemplateController
{
    use JsonControllerHelpers;

    public function __construct(
        private readonly AuthService $auth,
        private readonly AgentTemplateScanner $scanner,
        private readonly AgentTemplateValidator $validator,
        private readonly AgentTemplateImporter $importer,
        private readonly AgentTemplateExporter $exporter,
        private readonly AgentServiceInterface $agentService,
    ) {}

    /**
     * GET /api/v1/agent-templates
     */
    public function index(): JsonResponse
    {
        if ($this->auth->currentUserId() === null) {
            return $this->unprocessable('UNAUTHENTICATED', 'Authentication required.');
        }

        $templates = $this->scanner->scan();
        $summaries = array_map(
            static fn(AgentTemplate $t) => self::summarize($t),
            $templates,
        );

        return new JsonResponse(['data' => ['templates' => $summaries]]);
    }

    /**
     * GET /api/v1/agent-templates/{id}
     */
    public function show(Request $request): JsonResponse
    {
        if ($this->auth->currentUserId() === null) {
            return $this->unprocessable('UNAUTHENTICATED', 'Authentication required.');
        }

        $id = (string) $request->attributes->get('id', '');
        foreach ($this->scanner->scan() as $template) {
            if ($template->id() === $id) {
                return new JsonResponse([
                    'data' => [
                        'template' => $template->raw(),
                        'warnings' => $template->warnings(),
                        'source'   => $template->source(),
                        'filename' => $template->filename(),
                    ],
                ]);
            }
        }

        return $this->notFound('TEMPLATE_NOT_FOUND', "Agent template '{$id}' not found.");
    }

    /**
     * POST /api/v1/agent-templates/validate
     */
    public function validatePayload(Request $request): JsonResponse
    {
        if ($this->auth->currentUserId() === null) {
            return $this->unprocessable('UNAUTHENTICATED', 'Authentication required.');
        }

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }

        $result = $this->validator->validate($body);
        return new JsonResponse(['data' => $result->toArray()]);
    }

    /**
     * POST /api/v1/agent-templates/import
     */
    public function import(Request $request): JsonResponse
    {
        $userId = $this->auth->currentUserId();
        if ($userId === null) {
            return $this->unprocessable('UNAUTHENTICATED', 'Authentication required.');
        }

        try {
            $body = $this->decodeJson($request);
        } catch (JsonException) {
            return $this->error('INVALID_JSON', 'Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
        }

        $result = $this->validator->validate($body);
        if (!$result->isValid()) {
            return new JsonResponse(
                [
                    'error' => [
                        'code'     => 'VALIDATION_ERROR',
                        'message'  => 'Template payload is invalid.',
                        'details'  => $result->toArray(),
                    ],
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $import = $this->importer->importPayload($userId, $body);

        return new JsonResponse(
            [
                'data' => [
                    'agent'         => $this->serializeAgent($import->agent),
                    'warnings'      => $import->warnings,
                    'tools_enabled' => $import->toolsEnabled,
                ],
            ],
            Response::HTTP_CREATED,
        );
    }

    /**
     * GET /api/v1/agents/{id}/export
     */
    public function exportAgent(Request $request): JsonResponse
    {
        $userId = $this->auth->currentUserId();
        if ($userId === null) {
            return $this->unprocessable('UNAUTHENTICATED', 'Authentication required.');
        }

        $agentId = (int) $request->attributes->get('id', 0);
        $agent = $this->agentService->getAgent($agentId, $userId);
        if ($agent === null) {
            return $this->notFound('AGENT_NOT_FOUND', 'Agent not found.');
        }

        $exported = $this->exporter->export($agent);

        return new JsonResponse([
            'data' => [
                'template'       => $exported['template']->raw(),
                'inline_warning' => $exported['inline_warning'],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAgent(\Spora\Models\Agent $agent): array
    {
        return [
            'id'                   => (int) $agent->id,
            'name'                 => $agent->name,
            'description'          => $agent->description,
            'recipe_id'            => $agent->recipe_id,
            'system_prompt'        => $agent->system_prompt,
            'llm_driver_config_id' => $agent->llm_driver_config_id,
            'max_steps'            => (int) $agent->max_steps,
            'is_active'            => (bool) $agent->is_active,
            'allow_followup'       => (bool) $agent->allow_followup,
            'retry_after_minutes'  => (int) ($agent->retry_after_minutes ?? 0),
            'max_retries'          => (int) ($agent->max_retries ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function summarize(AgentTemplate $t): array
    {
        return [
            'id'               => $t->id(),
            'name'             => $t->name(),
            'description'      => $t->description(),
            'version'          => $t->version(),
            'source'           => $t->source(),
            'filename'         => $t->filename(),
            'category'         => $t->metadata()['category'] ?? 'general',
            'icon'             => $t->metadata()['icon'] ?? 'puzzle',
            'tools_count'      => count($t->tools()),
            'required_plugins' => $t->requiredPlugins(),
            'has_warnings'     => $t->hasWarnings(),
        ];
    }
}
