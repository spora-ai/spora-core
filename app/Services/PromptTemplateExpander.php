<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Models\Agent;
use Spora\Models\Group;
use Spora\Models\Principal;
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
     * The principal's placeholder values for the expansion in flight.
     *
     * A template may name all four principal placeholders; reading the
     * principal once keeps that one lookup instead of four. Reset at the
     * start of every {@see self::expand()} so a reused expander never
     * answers from the previous expansion's principal.
     *
     * @var array{type: string, name: string, description: string, information: string}|null
     */
    private ?array $resolvedPrincipalValues = null;

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

        $this->resolvedPrincipalValues = null;

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
        $key = $matches[1];

        return $this->resolveBuiltin($key)
            ?? $this->resolveAgentValue($key, $agent)
            ?? $this->resolveStoredDefault($key, $defaults)
            ?? ($matches[2] ?? $matches[0]);
    }

    /**
     * The value an agent-scoped placeholder takes, if it names one.
     */
    private function resolveAgentValue(string $key, ?Agent $agent): ?string
    {
        if ($agent === null) {
            return null;
        }

        return match ($key) {
            'agent_name'            => (string) $agent->name,
            'user_name'             => $this->resolveUserName($agent),
            'principal_type'        => $this->principalValues($agent)['type'],
            'principal_name'        => $this->principalValues($agent)['name'],
            'principal_description' => $this->principalValues($agent)['description'],
            'principal_information' => $this->principalValues($agent)['information'],
            default                 => null,
        };
    }

    /**
     * The principal's placeholder values, read once per expansion.
     *
     * Identity fields fall back rather than going empty — a user's display
     * name is optional, so it falls back to the username and only then to
     * the placeholder name itself, the same sentinel shape `user_name`
     * already uses. Content fields resolve to an empty string instead:
     * "nothing to say" is a legitimate value for prose, and a raw
     * `{{principal_description}}` left in a scheduled prompt is not.
     *
     * @return array{type: string, name: string, description: string, information: string}
     */
    private function principalValues(Agent $agent): array
    {
        if ($this->resolvedPrincipalValues !== null) {
            return $this->resolvedPrincipalValues;
        }

        return $this->resolvedPrincipalValues = $this->readPrincipalValues($agent);
    }

    /**
     * @return array{type: string, name: string, description: string, information: string}
     */
    private function readPrincipalValues(Agent $agent): array
    {
        $principal = Principal::find((int) $agent->principal_id);
        if (!$principal instanceof Principal) {
            return [
                'type'        => 'principal_type',
                'name'        => 'principal_name',
                'description' => '',
                'information' => '',
            ];
        }

        if ($principal->type === Principal::TYPE_GROUP) {
            $group = Group::find((int) $principal->group_id);
            if (!$group instanceof Group) {
                return [
                    'type'        => Principal::TYPE_GROUP,
                    'name'        => 'principal_name',
                    'description' => '',
                    'information' => '',
                ];
            }

            return [
                'type'        => Principal::TYPE_GROUP,
                'name'        => $group->name ?: 'principal_name',
                'description' => $group->description ?? '',
                'information' => $group->information ?? '',
            ];
        }

        $user = User::find((int) $principal->user_id);
        if (!$user instanceof User) {
            return [
                'type'        => Principal::TYPE_USER,
                'name'        => 'principal_name',
                'description' => '',
                'information' => '',
            ];
        }

        return [
            'type'        => Principal::TYPE_USER,
            'name'        => $user->name ?: $user->username ?: 'principal_name',
            'description' => $user->about_me ?? '',
            'information' => $user->about_me ?? '',
        ];
    }

    /**
     * @param array<string, string|null> $defaults
     */
    private function resolveStoredDefault(string $key, array $defaults): ?string
    {
        if (!isset($defaults[$key]) || $defaults[$key] === '') {
            return null;
        }

        return (string) $defaults[$key];
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
