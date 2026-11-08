<?php

declare(strict_types=1);

namespace Spora\Agents;

/**
 * Renders the "Effective Configuration" block an agent's tool definitions
 * carry.
 *
 * The block tells the model which settings the agent actually runs with,
 * so a setting the operator left unset reads as "(not configured)" rather
 * than as an empty value.
 */
final class LlmConfigBlockRenderer
{
    public function render(array $llmSettings): string
    {
        if ($llmSettings === []) {
            return '';
        }

        $lines = [];
        foreach ($llmSettings as $setting) {
            $lines[] = '- ' . $setting['label'] . ': ' . $this->display($setting['value']);
        }

        return "\n[Effective Configuration]\n" . implode("\n", $lines);
    }

    private function display(mixed $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '(not configured)';
        }

        if (is_array($value)) {
            // list<string> from a multi-select, or a list of agent "Name (#id)" pairs.
            return implode(', ', array_map(static fn($v) => is_scalar($v) ? (string) $v : json_encode($v), $value));
        }

        return (string) $value;
    }
}
