<?php

declare(strict_types=1);

use Spora\Models\Agent;

const AGENT_PROMPT_TEMPLATE_TEST_PASSWORD = 'Password1!';
use Spora\Models\AgentPromptTemplate;

it('uses the agent_prompt_templates table', function (): void {
    $template = new AgentPromptTemplate();

    expect($template->getTable())->toBe('agent_prompt_templates');
});

it('allows mass assignment of template fields', function (): void {
    $userId = bootAuthLayer()->register('template@example.com', AGENT_PROMPT_TEMPLATE_TEST_PASSWORD, 'Template');
    $agent = Agent::create([
        'user_id'      => $userId,
        'name'         => 'Template Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);

    $template = AgentPromptTemplate::create([
        'agent_id'        => $agent->id,
        'name'            => 'Daily Summary',
        'description'     => 'Summarise recent activity',
        'prompt_template' => 'Hello {{name}}, today is {{current_date}}.',
        'variables'       => [['key' => 'name', 'default_value' => 'World']],
        'max_steps'       => 5,
        'is_active'       => true,
    ]);

    expect($template->name)->toBe('Daily Summary')
        ->and($template->is_active)->toBeTrue()
        ->and($template->max_steps)->toBe(5)
        ->and($template->variables)->toBe([['key' => 'name', 'default_value' => 'World']]);
});

it('casts variables to array, is_active to bool, max_steps to int', function (): void {
    $userId = bootAuthLayer()->register('cast-tpl@example.com', AGENT_PROMPT_TEMPLATE_TEST_PASSWORD, 'Cast');
    $agent = Agent::create([
        'user_id'      => $userId,
        'name'         => 'Cast Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);

    $template = AgentPromptTemplate::create([
        'agent_id'        => $agent->id,
        'name'            => 'Cast Template',
        'prompt_template' => 'Static',
        'variables'       => ['k' => 'v'],
        'is_active'       => true,
        'max_steps'       => 7,
    ]);

    expect($template->variables)->toBeArray()
        ->and($template->is_active)->toBeBool()
        ->and($template->max_steps)->toBeInt();
});

it('belongs to an agent', function (): void {
    $userId = bootAuthLayer()->register('tpl-rel@example.com', AGENT_PROMPT_TEMPLATE_TEST_PASSWORD, 'TplRel');
    $agent = Agent::create([
        'user_id'      => $userId,
        'name'         => 'TplRel Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);
    $template = AgentPromptTemplate::create([
        'agent_id'        => $agent->id,
        'name'            => 'Rel Template',
        'prompt_template' => 'Hi',
        'is_active'       => true,
    ]);

    expect($template->agent)->toBeInstanceOf(Agent::class)
        ->and((int) $template->agent->getKey())->toBe($agent->id);
});
