<?php

declare(strict_types=1);

/**
 * The `allowed_skills` read gate over the *real* `ToolConfigService`.
 *
 * `SkillToolTest` mocks `getEffectiveSettings` to hand the tool a native array,
 * which is the shape the tests wanted and not the shape production stores: the
 * override endpoint takes `allowed_skills` JSON-encoded, because the settings blob
 * is itself JSON and a nested array is not something the panel can put inside it.
 * This uses the real service so the stored form and the read form meet somewhere,
 * which is where the read gate and the agent's own configuration could otherwise
 * disagree.
 */

use Monolog\Logger;
use Spora\Core\SecurityManager;
use Spora\Services\PrincipalResolver;
use Spora\Services\ToolConfigService;
use Spora\Skills\Providers\FilesystemSkillProvider;
use Spora\Skills\SkillProviderRegistry;
use Spora\Skills\SkillScanner;
use Spora\Tools\SkillTool;

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
function makeRealConfigSkillTool(string $slug): array
{
    [$root, $cleanup] = writeRealConfigSkill($slug);

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
