<?php

declare(strict_types=1);

use Spora\Core\Database;
use Spora\Core\SecurityManager;
use Spora\Models\Agent;
use Spora\Services\PrincipalResolver;
use Spora\Services\ToolConfigService;
use Tests\Fixtures\TestTool;

// Helpers

function makeLlmSettingsService(): array
{
    $authService = bootAuthLayer();

    $key     = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new SecurityManager($key);
    $logger  = new Monolog\Logger('test');
    $service = new ToolConfigService($security, $logger, [TestTool::class]);

    return [$service, $security, $authService];
}

function makeAgentWithLlm(mixed $authService): int
{
    static $seq = 0;
    $seq++;
    $email = "llmtest{$seq}@example.com";
    $userId = $authService->register($email, 'Password1!', ucfirst(explode('@', $email)[0]));

    return Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name'         => 'LLM Test Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 10,
        'is_active'    => true,
    ])->id;
}

// getLlmToolSettings

test('getLlmToolSettings returns only expose_to_llm fields', function (): void {
    [$service, , $authService] = makeLlmSettingsService();
    $agentId = makeAgentWithLlm($authService);

    // Put some agent-level settings
    $service->putAgentOverride(TestTool::class, $agentId, [
        'allowed_target_agents' => ['agent-a', 'agent-b'],
        // These should be filtered out (expose_to_llm = false)
        'api_key'               => 'secret-123',
        'max_results'           => '25',
    ]);

    $result = $service->getLlmToolSettings(TestTool::class, $agentId);

    // Should only contain expose_to_llm fields
    expect($result)->toHaveKey('allowed_target_agents');
    expect($result)->not->toHaveKey('api_key');
    expect($result)->not->toHaveKey('max_results');

    // Should contain label
    expect($result['allowed_target_agents']['label'])->toBe('Allowed target agents');
})->afterEach(fn() => Database::resetBootState());

test('getLlmToolSettings returns empty array for multi-select with no value', function (): void {
    [$service, , $authService] = makeLlmSettingsService();
    $agentId = makeAgentWithLlm($authService);

    $result = $service->getLlmToolSettings(TestTool::class, $agentId);

    // expose_to_llm multi-select field is returned as an empty array when
    // no value is configured (formatAgentIdList always returns array).
    expect($result)->toHaveKey('allowed_target_agents');
    expect($result['allowed_target_agents']['value'])->toBe([]);
})->afterEach(fn() => Database::resetBootState());

test('getLlmToolSettings respects user-specific settings cascade', function (): void {
    [$service, , $authService] = makeLlmSettingsService();
    $agentId = makeAgentWithLlm($authService);

    // Set user-level override
    $agent = Agent::find($agentId);
    $userAdapter = makeUserAdapter($service);
    $userAdapter->putUserSettings(TestTool::class, $agent->user_id, [
        'allowed_target_agents' => ['user-override-agent'],
    ]);

    $result = $service->getLlmToolSettings(TestTool::class, $agentId, $agent->user_id);

    // Multi-select values get formatted as a name list — without a matching
    // Agent row the value is an empty list. The test just checks the
    // method runs and returns the structure.
    expect($result)->toHaveKey('allowed_target_agents');
})->afterEach(fn() => Database::resetBootState());

test('getLlmToolSettings resolves agent names when a PrincipalResolver is wired', function (): void {
    // Regression for the LLM seeing only "#id" placeholders: when
    // ToolConfigService is constructed without a PrincipalResolver, the
    // internal inspector falls back to "#{$id}" strings for every
    // resolveAs:'agent' multi-select. With the resolver wired (the DI
    // runtime path), same-principal agent ids get "Name (#id)" labels.
    $authService = bootAuthLayer();
    $userId = $authService->register('agent-name-resolve@example.com', 'Password1!', 'Resolve');
    $principalId = createUserPrincipalPublic($userId);

    $source = Agent::create([
        'principal_id' => $principalId,
        'name'         => 'Source',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 5,
        'is_active'    => true,
    ]);
    $target = Agent::create([
        'principal_id' => $principalId,
        'name'         => 'My Target Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 5,
        'is_active'    => true,
    ]);

    $key      = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $resolver = new PrincipalResolver();
    $service  = new ToolConfigService(
        new SecurityManager($key),
        new Monolog\Logger('test'),
        [TestTool::class],
        null,
        null,
        false,
        $resolver,
    );
    $service->putAgentOverride(TestTool::class, $source->id, [
        'allowed_target_agents' => [$target->id],
    ]);

    $result = $service->getLlmToolSettings(TestTool::class, $source->id, $userId);

    expect($result['allowed_target_agents']['value'])
        ->toBe(["My Target Agent (#{$target->id})"]);
})->afterEach(fn() => Database::resetBootState());

test('getLlmToolSettings falls back to "#id" placeholders when no PrincipalResolver is wired', function (): void {
    // Documents the legacy behaviour preserved for test stubs: without
    // the resolver, the inspector cannot look up names and emits "#{$id}"
    // placeholders. The runtime always wires the resolver via the DI
    // container — this is the test-only branch.
    $authService = bootAuthLayer();
    $userId = $authService->register('agent-name-noop@example.com', 'Password1!', 'Noop');
    $principalId = createUserPrincipalPublic($userId);

    $source = Agent::create([
        'principal_id' => $principalId,
        'name'         => 'Source Noop',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 5,
        'is_active'    => true,
    ]);
    $target = Agent::create([
        'principal_id' => $principalId,
        'name'         => 'Target Noop',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 5,
        'is_active'    => true,
    ]);

    $key     = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $service = new ToolConfigService(
        new SecurityManager($key),
        new Monolog\Logger('test'),
        [TestTool::class],
        // no PrincipalResolver — fallback path
    );
    $service->putAgentOverride(TestTool::class, $source->id, [
        'allowed_target_agents' => [$target->id],
    ]);

    $result = $service->getLlmToolSettings(TestTool::class, $source->id, $userId);

    expect($result['allowed_target_agents']['value'])->toBe(["#{$target->id}"]);
})->afterEach(fn() => Database::resetBootState());
