<?php

declare(strict_types=1);

namespace Spora\Tools\AgentTool;

use Spora\Models\Agent;
use Spora\Services\AgentServiceInterface;
use Spora\Services\Text\Utf8Sanitizer;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Notes mutations on an Agent row: `read_notes`, `write_notes`, and
 * `write_notes_overwrite`. `combineNotes` and `humanBytes` are pure
 * helpers used by the write paths.
 */
final class NotesHandler
{
    private const NOTES_SEPARATOR = "\n\n";

    private const APPEND_MODES = ['append', 'prepend'];

    public function __construct(
        private readonly AgentServiceInterface $agentService,
    ) {}

    public function read(int $agentId): ToolResult
    {
        $agent = $this->agentService->getAgentByAgentId($agentId);
        if ($agent === null) {
            return ToolResult::fail(self::agentNotFoundMessage());
        }

        $notes = (string) ($agent->notes ?? '');

        return ToolResult::ok(
            "Notes for agent #{$agentId} ({$this->humanBytes(mb_strlen($notes))}).",
            [
                'notes'  => $notes,
                'length' => mb_strlen($notes),
            ],
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function write(int $agentId, array $arguments, string $mode): ToolResult
    {
        $agent = $this->agentService->getAgentByAgentId($agentId);
        if ($agent === null) {
            return ToolResult::fail(self::agentNotFoundMessage());
        }

        $parsed = $this->parseArgs($arguments, $mode);
        if ($parsed instanceof ToolResult) {
            return $parsed;
        }
        [$content, $mode] = $parsed;

        $existing = (string) ($agent->notes ?? '');
        $combined = $this->combineNotes($existing, $content, $mode);

        // Empty content on append/prepend is a no-op so repeated LLM
        // calls don't pile up separators or drift `updated_at`.
        $isNoop = $combined === $existing;
        if (!$isNoop) {
            $this->agentService->updateAgentByAgentId($agentId, ['notes' => Utf8Sanitizer::scrubString($combined)]);
        }

        $size = $this->humanBytes(mb_strlen($combined));
        $message = $isNoop
            ? "Notes unchanged ({$size})."
            : "Notes updated via {$mode} ({$size}).";

        return ToolResult::ok(
            $message,
            [
                'notes'  => $combined,
                'length' => mb_strlen($combined),
                'mode'   => $mode,
            ],
        );
    }

    /**
     * @param  array<string, mixed>   $arguments
     * @param  string                $defaultMode
     * @return array{0: string, 1: string}|ToolResult
     */
    private function parseArgs(array $arguments, string $defaultMode): array|ToolResult
    {
        if (!array_key_exists('content', $arguments)) {
            return ToolResult::fail('write_notes: content is required.');
        }
        $content = (string) $arguments['content'];

        $resolvedMode = $this->resolveMode($arguments, $defaultMode);
        if ($resolvedMode instanceof ToolResult) {
            return $resolvedMode;
        }

        return [$content, $resolvedMode];
    }

    /** @return string|ToolResult */
    private function resolveMode(array $arguments, string $defaultMode): string|ToolResult
    {
        if ($defaultMode !== 'append' && $defaultMode !== 'prepend') {
            return $defaultMode;
        }
        $requested = (string) ($arguments['mode'] ?? $defaultMode);
        if (!in_array($requested, self::APPEND_MODES, true)) {
            return ToolResult::fail(
                "write_notes: invalid mode '{$requested}'. Allowed: " . implode(', ', self::APPEND_MODES) . '.',
            );
        }
        return $requested;
    }

    /**
     * Concatenate $content with $existing per the chosen mode. The
     * separator is a fixed blank line per product decision — operators
     * see a clean markdown break and the agent cannot choose its own
     * joiner.
     */
    private function combineNotes(string $existing, string $content, string $mode): string
    {
        $separator = self::NOTES_SEPARATOR;
        return match (true) {
            $content === ''                                       => $existing,
            $existing === '' || $mode === 'overwrite'             => $content,
            $mode === 'prepend'                                   => $content . $separator . $existing,
            default                                               => $existing . $separator . $content,
        };
    }

    private function humanBytes(int $length): string
    {
        return $length . ' chars';
    }

    private static function agentNotFoundMessage(): string
    {
        return 'Agent not found.';
    }
}
