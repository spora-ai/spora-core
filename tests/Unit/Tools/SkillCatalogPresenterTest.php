<?php

declare(strict_types=1);

use Spora\Services\PrincipalContext;
use Spora\Services\PrincipalResolver;
use Spora\Services\ToolConfigServiceInterface;
use Spora\Skills\SkillProviderRegistry;
use Spora\Tools\AgentTool\SkillCatalogPresenter;
use Spora\Tools\SkillTool;
use Tests\Fixtures\Skills\StubSkillProvider;

/**
 * The `skills` block of `get_available_tools` — the discovery half of what the
 * `skill` tool's `list` operation used to be.
 *
 * Both halves of it are identity-sensitive. `visible` must be the *executing*
 * principal's listing, and `allowed` must be read through the execution's
 * context rather than the runner's, because a group agent's group-level
 * `allowed_skills` lives under the agent's principal and resolving from the
 * runner instead reports a legitimately-configured skill as absent.
 */
function skillCatalogPresenter(
    ToolConfigServiceInterface $config,
    SkillProviderRegistry $registry,
    ?PrincipalResolver $principals = null,
): SkillCatalogPresenter {
    return new SkillCatalogPresenter($registry, $config, $principals);
}

/**
 * A registry whose skills are each owned by exactly one principal.
 *
 * @param array<string, int> $nameToOwner
 */
function perOwnerSkillRegistry(array $nameToOwner): SkillProviderRegistry
{
    $provider = new StubSkillProvider('custom');
    foreach ($nameToOwner as $name => $owner) {
        $provider->add($name, ['SKILL.md'], 'Body.', "The {$name} skill.", $owner);
    }
    // Non-null is what switches the stub from "always visible" to per-owner.
    $provider->onlyVisibleTo = 0;

    return new SkillProviderRegistry([$provider]);
}

it('reports the agent allowlist and the principal listing as two fields', function (): void {
    $config = Mockery::mock(ToolConfigServiceInterface::class);
    $config->shouldReceive('getEffectiveSettings')->andReturn(['allowed_skills' => ['mine']]);

    $block = skillCatalogPresenter($config, perOwnerSkillRegistry(['mine' => 10]))
        ->present(7, 99, new PrincipalContext(10, 'user', 99, 99));

    expect($block['allowed'])->toBe(['mine'])
        ->and($block['visible'])->toBe([
            ['name' => 'mine', 'description' => 'The mine skill.', 'active' => true],
        ]);
});

it('scopes `visible` to the executing principal, not the runner', function (): void {
    $config = Mockery::mock(ToolConfigServiceInterface::class);
    $config->allows('getEffectiveSettings')->andReturn([]);
    $registry = perOwnerSkillRegistry(['mine' => 10, 'yours' => 11, 'theirs' => 500]);

    $mine = skillCatalogPresenter($config, $registry)->present(7, 99, new PrincipalContext(10, 'user', 99, 77));
    $theirs = skillCatalogPresenter($config, $registry)->present(7, 77, new PrincipalContext(500, 'user', 77, 77));

    // The runner differs from the principal on the first call; the listing
    // follows the principal either way.
    expect(array_column($mine['visible'], 'name'))->toBe(['mine'])
        ->and(array_column($theirs['visible'], 'name'))->toBe(['theirs']);
});

it('reads `allowed` through the execution context, not the runner', function (): void {
    $config = Mockery::mock(ToolConfigServiceInterface::class);
    // The cascade resolves group-level rows per principal, so a read that
    // dropped the context would report a group-inherited list as empty.
    $config->shouldReceive('getEffectiveSettings')
        ->once()
        ->withArgs(function (string $toolClass, int $agentId, ?int $userId, ?PrincipalContext $context): bool {
            return $toolClass === SkillTool::class
                && $agentId === 7
                && $userId === 99
                && $context instanceof PrincipalContext
                && $context->principalId === 10;
        })
        ->andReturn(['allowed_skills' => ['mine']]);

    $block = skillCatalogPresenter($config, perOwnerSkillRegistry(['mine' => 10]))
        ->present(7, 99, new PrincipalContext(10, 'user', 99, 99));

    expect($block['allowed'])->toBe(['mine']);
});

it('reports no skills rather than failing when the principal does not resolve', function (): void {
    $config = Mockery::mock(ToolConfigServiceInterface::class);
    $config->allows('getEffectiveSettings')->andReturn([]);

    $block = skillCatalogPresenter($config, perOwnerSkillRegistry(['mine' => 10]))
        ->present(7, 99, new PrincipalContext(0, 'user', 99, 99));

    // The principalId-0 sentinel resolves nothing, and the block says so
    // rather than implying a skill the agent might reach.
    expect($block)->toBe(['allowed' => [], 'visible' => []]);
});
