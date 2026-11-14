<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Models\Agent;

/**
 * The reasoning effort a scheduled run dispatches with.
 *
 * The save path refuses a level the agent's configuration does not offer,
 * but a configuration can narrow its level list afterwards: the run then
 * holds a level the driver drops on the way to the provider, and the run
 * would happen at the provider's default without a word. The dispatch
 * path re-checks the level against the configuration as it stands now.
 *
 * A level the configuration no longer offers is replaced by the agent's
 * effective default — the same fallback an orphaned agent default gets in
 * {@see EffortService::defaultFor()}. The run fires rather than fails: an
 * unattended schedule that stopped firing because the operator narrowed a
 * level list would do no work at all. The substitution is reported, so the
 * run is never silently at another depth than the one it was saved with.
 */
final class ScheduledRunEffortResolver
{
    /**
     * The line a substitution is recorded with. Both dispatch paths
     * report it, so the wording lives with the rule.
     */
    public const SUBSTITUTION_MESSAGE = 'Scheduled run effort is no longer offered; the run fires at the agent default';

    public function __construct(
        private readonly EffortService $effortService,
        private readonly LLMConfigPreferences $preferences,
    ) {}

    /**
     * The level to dispatch, and the level it replaced.
     *
     * @return array{level: ?string, replaced: ?string} `replaced` is null
     *         when the run dispatches the level it was saved with
     */
    public function resolve(?string $requested, Agent $agent): array
    {
        if ($requested === null || $requested === '') {
            return ['level' => null, 'replaced' => null];
        }

        // Without a configuration there is no level set to compare the
        // level with, and the run cannot run anyway; a level the
        // configuration still offers is dispatched as it stands.
        $stillOffered = $this->preferences->getEffectiveConfigForAgent($agent) === null
            || $this->effortService->levelSetFor($agent)->has($requested);

        if ($stillOffered) {
            return ['level' => $requested, 'replaced' => null];
        }

        return ['level' => $this->effortService->defaultFor($agent), 'replaced' => $requested];
    }
}
