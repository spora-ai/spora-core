<?php

declare(strict_types=1);

use Spora\Tools\AgentTool\SlimPayloadValidator;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * The slim `create_agent` payload's default effort.
 *
 * Two states only: null means "inherit", a level name means "run at this
 * level". A number is neither, and it is refused rather than read as
 * "inherit" — the agent would then run at a default nobody asked for.
 *
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function acceptedSlimPayload(array $payload): array
{
    $out = (new SlimPayloadValidator())->validateCreateAgentPayload(['payload' => $payload]);

    if (!is_array($out)) {
        throw new RuntimeException('The slim payload was refused: ' . $out->content);
    }

    return $out;
}

describe('the slim create_agent payload\'s default effort', function (): void {

    it('refuses a default that is not a level name', function (): void {
        $out = (new SlimPayloadValidator())->validateCreateAgentPayload([
            'payload' => ['name' => 'Example', 'default_effort' => 42],
        ]);

        expect($out)->toBeInstanceOf(ToolResult::class)
            ->and($out->success)->toBeFalse()
            ->and($out->content)->toContain('`default_effort` must be a reasoning-effort level name, or null to inherit');
    });

    it('reads a null as inherit', function (): void {
        $accepted = acceptedSlimPayload(['name' => 'Example', 'default_effort' => null]);

        expect($accepted['default_effort'])->toBeNull();
    });

    it('keeps a level name as it was sent', function (): void {
        $accepted = acceptedSlimPayload(['name' => 'Example', 'default_effort' => 'high']);

        expect($accepted['default_effort'])->toBe('high');
    });
});
