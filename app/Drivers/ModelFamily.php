<?php

declare(strict_types=1);

namespace Spora\Drivers;

/**
 * The one place a model name is interpreted.
 *
 * Both drivers ask it a question instead of re-implementing the parse:
 * the Anthropic driver asks which thinking mode the model has and
 * whether it takes images, the OpenAI driver asks whether the model is a
 * reasoning model. A new model family is a change here and nowhere else.
 */
final class ModelFamily
{
    /**
     * OpenAI's reasoning families.
     *
     * `gpt-5`, `gpt-5.1`, `gpt-6`, `gpt-6.1` and their variants, plus
     * the o-series (`o1`, `o3`, `o4`). The `-chat-latest` ids are the
     * non-reasoning ChatGPT Instant models and are excluded by name.
     */
    public static function isOpenAIReasoningModel(string $model): bool
    {
        $id = self::stripProviderPrefix($model);

        if (str_contains($id, '-chat-')) {
            return false;
        }

        if (str_starts_with($id, 'gpt-oss')) {
            return false;
        }

        if (preg_match('/^gpt-(5|6)(\.\d+)?(-|$)/', $id) === 1) {
            return true;
        }

        return preg_match('/^o(1|3|4)(-|$)/', $id) === 1;
    }

    /**
     * Whether the model only accepts tool calls at `reasoning_effort: none`.
     *
     * From GPT-5.4 on, Chat Completions rejects a request that carries
     * tools and an effort other than `none` — and the model's own default
     * is `medium`, so silence is a 400 too. The escape hatch has to be
     * sent explicitly.
     *
     * The o-series predates the restriction and does not accept `none`
     * at all, so the rule is scoped to the GPT-5.4+ generation.
     */
    public static function openAIRequiresNoneEffortForTools(string $model): bool
    {
        if (!self::isOpenAIReasoningModel($model)) {
            return false;
        }

        $version = self::openAIGenerationVersion($model);
        if ($version === null) {
            return false;
        }

        return $version[0] > 5 || ($version[0] === 5 && $version[1] >= 4);
    }

    /**
     * Whether the model accepts a `configuration_update` input item.
     *
     * The item changes the reasoning effort without rewriting the
     * request-level `reasoning.effort`, so the cached prompt prefix
     * survives. OpenAI documents it for GPT-6 and later; on an earlier
     * model the same change is a prefix edit that costs the cache.
     */
    public static function openAISupportsConfigurationUpdate(string $model): bool
    {
        $version = self::openAIGenerationVersion($model);
        if ($version === null) {
            return false;
        }

        return $version[0] >= 6;
    }

    /**
     * Whether the model has an adaptive thinking mode.
     *
     * Adaptive thinking arrived with the 4.6 generation; below it the
     * only thinking mode is the manual `budget_tokens` one, which this
     * driver no longer sends.
     */
    public static function claudeSupportsAdaptiveThinking(string $model): bool
    {
        $id = self::stripVendorAffixes($model);
        if (!str_starts_with($id, 'claude-')) {
            return false;
        }

        $version = self::claudeVersion($id);
        if ($version === null) {
            // No numeric component at all: the id carries no version, so
            // it belongs to the current generation (`claude-mythos-preview`).
            return true;
        }

        return $version[0] > 4 || ($version[0] === 4 && $version[1] >= 6);
    }

    /**
     * Whether the model accepts image content blocks.
     *
     * Every Claude generation from 3 onwards does; Claude 2 and the
     * Instant models do not. A name that is not a Claude id is not
     * answered for — an Anthropic-compatible proxy may name its models
     * anything at all.
     */
    public static function claudeSupportsImageInput(string $model): bool
    {
        $id = self::stripVendorAffixes($model);
        if (!str_starts_with($id, 'claude-')) {
            return false;
        }

        $version = self::claudeVersion($id);
        if ($version === null) {
            return true;
        }

        return $version[0] >= 3;
    }

    /**
     * The Claude version carried by a model id.
     *
     * The numeric segments are collected in order and an 8-digit segment
     * is a snapshot date, not a version — `claude-3-haiku-20240307` is
     * 3.0, not 3.20240307. The capability questions above are what a
     * driver should ask; this answers "which version is it?".
     *
     * @return array{0: int, 1: int}|null null when the id carries no version
     */
    public static function claudeVersion(string $model): ?array
    {
        $segments = preg_split('/[-.]/', self::stripVendorAffixes($model));
        if ($segments === false) {
            return null;
        }

        $numbers = [];
        foreach ($segments as $segment) {
            if ($segment === '' || !ctype_digit($segment)) {
                continue;
            }
            if (strlen($segment) === 8) {
                continue;
            }
            $numbers[] = (int) $segment;
        }

        if ($numbers === []) {
            return null;
        }

        return [$numbers[0], $numbers[1] ?? 0];
    }

    /**
     * The generation of a `gpt-<major>` id.
     *
     * @return array{0: int, 1: int}|null null for every id that is not a GPT model
     */
    private static function openAIGenerationVersion(string $model): ?array
    {
        $id = self::stripProviderPrefix($model);
        if (preg_match('/^gpt-(\d+)(?:\.(\d+))?(?:-|$)/', $id, $matches) !== 1) {
            return null;
        }

        return [(int) $matches[1], (int) ($matches[2] ?? 0)];
    }

    /**
     * Drop the vendor affixes the hosted Claude ids carry.
     *
     * Bedrock, Vertex and Google Cloud prefix the model (`anthropic.`,
     * `us.anthropic.`), suffix it (`-v1`, `-v1:0`) or date it
     * (`@20251001`).
     */
    private static function stripVendorAffixes(string $model): string
    {
        $id = strtolower(trim($model));
        $id = (string) preg_replace('/^(?:[a-z]{2,4}\.)?anthropic\./', '', $id);
        $id = (string) preg_replace('/-v\d+(?::\d+)?$/', '', $id);
        $id = (string) preg_replace('/@\d{8}$/', '', $id);

        return $id;
    }

    /**
     * Drop the routing prefix a gateway adds to the model id.
     *
     * OpenRouter addresses a model as `openai/gpt-5.5`.
     */
    private static function stripProviderPrefix(string $model): string
    {
        $id = strtolower(trim($model));
        $slash = strrpos($id, '/');
        if ($slash !== false) {
            $id = substr($id, $slash + 1);
        }

        return $id;
    }
}
