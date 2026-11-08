<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Models\Agent;
use Spora\Models\User;
use Spora\Services\Exceptions\PromptExpansionTooLargeException;

/**
 * Expands `{{placeholder}}` templates into a scheduled prompt.
 *
 * One implementation for the manual trigger and the worker, so both apply
 * the same ceiling. A template may name the same placeholder thousands of
 * times and each occurrence copies the stored default in full, so the
 * expanded string grows as the product of the two — a stored template and
 * default totalling a couple of hundred kilobytes would otherwise
 * allocate gigabytes in the request process before any task limit applies.
 */
final class PromptTemplateExpander
{
    /**
     * Ceiling on the expanded prompt.
     *
     * A scheduled prompt is a task prompt, not a document; the legitimate
     * ones are orders of magnitude below this.
     */
    public const MAX_EXPANDED_BYTES = 1_048_576;

    /**
     * @param list<array<string, mixed>> $variables The template's stored variables.
     *
     * @throws PromptExpansionTooLargeException
     */
    public function expand(string $template, array $variables, ?Agent $agent = null): string
    {
        if (strlen($template) > self::MAX_EXPANDED_BYTES) {
            throw new PromptExpansionTooLargeException(
                'The expanded prompt exceeds ' . self::MAX_EXPANDED_BYTES . ' bytes.',
            );
        }

        $defaults = $this->defaultsByKey($variables);
        $budget   = self::MAX_EXPANDED_BYTES - strlen($template);

        $expanded = preg_replace_callback(
            '/\{\{(\w+)(?::([^}]*))?\}\}/',
            function (array $matches) use ($defaults, $agent, &$budget): string {
                $replacement = $this->resolve($matches, $defaults, $agent);
                $budget -= strlen($replacement);

                if ($budget < 0) {
                    throw new PromptExpansionTooLargeException(
                        'The expanded prompt exceeds ' . self::MAX_EXPANDED_BYTES . ' bytes.',
                    );
                }

                return $replacement;
            },
            $template,
        );

        return $expanded ?? $template;
    }

    /**
     * @param list<array<string, mixed>> $variables
     * @return array<string, string|null>
     */
    private function defaultsByKey(array $variables): array
    {
        $defaults = [];
        foreach ($variables as $variable) {
            if (isset($variable['key'])) {
                $defaults[(string) $variable['key']] = $variable['default_value'] ?? null;
            }
        }

        return $defaults;
    }

    /**
     * @param array<int, string> $matches
     * @param array<string, string|null> $defaults
     */
    private function resolve(array $matches, array $defaults, ?Agent $agent): string
    {
        $key           = $matches[1];
        $inlineDefault = $matches[2] ?? null;

        $builtin = $this->resolveBuiltin($key);
        if ($builtin !== null) {
            return $builtin;
        }

        if ($agent !== null && ($key === 'agent_name' || $key === 'user_name')) {
            return $key === 'agent_name' ? (string) $agent->name : $this->resolveUserName($agent);
        }

        if (isset($defaults[$key]) && $defaults[$key] !== '') {
            return (string) $defaults[$key];
        }

        return $inlineDefault ?? $matches[0];
    }

    private function resolveBuiltin(string $key): ?string
    {
        return match ($key) {
            'current_date'         => date('Y-m-d'),
            'date'                 => date('Y-m-d'),
            'current_time'         => date('H:i'),
            'time'                 => date('H:i'),
            'current_datetime'     => date('Y-m-d\TH:i'),
            'datetime'             => date('Y-m-d\TH:i'),
            'day_of_week'          => date('l'),
            'day_of_month'         => date('j'),
            'month'                => date('F'),
            'year'                 => date('Y'),
            default                => null,
        };
    }

    private function resolveUserName(Agent $agent): string
    {
        $ownerUserId = (new PrincipalResolver())->ownerUserId((int) $agent->principal_id);
        if ($ownerUserId === null) {
            return 'user_name';
        }

        $user = User::find($ownerUserId);

        return $user instanceof User ? ($user->username ?? 'user_name') : 'user_name';
    }
}
