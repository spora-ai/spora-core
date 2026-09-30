<?php

declare(strict_types=1);

use Spora\Agents\ToolDefinitionBuilder;
use Spora\Core\SecurityManager;
use Spora\Models\Agent;
use Spora\Services\PrincipalContext;
use Spora\Services\PrincipalResolver;
use Spora\Services\ToolConfigSchemaInspector;
use Spora\Services\ToolConfigService;
use Spora\Skills\SkillProviderRegistry;
use Spora\Tools\SkillTool;
use Tests\Fixtures\Skills\StubSkillProvider;

defined('SKILL_PROJ_PASSWORD') || define('SKILL_PROJ_PASSWORD', 'Password1!');

/**
 * The wiring test the plan originally lacked, and the only place the A3
 * failure is visible: a skill the agent is *authorised* to use, that the model
 * is never *told* about.
 *
 * It has to run through `ToolDefinitionBuilder` rather than the inspector,
 * because the bug was never in the inspector — it was in what the builder
 * passed it. `ToolConfigService::getLlmToolSettings()` accepted a
 * `PrincipalContext` and dropped it, and the inspector had no parameter to
 * receive. A unit test on either class alone would have stayed green.
 */
function skillProjectionService(SkillProviderRegistry $registry): ToolConfigService
{
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));

    return new ToolConfigService(
        $security,
        new Monolog\Logger('test'),
        [SkillTool::class],
        new ToolConfigSchemaInspector($registry, new PrincipalResolver()),
    );
}

function skillProjectionAgent(string $email, int $principalId): int
{
    bootAuthLayer();
    $userId = bootAuth(bootAuthLayer(), $email, SKILL_PROJ_PASSWORD);

    return Agent::create([
        'principal_id' => $principalId,
        'name'         => 'Projection Agent',
        'max_steps'    => 5,
        'is_active'    => true,
    ])->id;
}

function skillProjectionText(ToolConfigService $service, int $agentId, ?PrincipalContext $context = null): string
{
    $builder = new ToolDefinitionBuilder([new SkillTool(
        new SkillProviderRegistry(),
        $service,
        new PrincipalResolver(),
    )], $service, null, static fn(array $settings): string => json_encode($settings));

    $defs = $builder->buildToolDefinitions([SkillTool::class], $agentId, $context);

    return (string) json_encode($defs);
}

it('a principal-scoped skill reaches the emitted tool definition', function (): void {
    bootAuthLayer();
    $userId = bootAuth(bootAuthLayer(), 'proj-visible@example.com', SKILL_PROJ_PASSWORD);
    $principalId = createUserPrincipalPublic($userId);
    $agentId = skillProjectionAgent('proj-visible-agent@example.com', $principalId);

    $provider = (new StubSkillProvider('custom'))->add('my-skill', [], 'Body.', 'My own skill.', $principalId);
    $provider->onlyVisibleTo = $principalId;
    $service = skillProjectionService(new SkillProviderRegistry([$provider]));
    $service->putAgentOverride(SkillTool::class, $agentId, ['allowed_skills' => ['my-skill']]);

    $text = skillProjectionText($service, $agentId, new PrincipalContext($principalId, 'user', $userId, $userId));

    expect($text)->toContain('my-skill: My own skill.');
});

it('another principal\'s skill does not reach the emitted tool definition', function (): void {
    bootAuthLayer();
    $userId = bootAuth(bootAuthLayer(), 'proj-hidden@example.com', SKILL_PROJ_PASSWORD);
    $principalId = createUserPrincipalPublic($userId);
    $agentId = skillProjectionAgent('proj-hidden-agent@example.com', $principalId);

    $provider = (new StubSkillProvider('custom'))->add('their-skill', [], 'Body.', 'Somebody else\'s skill.');
    $provider->onlyVisibleTo = $principalId + 500;
    $service = skillProjectionService(new SkillProviderRegistry([$provider]));
    $service->putAgentOverride(SkillTool::class, $agentId, ['allowed_skills' => ['their-skill']]);

    $text = skillProjectionText($service, $agentId, new PrincipalContext($principalId, 'user', $userId, $userId));

    // The name is in the agent's own configuration, so seeing it is not a leak;
    // what must not appear is the foreign *description*, which is
    // provider-supplied content the caller never wrote.
    expect($text)->not->toContain("Somebody else's skill.");
});

it('a null context resolves no principal-scoped skill', function (): void {
    // Operator-default and template-preview paths call this with no principal
    // in scope. Widening there would put one tenant's skills in another's
    // preview, so the projection must fail closed.
    bootAuthLayer();
    $userId = bootAuth(bootAuthLayer(), 'proj-null@example.com', SKILL_PROJ_PASSWORD);
    $principalId = createUserPrincipalPublic($userId);
    $agentId = skillProjectionAgent('proj-null-agent@example.com', $principalId);

    $provider = (new StubSkillProvider('custom'))->add('my-skill', [], 'Body.', 'Private notes.', $principalId);
    $provider->onlyVisibleTo = $principalId;
    $service = skillProjectionService(new SkillProviderRegistry([$provider]));
    $service->putAgentOverride(SkillTool::class, $agentId, ['allowed_skills' => ['my-skill']]);

    $text = skillProjectionText($service, $agentId, null);

    expect($text)->not->toContain('Private notes.');
});

it('the principalId-0 sentinel resolves no principal-scoped skill', function (): void {
    bootAuthLayer();
    $userId = bootAuth(bootAuthLayer(), 'proj-zero@example.com', SKILL_PROJ_PASSWORD);
    $principalId = createUserPrincipalPublic($userId);
    $agentId = skillProjectionAgent('proj-zero-agent@example.com', $principalId);

    $provider = (new StubSkillProvider('custom'))->add('my-skill', [], 'Body.', 'Private notes.', $principalId);
    $provider->onlyVisibleTo = $principalId;
    $service = skillProjectionService(new SkillProviderRegistry([$provider]));
    $service->putAgentOverride(SkillTool::class, $agentId, ['allowed_skills' => ['my-skill']]);

    $text = skillProjectionText($service, $agentId, new PrincipalContext(0, 'user', $userId, $userId));

    expect($text)->not->toContain('Private notes.');
});

it('a shipped skill still resolves with no context at all', function (): void {
    // Principal-independence is the filesystem provider's contract; failing
    // closed on a null context must not take the shipped skills with it.
    bootAuthLayer();
    $userId = bootAuth(bootAuthLayer(), 'proj-shipped@example.com', SKILL_PROJ_PASSWORD);
    $principalId = createUserPrincipalPublic($userId);
    $agentId = skillProjectionAgent('proj-shipped-agent@example.com', $principalId);

    $service = skillProjectionService(new SkillProviderRegistry([
        new Spora\Skills\Providers\FilesystemSkillProvider(
            new Spora\Skills\SkillScanner([['path' => BASE_PATH . '/skills', 'source' => 'core']]),
        ),
    ]));
    $service->putAgentOverride(SkillTool::class, $agentId, ['allowed_skills' => ['time-arithmetic']]);

    $text = skillProjectionText($service, $agentId, null);

    expect($text)->toContain('time-arithmetic');
});

it('an allowlisted name with no matching skill is annotated, not dropped', function (): void {
    bootAuthLayer();
    $userId = bootAuth(bootAuthLayer(), 'proj-stale@example.com', SKILL_PROJ_PASSWORD);
    $principalId = createUserPrincipalPublic($userId);
    $agentId = skillProjectionAgent('proj-stale-agent@example.com', $principalId);

    $service = skillProjectionService(new SkillProviderRegistry([
        new Spora\Skills\Providers\FilesystemSkillProvider(
            new Spora\Skills\SkillScanner([['path' => BASE_PATH . '/skills', 'source' => 'core']]),
        ),
    ]));
    $service->putAgentOverride(SkillTool::class, $agentId, ['allowed_skills' => ['time-arithmetic', 'deleted-one']]);

    $text = skillProjectionText($service, $agentId, new PrincipalContext($principalId, 'user', $userId, $userId));

    expect($text)->toContain('time-arithmetic')
        ->and($text)->toContain('(unavailable: deleted-one)');
});
