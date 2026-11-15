<?php

declare(strict_types=1);

use Spora\Core\Config;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Http\AgentController;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Services\AgentFavoriteService;
use Spora\Services\AgentService;
use Spora\Services\AgentStepCap;
use Spora\Services\PrincipalResolver;
use Spora\Tools\AgentTool\AgentPatchValidator;
use Spora\Tools\AgentTool\SlimPayloadValidator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The step cap an agent may carry.
 *
 * The cap is the only thing bounding the tick's recursion: a tick that
 * finishes a tool batch hands the task straight back to the loop, so an
 * agent whose model keeps calling tools would recurse until the worker
 * exhausted its memory and died.
 *
 * The ceiling is what makes that impossible. It is configurable, because a
 * long-running agentic workflow needs more room than a chat does, and it
 * is the upper limit of the cap on every surface that takes one.
 */
afterEach(fn() => Spora\Core\Database::resetBootState());

/**
 * @return array{0: AgentController, 1: int} the controller and the agent id
 */
function stepCapController(?AgentStepCap $stepCap = null): array
{
    static $seq = 0;
    $seq++;

    $authService = bootAuthLayer();
    $userId = bootAuth($authService, "step-cap-{$seq}@example.com");

    $config = LLMDriverConfiguration::create([
        'principal_id' => null,
        'name'         => 'Step Cap Config',
        'driver_class' => OpenAICompatibleDriver::class,
        'settings'     => json_encode([
            'api_key' => 'test',
            'model'   => 'gpt-4o-mini',
            'base_url' => 'https://example.invalid/v1',
        ]),
        'is_global'  => true,
        'is_default' => true,
    ]);

    $agent = Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name'         => 'Step Cap Agent',
        'llm_driver_config_id' => $config->id,
        'max_steps'    => 100,
        'is_active'    => true,
    ]);

    $agentService = new AgentService(
        null,
        null,
        new PrincipalResolver(),
        null,
        null,
        $stepCap,
    );

    return [
        new AgentController(
            $authService,
            $agentService,
            new AgentFavoriteService($agentService),
            null,
            null,
            null,
            null,
            null,
            $stepCap,
        ),
        (int) $agent->id,
    ];
}

/**
 * @param array<string, mixed> $body
 */
function stepCapRequest(int $agentId, array $body, string $method = 'PATCH'): Request
{
    $request = Request::create(
        '/api/v1/agents/' . $agentId,
        $method,
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        json_encode($body),
    );
    $request->attributes->set('id', $agentId);

    return $request;
}

test('the ceiling is two hundred when nothing says otherwise', function (): void {
    expect((new AgentStepCap())->limit())->toBe(200)
        ->and((new AgentStepCap(new Config()))->limit())->toBe(200);
});

test('the ceiling follows the setting when it is set', function (): void {
    $stepCap = new AgentStepCap(new Config(['SPORA_MAX_STEPS' => 50]));

    expect($stepCap->limit())->toBe(50);
});

test('a new agent carries a hundred steps', function (): void {
    expect((new AgentStepCap())->defaultCap())->toBe(100);
});

test('the default never exceeds the ceiling', function (): void {
    $stepCap = new AgentStepCap(new Config(['SPORA_MAX_STEPS' => 50]));

    expect($stepCap->defaultCap())->toBe(50);
});

test('a cap inside the ceiling is accepted', function (): void {
    [$controller, $agentId] = stepCapController();

    $response = $controller->update(stepCapRequest($agentId, ['max_steps' => 150]));

    expect($response->getStatusCode())->toBe(200)
        ->and(Agent::findOrFail($agentId)->max_steps)->toBe(150);
});

test('a cap above the ceiling is refused, and the message names the bound', function (): void {
    [$controller, $agentId] = stepCapController();

    $response = $controller->update(stepCapRequest($agentId, ['max_steps' => 250]));

    expect($response->getStatusCode())->toBe(422);

    $payload = json_decode($response->getContent(), true);
    expect($payload['error']['code'])->toBe('VALIDATION_ERROR')
        ->and($payload['error']['message'])->toContain('between 1 and 200')
        ->and(Agent::findOrFail($agentId)->max_steps)->toBe(100);
});

test('the ceiling the instance sets is the one that is enforced', function (): void {
    $stepCap = new AgentStepCap(new Config(['SPORA_MAX_STEPS' => 120]));
    [$controller, $agentId] = stepCapController($stepCap);

    $response = $controller->update(stepCapRequest($agentId, ['max_steps' => 150]));

    expect($response->getStatusCode())->toBe(422)
        ->and(json_decode($response->getContent(), true)['error']['message'])->toContain('between 1 and 120');
});

test('a new agent with no cap named carries the default', function (): void {
    [$controller, $agentId] = stepCapController();

    $response = $controller->store(stepCapRequest($agentId, ['name' => 'Fresh Agent'], 'POST'));

    expect($response->getStatusCode())->toBe(Response::HTTP_CREATED);

    $payload = json_decode($response->getContent(), true);
    expect($payload['data']['agent']['max_steps'])->toBe(100)
        ->and(Agent::findOrFail($payload['data']['agent']['id'])->max_steps)->toBe(100);
});

test('the LLM tools enforce the same ceiling', function (): void {
    $stepCap = new AgentStepCap();

    $patch = new AgentPatchValidator($stepCap);
    $create = new SlimPayloadValidator($stepCap);

    $refused = $patch->normalise(['max_steps' => 250]);
    expect($refused)->toBeInstanceOf(Spora\Tools\ValueObjects\ToolResult::class)
        ->and($refused->content)->toContain('1..200');

    $accepted = $patch->normalise(['max_steps' => 150]);
    expect($accepted)->toBeArray()
        ->and($accepted['max_steps'])->toBe(150);

    $refusedCreate = $create->validateCreateAgentPayload(['payload' => ['name' => 'Example', 'max_steps' => 250]]);
    expect($refusedCreate)->toBeInstanceOf(Spora\Tools\ValueObjects\ToolResult::class)
        ->and($refusedCreate->content)->toContain('1..200');
});

test('the agent template format enforces the same ceiling', function (): void {
    $validator = new Spora\AgentTemplates\AgentTemplateValidator();

    $result = $validator->validate([
        'id'      => 'template-1',
        'name'    => 'Example',
        'version' => '1.0.0',
        'agent'   => ['description' => 'Example', 'max_steps' => 250],
    ]);

    expect(array_column($result->errors(), 'code'))->toContain('MAX_STEPS_RANGE');
});
