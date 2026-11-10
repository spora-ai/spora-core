<?php

declare(strict_types=1);

namespace Spora\Metering;

use Psr\Log\LoggerInterface;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\ValueObjects\LLMRequest;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Metering\Exceptions\UsageLimitExceededException;
use Spora\Models\Agent;
use Spora\Models\Task;

/**
 * The one place a provider is called.
 *
 * Both provider call sites come through here — the agent turn
 * ({@see \Spora\Agents\TickPhaseRunner}) and the conversation compaction
 * ({@see \Spora\Agents\ConversationCompactor}) — because the compaction
 * call discards its {@see \Spora\Drivers\ValueObjects\Usage}: a meter had
 * no other way to see those tokens.
 *
 * The order is the point: the meter is asked **before** the request
 * leaves, and the tokens are recorded **after** it returns. A hard
 * decision stops the request from being sent at all.
 */
final class LlmCallRunner
{
    private readonly MeteringGuard $guard;

    public function __construct(
        private readonly DriverFactory $driverFactory,
        UsageMeterInterface $usageMeter,
        private readonly MeteringContextFactory $contextFactory = new MeteringContextFactory(),
        ?LoggerInterface $logger = null,
    ) {
        $this->guard = new MeteringGuard($usageMeter, $logger);
    }

    /**
     * @throws UsageLimitExceededException  The meter returned a hard decision.
     */
    public function complete(Task $task, Agent $agent, LLMRequest $request): LLMResponse
    {
        $driver = $this->driverFactory->makeFromAgent($agent);

        // Without a meter the request goes out exactly as it did before,
        // and the driver is not even asked for its provider and model.
        if (!$this->guard->isActive()) {
            return $driver->complete($request);
        }

        $context = $this->contextFactory->forTask($task);
        $subject = new LlmSubject($driver->getProviderName(), $driver->getModelName());

        $decision = $this->guard->evaluate($context, $subject);
        if ($decision !== null && $decision->isHard()) {
            throw new UsageLimitExceededException($decision);
        }

        $response = $driver->complete($request);

        $this->guard->recordLlmUsage($context, new LlmUsage(
            usage: $response->usage,
            provider: $subject->provider,
            model: $subject->model,
        ));

        return $response;
    }
}
