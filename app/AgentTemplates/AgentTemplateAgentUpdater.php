<?php

declare(strict_types=1);

namespace Spora\AgentTemplates;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Http\Exceptions\AgentPatchValidationException;
use Spora\Services\AgentStepCap;

/**
 * Writes the agent columns a template names, and only those.
 *
 * The write-only twin of {@see AgentTemplateAgentCreator}: a create fills
 * every column from the payload, an update fills the columns the payload
 * mentions and leaves the rest of the agent alone. A caller that sends
 * `{"agent": {"system_prompt": "…"}}` changes the prompt and nothing else
 * — it cannot destroy configuration it did not send.
 */
final class AgentTemplateAgentUpdater
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly ?AgentStepCap $stepCap = null,
    ) {}

    /**
     * The columns this updater writes. The `agents` table has more, and
     * they are not the template's to set: ownership (`principal_id`),
     * activation and the timestamps belong to the surrounding flow.
     *
     * @throws AgentPatchValidationException when the template names a cap this installation does not allow
     */
    public function update(int $agentId, AgentTemplate $template): void
    {
        $updates = $this->updates($template);

        if ($updates === []) {
            return;
        }

        $updates['updated_at'] = date(self::DATETIME_FORMAT);

        Capsule::table('agents')->where('id', $agentId)->update($updates);
    }

    /**
     * @return array<string, mixed>
     */
    private function updates(AgentTemplate $template): array
    {
        $agent = $template->agent();
        $updates = [];

        $name = $template->name();
        if ($name !== '') {
            $updates['name'] = $name;
        }

        if (array_key_exists('description', $agent)) {
            $updates['description'] = $this->nullIfEmpty($agent['description']);
        }

        if (array_key_exists('system_prompt', $agent)) {
            $updates['system_prompt'] = $this->nullIfEmpty($agent['system_prompt']);
        }

        if (array_key_exists('notes', $agent)) {
            $updates['notes'] = $this->nullIfEmpty($agent['notes']);
        }

        if (array_key_exists('max_steps', $agent)) {
            // The same refusal the create path makes: a template on disk
            // is judged by the instance's ceiling, not by the scanner that
            // read it.
            $this->stepCap?->assertValid($agent['max_steps']);
            $updates['max_steps'] = (int) $agent['max_steps'];
        }

        if (array_key_exists('default_effort', $agent)) {
            $updates['default_effort'] = $this->nullIfEmpty($agent['default_effort']);
        }

        if (array_key_exists('allow_followup', $agent)) {
            $updates['allow_followup'] = (bool) $agent['allow_followup'] ? 1 : 0;
        }

        if (array_key_exists('retry_after_minutes', $agent)) {
            $updates['retry_after_minutes'] = (int) $agent['retry_after_minutes'];
        }

        if (array_key_exists('max_retries', $agent)) {
            $updates['max_retries'] = (int) $agent['max_retries'];
        }

        return $updates;
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
