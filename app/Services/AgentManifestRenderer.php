<?php

declare(strict_types=1);

namespace Spora\Services;

/**
 * Renders the canonical agent manifest ({@see AgentManifest::toArray()})
 * as Markdown for the `result_content` returned to LLM-facing tool calls.
 *
 * The audit-log `result_data` carries the same fields as structured JSON.
 * The Markdown wrapper exists so the LLM — and a human reading the audit
 * log — get a clean prose preamble followed by the two machine-readable
 * JSON sub-blocks (`base-config` and `tool-config`).
 *
 * Output is deterministic for a given manifest so test fixtures hash
 * cleanly. Newlines are LF; line widths are not enforced.
 */
final class AgentManifestRenderer
{
    /**
     * Render the manifest produced by {@see AgentManifest::toArray()} as
     * Markdown. The leading preamble names the agent + a one-line status,
     * followed by two fenced JSON code blocks:
     *
     * ```
     * ## Agent #6 — Weather Agent
     *
     * 1 of 18 tools enabled. 0 missing required config.
     *
     * ### Base config
     *
     * ```json
     * { ... base-config keys ... }
     * ```
     *
     * ### Tool config
     *
     * ```json
     * { ... tool-config keys ... }
     * ```
     * ```
     *
     * @param array<string, mixed> $manifest Output of AgentManifest::toArray()
     */
    public static function markdown(array $manifest): string
    {
        $agentId   = (int) $manifest['agent_id'];
        $name      = (string) $manifest['name'];
        $tools     = (array) ($manifest['tools'] ?? []);
        $total     = count($tools);
        $enabled   = count(array_filter($tools, static fn(array $t): bool => (bool) $t['enabled']));
        $disabled  = array_values(array_map(
            static fn(array $t): string => (string) $t['tool_class'],
            array_filter($tools, static fn(array $t): bool => !(bool) $t['enabled']),
        ));
        $missing   = count((array) ($manifest['missing_required'] ?? []));

        $header = sprintf(
            "## Agent #%d \u{2014} %s",
            $agentId,
            $name !== '' ? $name : '(unnamed)',
        );

        $statusLine = sprintf(
            '%d of %d tools enabled. %d missing required config.',
            $enabled,
            $total,
            $missing,
        );

        // Operators who want to know which classes are inactive
        // without scanning the trailing tools[] block get the FQCNs
        // enumerated here. Cheap: just short class names, no
        // description or per-op state. Empty list omitted entirely so
        // the all-enabled case stays clean.
        $disabledLine = $disabled === []
            ? null
            : 'Disabled: ' . implode(', ', $disabled);

        $base = [
            'agent_id'            => $agentId,
            'name'                => $manifest['name'] ?? null,
            'description'         => $manifest['description'] ?? null,
            'system_prompt'       => $manifest['system_prompt'] ?? null,
            'notes'               => $manifest['notes'] ?? null,
            'template_id'         => $manifest['template_id'] ?? null,
            'version'             => $manifest['version'] ?? null,
            'max_steps'           => $manifest['max_steps'] ?? null,
            'allow_followup'      => $manifest['allow_followup'] ?? null,
            'retry_after_minutes' => $manifest['retry_after_minutes'] ?? null,
            'max_retries'         => $manifest['max_retries'] ?? null,
            'is_pinned'           => $manifest['is_pinned'] ?? null,
            'is_archived'         => $manifest['is_archived'] ?? null,
            'is_favorite'         => $manifest['is_favorite'] ?? null,
        ];

        $toolBlock = [
            'tools'            => $tools,
            'missing_required' => $manifest['missing_required'] ?? [],
            // Per-op override audit trail — emitted in tool-block since
            // it documents the toolset the operator is reading about.
            'overrides'        => $manifest['overrides'] ?? [],
            'warnings'         => $manifest['warnings'] ?? [],
        ];

        return implode("\n\n", array_filter(
            [
                $header,
                $statusLine,
                $disabledLine,
                "### Base config\n\n```json\n"
                    . self::prettyJson($base)
                    . "\n```",
                "### Tool config\n\n```json\n"
                    . self::prettyJson($toolBlock)
                    . "\n```",
            ],
            static fn(?string $line): bool => $line !== null,
        )) . "\n";
    }

    /**
     * Pretty-print JSON with stable key ordering. Falls back to a
     * `json_encode` with `JSON_PRETTY_PRINT` + `JSON_UNESCAPED_SLASHES`; we
     * do not hand-roll indentation so the test fixtures stay readable.
     *
     * @param array<string, mixed> $data
     */
    private static function prettyJson(array $data): string
    {
        return (string) json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
