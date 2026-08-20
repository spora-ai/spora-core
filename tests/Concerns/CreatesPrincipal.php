<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Database\Capsule\Manager as Capsule;
use PDOException;
use RuntimeException;

/**
 * Test-time helpers for materialising user-principals and the agents /
 * settings rows that key off `principal_id`.
 *
 * Background: migration 0067 cut `agents.user_id`, `llm_driver_configurations.user_id`,
 * `tool_user_settings.user_id` and `user_preferences.user_id` and re-keyed them
 * on `principal_id` (FK → `principals.id`). Many test fixtures still build rows
 * via `Capsule::table('agents')->insertGetId([... 'principal_id' => $this->createUserPrincipal($u) ...])` and
 * then hit a NOT NULL constraint on `principal_id` because the user-principal
 * row was never materialised.
 *
 * Use {@see self::createUserPrincipal()} to materialise the user-principal,
 * then {@see self::principalIdFor()} (or the inline `Capsule::table('principals')
 * ->where(...)->value('id')` pattern) when a test wants the id without the
 * create path.
 */
trait CreatesPrincipal
{
    /**
     * Return the user-principal id for $userId, materialising the row on
     * demand. Idempotent — calling repeatedly with the same $userId is
     * safe and cheap.
     */
    protected function createUserPrincipal(int $userId): int
    {
        $existing = Capsule::table('principals')
            ->where('type', 'user')->where('user_id', $userId)->value('id');
        if ($existing !== null) {
            return (int) $existing;
        }

        try {
            return (int) Capsule::table('principals')->insertGetId([
                'type'       => 'user',
                'user_id'    => $userId,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (PDOException) {
            // Lost the race to a parallel insert. Re-read.
            $existing = Capsule::table('principals')
                ->where('type', 'user')->where('user_id', $userId)->value('id');
            if ($existing !== null) {
                return (int) $existing;
            }
            throw new RuntimeException("Failed to materialise user-principal for user {$userId}");
        }
    }

    /**
     * Insert an `agents` row with `principal_id` populated from the
     * materialised user-principal. Merges the caller-provided columns
     * on top of the default ones so callers only have to think about
     * what they're actually testing.
     *
     * @param  array<string, mixed> $data
     */
    protected function makeAgentWithPrincipal(array $data, int $userId): int
    {
        $principalId = $this->createUserPrincipal($userId);
        $data['principal_id'] = $principalId;

        return (int) Capsule::table('agents')->insertGetId($data + [
            'name'       => 'Test Agent',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Insert an `llm_driver_configurations` row owned by the user-principal.
     * The XOR invariant (`principal_id` set ⇔ `is_global = false`) is
     * enforced by the model so we set `is_global` explicitly when the test
     * expects a global config.
     *
     * @param  array<string, mixed> $data
     */
    protected function makeLlmConfigWithPrincipal(array $data, int $userId): int
    {
        $data['principal_id'] = $this->createUserPrincipal($userId);
        $data['is_global'] ??= false;

        return (int) Capsule::table('llm_driver_configurations')->insertGetId($data + [
            'name'        => 'Test LLM Config',
            'driver_class' => 'Spora\Drivers\MockDriver',
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Return the user-principal id without forcing the create path. Returns
     * null if no user-principal row exists yet — useful for tests that want
     * to assert "no principal materialised".
     */
    protected function principalIdFor(int $userId): ?int
    {
        $id = Capsule::table('principals')
            ->where('type', 'user')->where('user_id', $userId)->value('id');

        return $id !== null ? (int) $id : null;
    }
}
