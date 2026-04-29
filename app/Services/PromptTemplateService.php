<?php

declare(strict_types=1);

namespace Spora\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\Agent;
use Spora\Models\AgentPromptTemplate;

/**
 * Service for prompt template management.
 * All DB access for AgentPromptTemplate domain goes through this service.
 */
final class PromptTemplateService implements PromptTemplateServiceInterface
{
    public function getTemplatesForAgent(int $agentId, int $userId): ?array
    {
        $agent = $this->findAgent($agentId, $userId);
        if ($agent === null) {
            return null;
        }

        $templates = AgentPromptTemplate::where('agent_id', $agentId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn(AgentPromptTemplate $t) => $this->resource($t));

        return $templates->all();
    }

    public function createTemplate(int $agentId, int $userId, array $data): array
    {
        $agent = $this->findAgent($agentId, $userId);
        if ($agent === null) {
            throw new \RuntimeException('Agent not found');
        }

        $id = Capsule::table('agent_prompt_templates')->insertGetId([
            'agent_id'         => $agentId,
            'name'             => $data['name'],
            'description'      => isset($data['description']) ? trim((string) $data['description']) : null,
            'prompt_template'  => $data['prompt_template'],
            'variables'        => isset($data['variables']) && is_array($data['variables']) ? json_encode($data['variables']) : null,
            'max_steps'        => isset($data['max_steps']) ? (int) $data['max_steps'] : null,
            'is_active'        => isset($data['is_active']) ? ($data['is_active'] ? 1 : 0) : 1,
            'created_at'       => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);

        $template = AgentPromptTemplate::findOrFail($id);

        return ['template' => $this->resource($template)];
    }

    public function getTemplate(int $templateId, int $agentId, int $userId): ?array
    {
        $agent = $this->findAgent($agentId, $userId);
        if ($agent === null) {
            return null;
        }

        $template = $this->findTemplate($templateId, $agentId);
        if ($template === null) {
            return null;
        }

        return ['template' => $this->resource($template)];
    }

    public function updateTemplate(int $templateId, int $agentId, int $userId, array $data): ?array
    {
        $agent = $this->findAgent($agentId, $userId);
        if ($agent === null) {
            return null;
        }

        $template = $this->findTemplate($templateId, $agentId);
        if ($template === null) {
            return null;
        }

        $allowed = ['name', 'description', 'prompt_template', 'variables', 'max_steps', 'is_active'];
        $updateData = array_intersect_key($data, array_flip($allowed));

        if ($updateData !== []) {
            if (array_key_exists('variables', $updateData) && is_array($updateData['variables'])) {
                $updateData['variables'] = json_encode($updateData['variables']);
            }
            if (isset($updateData['is_active'])) {
                $updateData['is_active'] = $updateData['is_active'] ? 1 : 0;
            }
            Capsule::table('agent_prompt_templates')
                ->where('id', $templateId)
                ->update(array_merge($updateData, ['updated_at' => date('Y-m-d H:i:s')]));
            $template->refresh();
        }

        return ['template' => $this->resource($template)];
    }

    public function deleteTemplate(int $templateId, int $agentId, int $userId): bool
    {
        $agent = $this->findAgent($agentId, $userId);
        if ($agent === null) {
            return false;
        }

        $template = $this->findTemplate($templateId, $agentId);
        if ($template === null) {
            return false;
        }

        Capsule::table('agent_prompt_templates')->where('id', $templateId)->delete();

        return true;
    }

    // ── Private helpers ─────────────────────────────────────────────────────────

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
}