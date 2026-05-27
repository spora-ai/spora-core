<?php

declare(strict_types=1);

use Spora\Auth\AuthService;
use Spora\Http\PromptTemplateController;
use Spora\Models\Agent;
use Spora\Models\AgentPromptTemplate;
use Spora\Services\PromptTemplateService;

function makePromptTemplateController(): array
{
    $authService = bootAuthLayer();
    $promptTemplateService = new PromptTemplateService();
    $controller = new PromptTemplateController($authService, $promptTemplateService);

    return [$controller, $authService, $promptTemplateService];
}

function registerAndGetAgentId(AuthService $authService): array
{
    $userId = $authService->register('template@example.com', 'Password1!', 'Template');
    simulateLoggedInSession($userId, 'template@example.com');

    $agent = Agent::create([
        'user_id'   => $userId,
        'name'      => 'TemplateTestAgent',
        'max_steps' => 10,
        'is_active' => true,
    ]);

    return [$userId, $agent->id];
}

// ---------------------------------------------------------------------------
// CRUD operations
// ---------------------------------------------------------------------------

describe('PromptTemplateController', function (): void {
    it('index returns templates for the authenticated user agent', function (): void {
        [$userId, $agentId] = registerAndGetAgentId(bootAuthLayer());

        AgentPromptTemplate::create([
            'agent_id' => $agentId,
            'name'     => 'Daily Report',
            'prompt_template' => 'Generate a report for {{city}}',
            'is_active' => true,
        ]);
        AgentPromptTemplate::create([
            'agent_id' => $agentId,
            'name'     => 'Weather Summary',
            'prompt_template' => 'What is the weather in {{city}}?',
            'is_active' => true,
        ]);

        $controller = new PromptTemplateController(bootAuthLayer(), new PromptTemplateService());
        $request = jsonRequest('GET', "/api/v1/agents/{$agentId}/templates");
        $request->attributes->set('id', $agentId);
        $response = $controller->index($request);

        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['templates'])->toHaveCount(2);
    });

    it('index returns empty array when agent has no templates', function (): void {
        [$userId, $agentId] = registerAndGetAgentId(bootAuthLayer());

        $controller = new PromptTemplateController(bootAuthLayer(), new PromptTemplateService());
        $request = jsonRequest('GET', "/api/v1/agents/{$agentId}/templates");
        $request->attributes->set('id', $agentId);
        $response = $controller->index($request);

        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['templates'])->toHaveCount(0);
    });

    it('index returns 404 for agent belonging to another user', function (): void {
        $authService = bootAuthLayer();
        $userId = $authService->register('owner@example.com', 'Password1!', 'Owner');
        $otherUserId = bootAuthLayer()->register('other@example.com', 'Password1!', 'Other');
        simulateLoggedInSession($otherUserId, 'other@example.com');

        $agent = Agent::create([
            'user_id' => $userId,
            'name'    => 'OtherUserAgent',
            'max_steps' => 10,
            'is_active' => true,
        ]);

        $controller = new PromptTemplateController(bootAuthLayer(), new PromptTemplateService());
        $request = jsonRequest('GET', "/api/v1/agents/{$agent->id}/templates");
        $request->attributes->set('agentId', $agent->id);
        $response = $controller->index($request);

        expect($response->getStatusCode())->toBe(404);
    });

    it('store creates a new template', function (): void {
        [$userId, $agentId] = registerAndGetAgentId(bootAuthLayer());

        $controller = new PromptTemplateController(bootAuthLayer(), new PromptTemplateService());
        $request = jsonRequest('POST', "/api/v1/agents/{$agentId}/templates", [
            'name'             => 'My Template',
            'prompt_template' => 'Hello {{name}}, today is {{date}}',
            'description'      => 'A test template',
            'variables'        => ['name' => 'World'],
            'max_steps'        => 5,
            'is_active'        => true,
        ]);
        $request->attributes->set('id', $agentId);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(201);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['template']['name'])->toBe('My Template');
        expect($body['data']['template']['prompt_template'])->toBe('Hello {{name}}, today is {{date}}');
        expect($body['data']['template']['variables'])->toBe(['name' => 'World']);
        expect($body['data']['template']['max_steps'])->toBe(5);
    });

    it('store returns 422 when name is missing', function (): void {
        [$userId, $agentId] = registerAndGetAgentId(bootAuthLayer());

        $controller = new PromptTemplateController(bootAuthLayer(), new PromptTemplateService());
        $request = jsonRequest('POST', "/api/v1/agents/{$agentId}/templates", [
            'prompt_template' => 'Hello',
        ]);
        $request->attributes->set('id', $agentId);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(422);
    });

    it('store returns 422 when prompt_template is missing', function (): void {
        [$userId, $agentId] = registerAndGetAgentId(bootAuthLayer());

        $controller = new PromptTemplateController(bootAuthLayer(), new PromptTemplateService());
        $request = jsonRequest('POST', "/api/v1/agents/{$agentId}/templates", [
            'name' => 'No Prompt Template',
        ]);
        $request->attributes->set('id', $agentId);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(422);
    });

    it('show returns a single template', function (): void {
        [$userId, $agentId] = registerAndGetAgentId(bootAuthLayer());

        $template = AgentPromptTemplate::create([
            'agent_id' => $agentId,
            'name'     => 'Show Template',
            'prompt_template' => 'Show me {{topic}}',
            'is_active' => true,
        ]);

        $controller = new PromptTemplateController(bootAuthLayer(), new PromptTemplateService());
        $request = jsonRequest('GET', "/api/v1/agents/{$agentId}/templates/{$template->id}");
        $request->attributes->set('id', $agentId);
        $request->attributes->set('templateId', $template->id);
        $response = $controller->show($request);

        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['template']['name'])->toBe('Show Template');
    });

    it('show returns 404 for template belonging to another agent', function (): void {
        [$userId, $agentId] = registerAndGetAgentId(bootAuthLayer());

        $otherAgent = Agent::create([
            'user_id' => $userId,
            'name'    => 'OtherAgent',
            'max_steps' => 10,
            'is_active' => true,
        ]);
        $template = AgentPromptTemplate::create([
            'agent_id' => $otherAgent->id,
            'name'     => 'Other Agent Template',
            'prompt_template' => 'Other',
            'is_active' => true,
        ]);

        $controller = new PromptTemplateController(bootAuthLayer(), new PromptTemplateService());
        $request = jsonRequest('GET', "/api/v1/agents/{$agentId}/templates/{$template->id}");
        $request->attributes->set('id', $agentId);
        $request->attributes->set('templateId', $template->id);
        $response = $controller->show($request);

        expect($response->getStatusCode())->toBe(404);
    });

    it('update modifies a template', function (): void {
        [$userId, $agentId] = registerAndGetAgentId(bootAuthLayer());

        $template = AgentPromptTemplate::create([
            'agent_id' => $agentId,
            'name'     => 'Original Name',
            'prompt_template' => 'Original prompt',
            'is_active' => true,
        ]);

        $controller = new PromptTemplateController(bootAuthLayer(), new PromptTemplateService());
        $request = jsonRequest('PUT', "/api/v1/agents/{$agentId}/templates/{$template->id}", [
            'name' => 'Updated Name',
            'prompt_template' => 'Updated prompt',
            'is_active' => false,
        ]);
        $request->attributes->set('id', $agentId);
        $request->attributes->set('templateId', $template->id);
        $response = $controller->update($request);

        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['template']['name'])->toBe('Updated Name');
        expect($body['data']['template']['is_active'])->toBe(false);
    });

    it('destroy deletes a template', function (): void {
        [$userId, $agentId] = registerAndGetAgentId(bootAuthLayer());

        $template = AgentPromptTemplate::create([
            'agent_id' => $agentId,
            'name'     => 'To Delete',
            'prompt_template' => 'Delete me',
            'is_active' => true,
        ]);
        $templateId = $template->id;

        $controller = new PromptTemplateController(bootAuthLayer(), new PromptTemplateService());
        $request = jsonRequest('DELETE', "/api/v1/agents/{$agentId}/templates/{$templateId}");
        $request->attributes->set('id', $agentId);
        $request->attributes->set('templateId', $templateId);
        $response = $controller->destroy($request);

        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['deleted'])->toBe(true);
        expect(AgentPromptTemplate::find($templateId))->toBeNull();
    });

    it('destroy returns 404 for template belonging to another agent', function (): void {
        [$userId, $agentId] = registerAndGetAgentId(bootAuthLayer());

        $otherAgent = Agent::create([
            'user_id' => $userId,
            'name'    => 'OtherAgent',
            'max_steps' => 10,
            'is_active' => true,
        ]);
        $template = AgentPromptTemplate::create([
            'agent_id' => $otherAgent->id,
            'name'     => 'Other Template',
            'prompt_template' => 'Other',
            'is_active' => true,
        ]);

        $controller = new PromptTemplateController(bootAuthLayer(), new PromptTemplateService());
        $request = jsonRequest('DELETE', "/api/v1/agents/{$agentId}/templates/{$template->id}");
        $request->attributes->set('id', $agentId);
        $request->attributes->set('templateId', $template->id);
        $response = $controller->destroy($request);

        expect($response->getStatusCode())->toBe(404);
        expect(AgentPromptTemplate::find($template->id))->not->toBeNull();
    });
});
