<?php

declare(strict_types=1);

use Spora\Core\Config;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Services\AgentStepCap;
use Symfony\Component\HttpFoundation\Request;

/**
 * The ceiling reaches every surface that takes a cap.
 *
 * The ceiling is the instance's, so a surface that judges a cap by the
 * built-in default instead of the instance's ignores what the operator
 * set — and a cap above the ceiling would be stored and carried into
 * every task the agent starts.
 */
afterEach(fn() => Spora\Core\Database::resetBootState());

/**
 * A configuration whose driver offers a known level set.
 */
function ceilingConfig(): LLMDriverConfiguration
{
    return LLMDriverConfiguration::create([
        'principal_id' => null,
        'name'         => 'Ceiling Config',
        'driver_class' => OpenAICompatibleDriver::class,
        'settings'     => json_encode([
            'api_key'      => 'test',
            'model'        => 'gpt-4o-mini',
            'base_url'     => 'https://example.invalid/v1',
            'effort_levels' => 'low,high',
        ]),
        'is_global'  => true,
        'is_default' => true,
    ]);
}

test('the template validator judges the cap by the instance ceiling', function (): void {
    $validator = new Spora\AgentTemplates\AgentTemplateValidator(
        stepCap: new AgentStepCap(new Config(['SPORA_MAX_STEPS' => 50])),
    );

    $result = $validator->validate([
        'id'      => 'template-1',
        'name'    => 'Example',
        'version' => '1.0.0',
        'agent'   => ['description' => 'Example', 'max_steps' => 80],
    ]);

    expect(array_column($result->errors(), 'code'))->toContain('MAX_STEPS_RANGE');
});

test('the template validator accepts a cap inside the instance ceiling', function (): void {
    $validator = new Spora\AgentTemplates\AgentTemplateValidator(
        stepCap: new AgentStepCap(new Config(['SPORA_MAX_STEPS' => 50])),
    );

    $result = $validator->validate([
        'id'      => 'template-1',
        'name'    => 'Example',
        'version' => '1.0.0',
        'agent'   => ['description' => 'Example', 'max_steps' => 40],
    ]);

    expect($result->errors())->toBe([]);
});

test('a template-created agent carries a cap inside the ceiling', function (): void {
    $creator = new Spora\AgentTemplates\AgentTemplateAgentCreator(
        new AgentStepCap(new Config(['SPORA_MAX_STEPS' => 50])),
    );

    $template = new Spora\AgentTemplates\AgentTemplate(
        ['id' => 'template-1', 'name' => 'Example', 'version' => '1.0.0', 'agent' => ['description' => 'Example']],
    );

    $agentId = $creator->create(createUserPrincipalPublic(1), $template);

    // The constant is 100, which is above this instance's ceiling of 50.
    expect((int) Agent::findOrFail($agentId)->max_steps)->toBe(50);
});

test('a task cannot carry a cap above the ceiling', function (): void {
    $authService = bootAuthLayer();
    $userId = bootAuth($authService, 'ceiling-task@example.com');

    $config = ceilingConfig();
    $agent = Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name'         => 'Ceiling Agent',
        'llm_driver_config_id' => $config->id,
        'max_steps'    => 100,
        'is_active'    => true,
    ]);

    $taskService = Mockery::mock(Spora\Services\TaskServiceInterface::class);
    $mediaCapability = new Spora\Services\MediaArchive\TaskMediaCapabilityService();

    $controller = new Spora\Http\TaskController(
        $authService,
        $taskService,
        $mediaCapability,
        new Spora\Http\ContinueTaskDispatcher($taskService, $mediaCapability, makeEffortService()),
        new Spora\Http\DecisionsRequestValidator($taskService),
        new Spora\Http\AnswerQuestionRequestValidator(new Spora\Services\PrincipalResolver()),
        makeAgentService(),
        makeEffortService(),
        new AgentStepCap(new Config(['SPORA_MAX_STEPS' => 50])),
    );

    $request = Request::create(
        '/api/v1/tasks',
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        json_encode(['agent_id' => (int) $agent->id, 'prompt' => 'hello', 'max_steps' => 500]),
    );

    $response = $controller->store($request);

    expect($response->getStatusCode())->toBe(422)
        ->and(json_decode($response->getContent(), true)['error']['message'])->toContain('between 1 and 50');
});
