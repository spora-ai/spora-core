<?php

declare(strict_types=1);

namespace Spora\Services;

use InvalidArgumentException;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\EffortAwareDriverInterface;
use Spora\Drivers\EffortLevel;
use Spora\Drivers\EffortLevelSet;
use Spora\Drivers\Exceptions\DriverClassNotFoundException;
use Spora\Drivers\Exceptions\LLMConfigDecryptFailedException;
use Spora\Drivers\LLMDriverInterface;
use Spora\Http\Exceptions\EffortValidationException;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;

/**
 * The reasoning-effort capabilities of an agent's effective driver, and the
 * default a run falls back to.
 *
 * The effective configuration is resolved here, on the server, through the
 * same tier resolution the tick uses. The frontend must never re-implement
 * it: a picker that offers levels the agent's driver cannot express sends
 * a request the provider answers with a 400.
 *
 * Two independent things are called "effort", and this class owns both:
 * the *capability* — which levels the configuration offers, read by
 * {@see self::describe()} — and the *default* — which level a run uses when
 * nobody names one, resolved by {@see self::defaultFor()}. The default
 * chain is the agent's own default, then the configuration's, then nothing;
 * the model default is the absence of all three.
 */
final class EffortService
{
    public function __construct(
        private readonly LLMConfigPreferences $preferences,
        private readonly LLMConfigService $llmConfigService,
        private readonly DriverFactory $driverFactory,
    ) {}

    /**
     * What the agent's driver can express, and what it runs with when
     * nobody names a level.
     *
     * @return array{supported: bool, levels: list<array{value: string, label: string}>, default: ?string}
     */
    public function describe(Agent $agent): array
    {
        $config = $this->preferences->getEffectiveConfigForAgent($agent);
        $levels = $this->levelsFor($config);

        if ($levels->isEmpty()) {
            return ['supported' => false, 'levels' => [], 'default' => null];
        }

        return [
            'supported' => true,
            'levels' => $this->labelled($levels->names()),
            'default' => $this->defaultWithin($agent, $config, $levels),
        ];
    }

    /**
     * The level this agent runs with when nobody names one.
     *
     * The agent's own default wins, then the effective configuration's,
     * then nothing — which is the model default. A level the
     * configuration does not offer is never returned: it would be dropped
     * on the way to the provider, and the run would happen at a depth
     * nobody asked for.
     *
     * An agent default orphaned by a configuration change falls back to
     * the configuration's default, the same reconciliation the client
     * performs on its cached selection.
     */
    public function defaultFor(Agent $agent): ?string
    {
        $config = $this->preferences->getEffectiveConfigForAgent($agent);

        return $this->defaultWithin($agent, $config, $this->levelsFor($config));
    }

    /**
     * The levels the agent's effective configuration offers.
     */
    public function levelSetFor(Agent $agent): EffortLevelSet
    {
        return $this->levelsFor($this->preferences->getEffectiveConfigForAgent($agent));
    }

    /**
     * Refuse an effort the agent's driver does not offer.
     *
     * A level the driver cannot express would be dropped on the way to the
     * provider, and the request would run at the model's own default
     * instead of the level the caller asked for. The API tells the client
     * which levels the driver offers, so a level outside that set is
     * refused here rather than silently downgraded.
     *
     * A level the driver *does* offer is not checked any further: the
     * provider validates the level against the model it is talking to.
     *
     * Each refusal names the reason it is refused for: an agent with no
     * configuration is fixed in the agent's LLM settings, a driver that
     * cannot be built in the configuration that names it, a driver with
     * no effort for this model in the model, and a level outside the set
     * the driver declares.
     *
     * A null agent means the caller could not resolve the agent the
     * request is for. There is no level set to check the level against,
     * so the level is left alone here.
     *
     * @throws EffortValidationException
     */
    public function assertLevel(?string $level, ?Agent $agent): void
    {
        if ($level === null || $level === '' || $agent === null) {
            return;
        }

        $config = $this->preferences->getEffectiveConfigForAgent($agent);

        if ($config === null) {
            throw new EffortValidationException(
                sprintf('effort "%s" cannot be sent: this agent has no LLM configuration.', $level),
                EffortValidationException::CODE_UNSUPPORTED,
            );
        }

        $driver = $this->driverFor($config);

        if ($driver === null) {
            throw new EffortValidationException(
                sprintf('effort "%s" cannot be sent: this agent\'s LLM driver could not be resolved.', $level),
                EffortValidationException::CODE_UNSUPPORTED,
            );
        }

        $declared = $this->levelSetOf($driver)->names();

        if (in_array($level, $declared, true)) {
            return;
        }

        if ($declared === []) {
            throw new EffortValidationException(
                sprintf('effort "%s" is not offered by this agent\'s LLM driver: it cannot express a reasoning effort for this model.', $level),
                EffortValidationException::CODE_UNSUPPORTED,
            );
        }

        throw new EffortValidationException(
            sprintf('effort "%s" is not offered by this agent\'s LLM driver. Supported levels: %s.', $level, implode(', ', $declared)),
            EffortValidationException::CODE_UNSUPPORTED,
        );
    }

    /**
     * Refuse a default effort the agent's configuration does not offer.
     *
     * Two states only: null means "inherit", a level name means "run at
     * this level". An empty string is not a state — the column never
     * distinguishes it from null — so it is refused.
     *
     * @throws EffortValidationException
     */
    public function assertAgentDefault(mixed $value, ?Agent $agent): void
    {
        if ($value === null) {
            return;
        }

        if (!is_string($value) || trim($value) === '') {
            throw new EffortValidationException(
                'default_effort must be a reasoning-effort level name, or null to inherit.',
                EffortValidationException::CODE_MALFORMED,
            );
        }

        $this->assertLevel(trim($value), $agent);
    }

    /**
     * The agent's default, then the configuration's, then nothing.
     *
     * @param EffortLevelSet $levels
     */
    private function defaultWithin(Agent $agent, ?LLMDriverConfiguration $config, EffortLevelSet $levels): ?string
    {
        $agentDefault = $agent->default_effort;

        if (is_string($agentDefault) && $agentDefault !== '' && $levels->has($agentDefault)) {
            return $agentDefault;
        }

        $configDefault = $config === null ? null : $this->llmConfigService->defaultEffort($config);

        if ($configDefault !== null && $configDefault !== '' && $levels->has($configDefault)) {
            return $configDefault;
        }

        return null;
    }

    /**
     * The levels a configuration's driver declares, or none.
     *
     * A driver that does not speak effort, and one that speaks it without
     * naming a level, both leave the agent without a picker.
     */
    private function levelsFor(?LLMDriverConfiguration $config): EffortLevelSet
    {
        if ($config === null) {
            return EffortLevelSet::fromNames([]);
        }

        return $this->levelSetOf($this->driverFor($config));
    }

    /**
     * The levels a driver declares, as a set.
     */
    private function levelSetOf(?LLMDriverInterface $driver): EffortLevelSet
    {
        if ($driver === null) {
            return EffortLevelSet::fromNames([]);
        }

        try {
            return EffortLevelSet::fromNames($this->levelsOf($driver));
        } catch (InvalidArgumentException) {
            // A driver that declares a level name outside the bounds is
            // broken: the name could not survive the trip into a request
            // body. It gets no picker rather than a failed request.
            return EffortLevelSet::fromNames([]);
        }
    }

    /**
     * The levels a driver declares.
     *
     * @return list<string>
     */
    private function levelsOf(LLMDriverInterface $driver): array
    {
        if (!$driver instanceof EffortAwareDriverInterface) {
            return [];
        }

        return array_keys($driver->effortLevels());
    }

    /**
     * The driver of a configuration, or null when it cannot be built.
     *
     * A configuration that names a driver class nobody ships, whose
     * settings cannot be decrypted, or whose stored level list is
     * malformed cannot express an effort — the agent cannot run with it
     * either.
     */
    private function driverFor(LLMDriverConfiguration $config): ?LLMDriverInterface
    {
        try {
            return $this->driverFactory->makeDriverFromConfig($config);
        } catch (DriverClassNotFoundException | LLMConfigDecryptFailedException | InvalidArgumentException) {
            return null;
        }
    }

    /**
     * The levels with a readable label.
     *
     * The label comes from the canonical vocabulary; a name the vocabulary
     * does not know shows as itself, so an operator's own level reads the
     * way it is written.
     *
     * @param  list<string> $levels
     * @return list<array{value: string, label: string}>
     */
    private function labelled(array $levels): array
    {
        $labelled = [];
        foreach ($levels as $level) {
            $labelled[] = [
                'value' => $level,
                'label' => EffortLevel::labelFor($level),
            ];
        }

        return $labelled;
    }
}
