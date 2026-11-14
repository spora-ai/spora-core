<?php

declare(strict_types=1);

namespace Spora\Drivers\OpenAI;

use Spora\Drivers\EffortLevel;
use Spora\Drivers\ModelFamily;
use Spora\Drivers\ValueObjects\LLMRequest;

/**
 * What the model family means for a Chat Completions request.
 *
 * {@see ModelFamily} answers which family a model belongs to; the
 * decisions that follow from the answer are made here — the role the
 * system prompt travels under, the token parameter a reasoning model
 * accepts, and the effort the request carries.
 *
 * Split out of {@see \Spora\Drivers\OpenAICompatibleDriver} so the
 * Chat Completions-specific rules live next to the request shape they
 * govern and the driver class stays focused on transport.
 */
final class ChatCompletionsReasoning
{
    public function __construct(
        private readonly string $model,
    ) {}

    /**
     * The role the system prompt travels under.
     *
     * `developer` replaced `system` from o1 onwards; older models reject
     * the role outright, so it is only used for the reasoning families.
     */
    public function systemRole(): string
    {
        return ModelFamily::isOpenAIReasoningModel($this->model) ? 'developer' : 'system';
    }

    /**
     * The token ceiling and the sampling parameters of the request.
     *
     * Reasoning models answer `400 Unsupported parameter: 'max_tokens'`
     * and reject the sampling parameters outright.
     *
     * @return array<string, mixed>
     */
    public function tokenParameters(LLMRequest $request): array
    {
        if (ModelFamily::isOpenAIReasoningModel($this->model)) {
            return ['max_completion_tokens' => $request->maxTokens];
        }

        return [
            'max_tokens' => $request->maxTokens,
            'temperature' => $request->temperature,
        ];
    }

    /**
     * The `reasoning_effort` value for the request, or null when the
     * request carries none and none is required.
     *
     * @param array<string, string> $levels the canonical level => the provider's value
     */
    public function effort(LLMRequest $request, array $levels): ?string
    {
        $effort = $request->effort === null ? null : ($levels[$request->effort] ?? null);

        if ($effort !== null) {
            return $effort;
        }

        // Tools on a GPT-5.4+ model are only accepted at `none`, and from
        // GPT-5.5 on the model's own default is `medium` — so silence is a
        // 400 too. GPT-5.4 and GPT-5.2 default to `none`, and the escape
        // hatch is a no-op there. A model whose tools need the Responses
        // API is refused either way.
        //
        // The escape hatch is what the API requires, not a depth the
        // operator chose, so it is not subject to their level list: a
        // configuration naming `low, medium, high` without `none` would
        // otherwise leave no value the request could carry, and the
        // provider would refuse it.
        if ($request->tools !== [] && ModelFamily::openAIRequiresNoneEffortForTools($this->model)) {
            return EffortLevel::None->value;
        }

        return null;
    }
}
