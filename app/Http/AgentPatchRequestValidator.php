<?php

declare(strict_types=1);

namespace Spora\Http;

use Spora\Http\Exceptions\AgentPatchValidationException;
use Spora\Services\AgentStepCap;

/**
 * The agent patch's own fields.
 *
 * The step cap, which the instance's ceiling bounds, and the per-agent
 * ceiling on temp media rows. Both are the patch's own bounds, so they
 * live here rather than in the controller: the controller stays a thin
 * HTTP layer, and the rules stay in one place.
 *
 * A refusal is thrown, not returned: the message reaches the operator
 * through the error envelope, and a message that only ever existed as a
 * return value would be untranslatable.
 */
final class AgentPatchRequestValidator
{
    /**
     * Refuse a patch the agent's own bounds do not allow.
     *
     * @param array<string, mixed> $data
     * @throws AgentPatchValidationException on the first refusal
     */
    public static function validate(array $data, AgentStepCap $stepCap): void
    {
        if (array_key_exists('max_steps', $data)) {
            $stepCap->assertValid($data['max_steps']);
        }

        self::assertVoiceRetentionWithinRange($data);
    }

    /**
     * @param array<string, mixed> $data
     * @throws AgentPatchValidationException
     */
    private static function assertVoiceRetentionWithinRange(array $data): void
    {
        if (!array_key_exists('voice_message_retention_count', $data)) {
            return;
        }

        $value = $data['voice_message_retention_count'];

        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new AgentPatchValidationException(
                'voice_message_retention_count must be an integer between 0 and 100.',
            );
        }

        $intValue = (int) $value;

        if ($intValue < 0 || $intValue > 100) {
            throw new AgentPatchValidationException(
                'voice_message_retention_count must be between 0 and 100.',
            );
        }
    }
}
