<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use Spora\Core\SecurityManager;
use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Models\Principal;
use Spora\Services\AgentToolSettingsService;
use Spora\Services\LLMConfigService;
use Spora\Services\PrincipalContext;
use Spora\Services\ToolConfigService;
use Spora\Tools\MediaTool;

/**
 * The media tool's `scope` setting now defaults to `principal`.
 *
 * The default lives in one place — the `#[ToolSetting]` attribute — and the
 * settings cascade always starts from it, so the effective value is read
 * through {@see ToolConfigService::getEffectiveSettings()}, never through
 * the attribute: the attribute is the input, the effective settings are
 * what run.
 *
 * The blast radius is narrow on purpose. Enabling a tool materialises the
 * schema default into the agent's override row, so an agent that already
 * had the `media` tool enabled keeps the value it was given and the default
 * change does not reach it. That is the property that makes the change safe
 * for an existing installation — and the reason the diagnostic scope
 * failure exists.
 *
 * The tool's own fallback answers two different questions with two
 * different scopes. An absent setting is the operator's silence and takes
 * the shipped default; a wrong one — an unrecognised string, a value of a
 * type the setting never holds — takes the narrowest scope, so a setting
 * nobody can read cannot widen what the agent sees.
 */

/**
 * An agent owned by a user-principal, so the enable path can see it.
 */
function scopeDefaultAgent(int $userId): int
{
    return Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name'         => 'Scope default agent ' . bin2hex(random_bytes(3)),
        'is_active'    => true,
    ])->id;
}

/**
 * The context of a call made by an agent of `$userId`'s principal.
 */
function scopeDefaultContext(int $userId): PrincipalContext
{
    return new PrincipalContext(
        principalId: createUserPrincipalPublic($userId),
        type: Principal::TYPE_USER,
        ownerUserId: $userId,
        runnerUserId: $userId,
    );
}

function scopeDefaultToolConfig(): ToolConfigService
{
    return new ToolConfigService(
        new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
        new NullLogger(),
        [MediaTool::class],
    );
}

function scopeDefaultSettingsService(ToolConfigService $toolConfig): AgentToolSettingsService
{
    return new AgentToolSettingsService(
        $toolConfig,
        new LLMConfigService(
            new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
            [],
        ),
    );
}

describe('the media tool scope default', function (): void {
    it('is principal for an agent with no scope value anywhere in the cascade', function (): void {
        $userId   = 51501;
        $agentId  = scopeDefaultAgent($userId);
        $toolConfig = scopeDefaultToolConfig();

        $effective = $toolConfig->getEffectiveSettings(MediaTool::class, $agentId, $userId);

        expect($effective['scope'])->toBe('principal');
    });

    it('is principal for an agent that enables the tool from now on', function (): void {
        $userId     = 51502;
        $agentId    = scopeDefaultAgent($userId);
        $toolConfig = scopeDefaultToolConfig();

        scopeDefaultSettingsService($toolConfig)->enableTool($agentId, $userId, MediaTool::class);

        $effective = $toolConfig->getEffectiveSettings(MediaTool::class, $agentId, $userId);

        expect($effective['scope'])->toBe('principal');
    });

    it('stays agent for an agent that already had the tool enabled before the change', function (): void {
        // What the pre-change `enableTool()` wrote: the schema default of
        // the day, materialised into the agent's override row. The default
        // change does not reach it, so the agent keeps its reach.
        $userId     = 51503;
        $agentId    = scopeDefaultAgent($userId);
        $toolConfig = scopeDefaultToolConfig();

        AgentTool::create([
            'agent_id'   => $agentId,
            'tool_class' => MediaTool::class,
            'tool_name'  => 'media',
        ]);
        $toolConfig->putAgentOverride(MediaTool::class, $agentId, ['scope' => 'agent']);

        // The enable path runs again, as it does on every dashboard visit.
        scopeDefaultSettingsService($toolConfig)->enableTool($agentId, $userId, MediaTool::class);

        $effective = $toolConfig->getEffectiveSettings(MediaTool::class, $agentId, $userId);

        expect($effective['scope'])->toBe('agent');
    });

    it('keeps an operator\'s explicit scope', function (): void {
        $userId     = 51504;
        $agentId    = scopeDefaultAgent($userId);
        $toolConfig = scopeDefaultToolConfig();

        $toolConfig->putAgentOverride(MediaTool::class, $agentId, ['scope' => 'agent']);

        expect($toolConfig->getEffectiveSettings(MediaTool::class, $agentId, $userId)['scope'])
            ->toBe('agent');
    });

    it('falls back to the principal scope when the tool has no settings service', function (): void {
        // An absent setting is the operator's silence, not a wrong value,
        // so it takes the shipped default: `principal`. The asset belongs
        // to the calling agent, so the agent scope would show it; the
        // principal scope does not, because the call carries no principal
        // context to resolve.
        $agentId = scopeDefaultAgent(51505);
        $asset   = seedMediaAsset(agentId: $agentId, userId: 99);

        $tool   = buildMediaToolForSchema();
        $result = $tool->execute(['action' => 'get_media', 'asset_id' => $asset->id], agentId: $agentId);

        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('Media asset not found');
    });
});

describe('the media tool scope fallback', function (): void {
    it('answers with the principal scope when the setting is absent', function (): void {
        // No cascade and no stored value: the operator expressed no
        // opinion, so the shipped default answers — and it is the wider
        // scope, so the sibling agent's asset is reachable.
        $userId       = 51506;
        $callerAgent  = scopeDefaultAgent($userId);
        $siblingAgent = scopeDefaultAgent($userId);
        $asset        = seedMediaAsset(agentId: $siblingAgent, userId: $userId);

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(makeMediaToolNonAdminAuth());
        try {
            $result = $tool->execute(
                ['action' => 'get_media', 'asset_id' => $asset->id],
                agentId: $callerAgent,
                context: scopeDefaultContext($userId),
            );

            expect($result->success)->toBeTrue();
            expect($result->data['id'])->toBe($asset->id);
        } finally {
            $restore();
        }
    });

    it('answers with the agent scope when the setting is unrecognised', function (): void {
        // A stored scope nobody can read — a typo, a hand-edited row, a
        // value from a retired enum — is a wrong setting, and a wrong
        // setting must not widen the reach: the narrowest scope answers.
        // The caller's own asset stays reachable and the sibling's does
        // not, which is the agent scope exactly.
        $userId       = 51507;
        $callerAgent  = scopeDefaultAgent($userId);
        $siblingAgent = scopeDefaultAgent($userId);
        $ownAsset     = seedMediaAsset(agentId: $callerAgent, userId: $userId);
        $siblingAsset = seedMediaAsset(agentId: $siblingAgent, userId: $userId);

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(
            makeMediaToolNonAdminAuth(),
            mediaToolScopeConfig('prinicpal'),
        );
        try {
            $own = $tool->execute(
                ['action' => 'get_media', 'asset_id' => $ownAsset->id],
                agentId: $callerAgent,
                context: scopeDefaultContext($userId),
            );
            $sibling = $tool->execute(
                ['action' => 'get_media', 'asset_id' => $siblingAsset->id],
                agentId: $callerAgent,
                context: scopeDefaultContext($userId),
            );

            expect($own->success)->toBeTrue();
            expect($sibling->success)->toBeFalse();
            expect($sibling->content)->toContain('Media asset not found');
        } finally {
            $restore();
        }
    });

    it('answers with the agent scope when the stored scope is not a string', function (mixed $stored): void {
        // A stored scope of a type the setting never holds — a boolean, a
        // number, an array — is a wrong setting in the same way a typo is,
        // and it must not widen the reach either: the narrowest scope
        // answers. The caller's own asset stays reachable and the
        // sibling's does not.
        //
        // The value goes in through the real cascade, so the test proves
        // the hole is reachable rather than hypothetical: nothing on the
        // write path checks a select setting against its options, and the
        // cascade leaves a present value alone — a value that is set is
        // not a value that was cleared.
        $userId       = 51508;
        $callerAgent  = scopeDefaultAgent($userId);
        $siblingAgent = scopeDefaultAgent($userId);
        $ownAsset     = seedMediaAsset(agentId: $callerAgent, userId: $userId);
        $siblingAsset = seedMediaAsset(agentId: $siblingAgent, userId: $userId);

        $toolConfig = scopeDefaultToolConfig();
        $toolConfig->putAgentOverride(MediaTool::class, $callerAgent, ['scope' => $stored]);

        expect($toolConfig->getEffectiveSettings(MediaTool::class, $callerAgent, $userId)['scope'])
            ->toBe($stored);

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(
            makeMediaToolNonAdminAuth(),
            $toolConfig,
        );
        try {
            $own = $tool->execute(
                ['action' => 'get_media', 'asset_id' => $ownAsset->id],
                agentId: $callerAgent,
                context: scopeDefaultContext($userId),
            );
            $sibling = $tool->execute(
                ['action' => 'get_media', 'asset_id' => $siblingAsset->id],
                agentId: $callerAgent,
                context: scopeDefaultContext($userId),
            );

            expect($own->success)->toBeTrue();
            expect($sibling->success)->toBeFalse();
            expect($sibling->content)->toContain('Media asset not found');
        } finally {
            $restore();
        }
    })->with([
        'a boolean' => [false],
        'a number'  => [0],
        'an array'  => [[]],
    ]);
});
