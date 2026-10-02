<?php

declare(strict_types=1);

/**
 * The allowlist round trip through the *real* `ToolConfigService`.
 *
 * `SkillToolTest` mocks `getEffectiveSettings` to hand the tool a native array,
 * which is the shape the tests wanted and not the shape production stores: the
 * override endpoint takes `allowed_skills` JSON-encoded, because the settings blob
 * is itself JSON and a nested array is not something the panel can put inside it.
 * These tests use the real service so the stored form and the read form meet
 * somewhere, which is where the two new operations and the existing read gate
 * could otherwise disagree.
 */

use Monolog\Logger;
use Spora\Core\SecurityManager;
use Spora\Services\PrincipalResolver;
use Spora\Services\ToolConfigService;
use Spora\Skills\Providers\FilesystemSkillProvider;
use Spora\Skills\SkillProviderRegistry;
use Spora\Skills\SkillScanner;
use Spora\Tools\SkillTool;
use Spora\Tools\SkillTool\AgentSkillAllowlist;

/**
 * A skill directory with a SKILL.md, in its own scan root.
 *
 * @return array{0: string, 1: callable(): void} the root and its cleanup
 */
function writeRealConfigSkill(string $slug): array
{
    $root = sys_get_temp_dir() . '/spora_skill_realcfg_' . uniqid('', true);
    mkdir($root . '/' . $slug, 0o755, true);
    file_put_contents(
        $root . '/' . $slug . '/SKILL.md',
        "---\nname: {$slug}\ndescription: A skill used by the allowlist round trip.\n---\n\nBody.\n",
    );

    $cleanup = static function () use ($root): void {
        if (! is_dir($root)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            @is_dir($item->getRealPath()) ? @rmdir($item->getRealPath()) : @unlink($item->getRealPath());
        }
        @rmdir($root);
    };

    return [$root, $cleanup];
}

/**
 * @return array{0: SkillTool, 1: ToolConfigService, 2: int, 3: callable(): void}
 */
function makeRealConfigSkillTool(?string $slug = 'allowlist-skill'): array
{
    [$root, $cleanup] = $slug === null
        ? [sys_get_temp_dir() . '/spora_skill_empty_' . uniqid('', true), static function (): void {}]
        : writeRealConfigSkill($slug);
    if (! is_dir($root)) {
        mkdir($root, 0o755, true);
    }

    $authService = bootAuthLayer();
    $service = new ToolConfigService(
        new SecurityManager(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
        new Logger('test'),
        [],
    );

    $email = 'skillallow' . uniqid('', false) . '@example.com';
    $userId = $authService->register($email, 'Password1!', 'Skill Allow');
    $agentId = Spora\Models\Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name'         => 'Test Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 10,
        'is_active'    => true,
    ])->id;

    $tool = new SkillTool(
        new SkillProviderRegistry([new FilesystemSkillProvider(new SkillScanner([
            ['path' => $root, 'source' => 'project'],
        ]))]),
        $service,
        new PrincipalResolver(),
        new AgentSkillAllowlist($service),
    );

    return [$tool, $service, $agentId, $cleanup];
}

test('the stored allowlist is a JSON string, and a read still works', function (): void {
    [$tool, $service, $agentId, $cleanup] = makeRealConfigSkillTool('round-trip-skill');
    try {
        // Exactly what `PUT /agents/{id}/tools/skill/override` stores, and what the
        // panel's "Enable on agent…" sends.
        $service->putAgentOverride(SkillTool::class, $agentId, [
            'allowed_skills' => json_encode(['round-trip-skill']),
        ]);

        expect($service->getRawAgentOverride(SkillTool::class, $agentId)['allowed_skills'])
            ->toBeString();

        $result = $tool->execute(['action' => 'read', 'name' => 'round-trip-skill'], $agentId);

        expect($result->success)->toBeTrue()
            ->and($result->content)->toContain('Body.');
    } finally {
        $cleanup();
    }
});

test('list reports a stored skill as active, from the same stored form', function (): void {
    [$tool, $service, $agentId, $cleanup] = makeRealConfigSkillTool('listed-skill');
    try {
        $service->putAgentOverride(SkillTool::class, $agentId, [
            'allowed_skills' => json_encode(['listed-skill']),
        ]);

        $result = $tool->execute(['action' => 'list'], $agentId);
        $row = null;
        foreach ($result->data['skills'] as $candidate) {
            if ($candidate['name'] === 'listed-skill') {
                $row = $candidate;
            }
        }

        expect($result->success)->toBeTrue()
            ->and($row)->not->toBeNull()
            ->and($row['active'])->toBeTrue();
    } finally {
        $cleanup();
    }
});

test('activate appends to a stored allowlist instead of replacing it', function (): void {
    [$tool, $service, $agentId, $cleanup] = makeRealConfigSkillTool('first-skill');
    try {
        $service->putAgentOverride(SkillTool::class, $agentId, [
            'allowed_skills' => json_encode(['something-else']),
        ]);

        $result = $tool->execute(['action' => 'activate', 'name' => 'first-skill'], $agentId);

        expect($result->success)->toBeTrue()
            ->and($result->data['allowed_skills'])->toBe(['something-else', 'first-skill']);

        // The pre-existing entry survived, which is why the write goes through the
        // service as a merge rather than replacing the row: one override row holds
        // every setting for the tool.
        $after = json_decode(
            $service->getRawAgentOverride(SkillTool::class, $agentId)['allowed_skills'],
            true,
        );
        expect($after)->toBe(['something-else', 'first-skill']);
    } finally {
        $cleanup();
    }
});

test('activate is idempotent, and does not duplicate an entry', function (): void {
    [$tool, , $agentId, $cleanup] = makeRealConfigSkillTool('twice-skill');
    try {
        $tool->execute(['action' => 'activate', 'name' => 'twice-skill'], $agentId);
        $second = $tool->execute(['action' => 'activate', 'name' => 'twice-skill'], $agentId);

        expect($second->success)->toBeTrue()
            ->and($second->data['changed'])->toBeFalse()
            ->and($second->data['allowed_skills'])->toBe(['twice-skill']);
    } finally {
        $cleanup();
    }
});

test('activate then read works, which is the whole point of the pair', function (): void {
    [$tool, , $agentId, $cleanup] = makeRealConfigSkillTool('pair-skill');
    try {
        $refused = $tool->execute(['action' => 'read', 'name' => 'pair-skill'], $agentId);
        expect($refused->success)->toBeFalse()
            ->and($refused->content)->toContain('not in the allowed_skills list');

        $tool->execute(['action' => 'activate', 'name' => 'pair-skill'], $agentId);

        expect($tool->execute(['action' => 'read', 'name' => 'pair-skill'], $agentId)->success)->toBeTrue();
    } finally {
        $cleanup();
    }
});

test('activate refuses a name the principal cannot see', function (): void {
    [$tool, $service, $agentId, $cleanup] = makeRealConfigSkillTool('visible-skill');
    try {
        $result = $tool->execute(['action' => 'activate', 'name' => 'not-a-real-skill'], $agentId);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('not available to this principal');

        // Nothing written, so a later read still fails the allowlist gate. Activating
        // must not become a way to pre-approve a name that resolves to nothing.
        expect($service->getRawAgentOverride(SkillTool::class, $agentId))->toBe([]);
    } finally {
        $cleanup();
    }
});

test('list is empty rather than a failure when the principal has no skills', function (): void {
    [$tool, , $agentId, $cleanup] = makeRealConfigSkillTool(null);
    try {
        $result = $tool->execute(['action' => 'list'], $agentId);

        expect($result->success)->toBeTrue()
            ->and($result->data['skills'])->toBe([]);
    } finally {
        $cleanup();
    }
});
