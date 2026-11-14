<?php

declare(strict_types=1);

use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Http\Exceptions\EffortValidationException;
use Spora\Models\Agent;
use Spora\Models\AgentPromptTemplate;
use Spora\Models\LLMDriverConfiguration;
use Spora\Services\PromptTemplateService;

/**
 * The reasoning effort a saved prompt template carries.
 *
 * A template's effort is the level the agent runs it at, unless the schedule
 * that fires it overrides it. The level has to survive the write and the
 * read, and a level the agent's driver does not offer must be refused at the
 * write — a template that stores it would run at a depth nobody asked for.
 */
afterEach(fn() => Spora\Core\Database::resetBootState());

/**
 * @return array{0: PromptTemplateService, 1: int, 2: int} the service, the owner and the agent
 */
function templateEffortFixture(): array
{
    $authService = bootAuthLayer();
    static $seq = 0;
    $seq++;
    $userId = bootAuth($authService, "template-effort-{$seq}@example.com", 'Password1!', 'Template Effort');

    $config = LLMDriverConfiguration::create([
        'principal_id' => null,
        'name'         => 'Template Effort Config',
        'driver_class' => OpenAICompatibleDriver::class,
        'settings'     => json_encode([
            'api_key'  => 'test',
            'model'    => 'gpt-5.5',
            'base_url' => 'https://example.invalid',
        ]),
        'is_global'    => true,
        'is_default'   => true,
    ]);

    $agent = Agent::create([
        'principal_id'         => createUserPrincipalPublic($userId),
        'name'                 => 'Template Effort Agent',
        'llm_driver_config_id' => $config->id,
        'max_steps'            => 10,
        'is_active'            => true,
    ]);

    return [new PromptTemplateService(makeEffortService()), $userId, (int) $agent->id];
}

it('stores the effort a template was created with and reads it back', function (): void {
    [$service, $userId, $agentId] = templateEffortFixture();

    $created = $service->createTemplate($agentId, $userId, [
        'name'            => 'Deep summary',
        'prompt_template' => 'Summarize {{topic}}',
        'effort'          => 'high',
    ]);

    expect($created['template']['effort'])->toBe('high');

    $read = $service->getTemplate((int) $created['template']['id'], $agentId, $userId);

    expect($read['template']['effort'])->toBe('high');
});

it('a template without an effort inherits, and one with a level carries it', function (): void {
    [$service, $userId, $agentId] = templateEffortFixture();

    $inheriting = $service->createTemplate($agentId, $userId, [
        'name'            => 'Plain summary',
        'prompt_template' => 'Summarize',
    ]);

    $deep = $service->createTemplate($agentId, $userId, [
        'name'            => 'Deep summary',
        'prompt_template' => 'Summarize',
        'effort'          => 'high',
    ]);

    expect($inheriting['template']['effort'])->toBeNull()
        ->and(AgentPromptTemplate::find((int) $inheriting['template']['id'])->effort)->toBeNull()
        ->and($deep['template']['effort'])->toBe('high');
});

it('an update replaces the effort', function (): void {
    [$service, $userId, $agentId] = templateEffortFixture();

    $created = $service->createTemplate($agentId, $userId, [
        'name'            => 'Deep summary',
        'prompt_template' => 'Summarize',
        'effort'          => 'high',
    ]);

    $updated = $service->updateTemplate((int) $created['template']['id'], $agentId, $userId, [
        'effort' => 'low',
    ]);

    expect($updated['template']['effort'])->toBe('low');
});

it('an update with null clears the effort, so the template inherits again', function (): void {
    [$service, $userId, $agentId] = templateEffortFixture();

    $created = $service->createTemplate($agentId, $userId, [
        'name'            => 'Deep summary',
        'prompt_template' => 'Summarize',
        'effort'          => 'high',
    ]);

    expect($created['template']['effort'])->toBe('high');

    $updated = $service->updateTemplate((int) $created['template']['id'], $agentId, $userId, [
        'effort' => null,
    ]);

    expect($updated['template']['effort'])->toBeNull()
        ->and(AgentPromptTemplate::find((int) $created['template']['id'])->effort)->toBeNull();
});

it('refuses a level the agent\'s driver does not offer, and names the ones it does', function (): void {
    [$service, $userId, $agentId] = templateEffortFixture();

    try {
        $service->createTemplate($agentId, $userId, [
            'name'            => 'Impossible summary',
            'prompt_template' => 'Summarize',
            'effort'          => 'ultra',
        ]);
    } catch (EffortValidationException $e) {
        expect($e->errorCode())->toBe('EFFORT_LEVEL_UNSUPPORTED')
            ->and($e->getMessage())->toContain('Supported levels:');

        return;
    }

    $this->fail('Expected an EffortValidationException.');
});

it('refuses an unsupported level on an update too', function (): void {
    [$service, $userId, $agentId] = templateEffortFixture();

    $created = $service->createTemplate($agentId, $userId, [
        'name'            => 'Deep summary',
        'prompt_template' => 'Summarize',
        'effort'          => 'high',
    ]);

    expect(fn() => $service->updateTemplate((int) $created['template']['id'], $agentId, $userId, [
        'effort' => 'ultra',
    ]))->toThrow(EffortValidationException::class, 'Supported levels:');
});

it('a refused write leaves the stored effort alone', function (): void {
    [$service, $userId, $agentId] = templateEffortFixture();

    $created = $service->createTemplate($agentId, $userId, [
        'name'            => 'Deep summary',
        'prompt_template' => 'Summarize',
        'effort'          => 'high',
    ]);

    try {
        $service->updateTemplate((int) $created['template']['id'], $agentId, $userId, [
            'effort' => 'ultra',
        ]);
    } catch (EffortValidationException) {
        // The refusal is the point of the test.
    }

    expect(AgentPromptTemplate::find((int) $created['template']['id'])->effort)->toBe('high');
});
