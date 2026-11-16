<?php

declare(strict_types=1);

namespace Spora\AgentTemplates;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Http\Exceptions\AgentPatchValidationException;
use Spora\Services\AgentStepCap;

/**
 * Inserts the agent row during a template import. Extracted from
 * {@see AgentTemplateImporter} so the importer stays under the SonarCloud
 * 20-method-per-class ceiling (S1448). The split mirrors the natural
 * read/write seam — orchestration lives in the importer, the DB insert
 * lives here.
 */
final class AgentTemplateAgentCreator
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly ?AgentStepCap $stepCap = null,
    ) {}

    /**
     * @throws AgentPatchValidationException when the template names a cap this installation does not allow
     */
    public function create(int $principalId, AgentTemplate $template): int
    {
        $agent = $template->agent();
        $now = date(self::DATETIME_FORMAT);
        $allowFollowup = (bool) ($agent['allow_followup'] ?? true);

        // A cap the template names is judged by the instance's ceiling, the
        // same as every other surface that takes one. A template on disk is
        // not validated against it — the scanner has no instance to judge
        // it by — so the refusal belongs here, before the row is written.
        if (array_key_exists('max_steps', $agent)) {
            $this->stepCap?->assertValid($agent['max_steps']);
        }

        return Capsule::table('agents')->insertGetId([
            'principal_id'        => $principalId,
            'name'                => $this->resolveName($template),
            'description'         => $this->nullIfEmpty($agent['description'] ?? null),
            'system_prompt'       => $this->nullIfEmpty($agent['system_prompt'] ?? null),
            'notes'               => $this->nullIfEmpty($agent['notes'] ?? null),
            // The cap a new agent carries, bounded by the ceiling.
            'max_steps'           => (int) ($agent['max_steps'] ?? $this->stepCap?->defaultCap() ?? AgentStepCap::DEFAULT_CAP),
            'default_effort'      => $this->nullIfEmpty($agent['default_effort'] ?? null),
            'allow_followup'      => $allowFollowup ? 1 : 0,
            'retry_after_minutes' => (int) ($agent['retry_after_minutes'] ?? 0),
            'max_retries'         => (int) ($agent['max_retries'] ?? 0),
            'is_active'           => 1,
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);
    }

    private function resolveName(AgentTemplate $template): string
    {
        $name = $template->name();
        return $name !== '' ? $name : $template->id();
    }

    private function nullIfEmpty(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }
}
