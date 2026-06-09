<?php

declare(strict_types=1);

use Spora\Core\SecurityManager;
use Spora\Models\Agent;
use Spora\Services\ToolConfigService;
use Spora\Tools\HandoverTool;

/**
 * Pin the agent-override save flow's behavior.
 *
 * The frontend form is `Record<string, string>`. Empty/missing fields are
 * coerced to `null` in the payload, which the backend's `array_filter`
 * drops. The semantic: "no value here means inherit from parent" — but
 * that means clicking "Save Agent Overrides" with an empty picker wipes
 * the agent override. This is the documented behavior, not a bug, but
 * the test makes the contract explicit.
 */
it('clears the agent override when the form is saved with an empty multi-select', function (): void {
    $auth = bootAuthLayer();
    $userId = $auth->register('clear@example.com', 'Password1!', 'Clear');

    $agent = Agent::create([
        'user_id'      => $userId,
        'name'         => 'Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 5,
        'is_active'    => true,
    ]);
    $allowed = Agent::create([
        'user_id'      => $userId,
        'name'         => 'Allowed',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 5,
        'is_active'    => true,
    ]);

    $configService = new ToolConfigService(
        new SecurityManager(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
        new Monolog\Logger('clear-test'),
        [HandoverTool::class],
    );

    // Step 1: configure the override with an agent
    $configService->putAgentOverride(
        HandoverTool::class,
        $agent->id,
        ['allowed_target_agents' => json_encode([$allowed->id])],
    );
    $row = Spora\Models\AgentToolOverride::where('agent_id', $agent->id)
        ->where('tool_class', HandoverTool::class)
        ->first();
    expect($row)->not->toBeNull();

    // Step 2: simulate "user opens the modal, sees an empty picker (e.g.
    // because the form init only reads source=agent rows, and they think
    // it's a bug), then clicks Save with no selections". The frontend
    // sends `null` for the field, the backend filters it out.
    $configService->putAgentOverride(
        HandoverTool::class,
        $agent->id,
        ['allowed_target_agents' => null],
    );

    // The override row is gone (or has no remaining fields).
    $row = Spora\Models\AgentToolOverride::where('agent_id', $agent->id)
        ->where('tool_class', HandoverTool::class)
        ->first();
    if ($row !== null) {
        $decoded = json_decode($row->getRawOriginal('settings'), true) ?? [];
        // Settings JSON might be empty because every field was filtered out,
        // but the row itself can still exist.
        expect($decoded)->not->toHaveKey('allowed_target_agents');
    }
    // The effective setting is the default (empty array), not the prior override.
    $effective = $configService->getEffectiveSettings(HandoverTool::class, $agent->id, $userId);
    expect($effective['allowed_target_agents'])->toBe([]);
});
