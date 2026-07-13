<?php

declare(strict_types=1);

use Spora\AgentTemplates\AgentTemplateExporter;
use Spora\AgentTemplates\AgentTemplateImporter;
use Spora\AgentTemplates\AgentTemplateScanner;
use Spora\AgentTemplates\AgentTemplateValidator;
use Spora\Core\Paths;
use Spora\Http\AgentTemplateController;
use Spora\Models\Agent;
use Spora\Plugins\PluginLoader;
use Spora\Services\AgentService;
use Spora\Services\LLMConfigService;
use Spora\Services\ToolConfigService;

function makeController(): AgentTemplateController
{
    $auth = bootAuthLayer();

    $key      = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new Spora\Core\SecurityManager($key);
    $logger   = new Monolog\Logger('test');
    $toolClasses = [
        Spora\Tools\CurrentTimeTool::class,
        Spora\Tools\CalculatorTool::class,
        Spora\Tools\AgentMemoryTool::class,
        Spora\Tools\GlobalMemoryTool::class,
        Spora\Tools\ReadUrlTool::class,
        Spora\Tools\UserInfoTool::class,
        Spora\Tools\HandoverTool::class,
    ];
    $toolConfig = new ToolConfigService($security, $logger, $toolClasses);
    $plugins = new PluginLoader([]);
    $paths = new Paths(BASE_PATH);
    $scanner = new AgentTemplateScanner(
        directories: $paths->agentTemplatesPaths(),
    );
    $validator = new AgentTemplateValidator();
    $importer = new AgentTemplateImporter($toolConfig, $plugins, $paths);
    $exporter = new AgentTemplateExporter();
    $agentService = new AgentService(
        $toolConfig,
        new LLMConfigService($security, []),
    );

    return new AgentTemplateController($auth, $scanner, $validator, $importer, $exporter, $agentService);
}

beforeEach(function (): void {
    $this->userId = bootAuth(bootAuthLayer(), 'controller-test@example.com');
    $this->controller = makeController();
});

test('validatePayload returns 400 on invalid JSON', function (): void {
    $request = Symfony\Component\HttpFoundation\Request::create(
        '/api/v1/agent-templates/validate',
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        'not json',
    );
    $response = $this->controller->validatePayload($request);
    expect($response->getStatusCode())->toBe(400);
    expect(json_decode($response->getContent(), true)['error']['code'])->toBe('INVALID_JSON');
});

test('validatePayload returns errors[] for a payload missing required fields', function (): void {
    $request = jsonRequest('POST', '/api/v1/agent-templates/validate', [
        'name' => 'Missing id and version',
    ]);
    $response = $this->controller->validatePayload($request);
    expect($response->getStatusCode())->toBe(200);
    $data = json_decode($response->getContent(), true)['data'];
    expect($data['valid'])->toBeFalse();
    $codes = array_column($data['errors'], 'code');
    expect($codes)->toContain('ID_REQUIRED');
    expect($codes)->toContain('VERSION_REQUIRED');
});

test('validatePayload returns valid:true and warnings[] for a complete payload', function (): void {
    $request = jsonRequest('POST', '/api/v1/agent-templates/validate', [
        'id' => 'ok', 'name' => 'OK', 'version' => '1.0.0',
        'agent' => ['max_steps' => 5, 'system_prompt' => 'be brief'],
        'tools' => [],
        'required_plugins' => [],
    ]);
    $response = $this->controller->validatePayload($request);
    $data = json_decode($response->getContent(), true)['data'];
    expect($data['valid'])->toBeTrue();
});

test('import returns 201 with an Agent payload + warnings on success', function (): void {
    $request = jsonRequest('POST', '/api/v1/agent-templates/import', [
        'id' => 'simple', 'name' => 'Simple', 'version' => '1.0.0',
        'agent' => ['max_steps' => 5, 'system_prompt' => 'x'],
        'tools' => [[
            'tool_class' => 'Spora\\Tools\\CurrentTimeTool',
            'enabled' => true,
            'operations' => [['name' => 'now', 'auto_approve' => true]],
        ]],
        'required_plugins' => [],
    ]);
    $response = $this->controller->import($request);
    expect($response->getStatusCode())->toBe(201);
    $data = json_decode($response->getContent(), true)['data'];
    expect($data['agent']['name'])->toBe('Simple');
    expect($data['agent']['id'])->toBe(1);
    expect($data['agent']['name'])->toBe('Simple');
    expect(count($data['tools_enabled']))->toBe(1);
});

test('import returns 422 VALIDATION_ERROR when validator reports errors', function (): void {
    $request = jsonRequest('POST', '/api/v1/agent-templates/import', [
        'name' => 'no id',
    ]);
    $response = $this->controller->import($request);
    expect($response->getStatusCode())->toBe(422);
    expect(json_decode($response->getContent(), true)['error']['code'])->toBe('VALIDATION_ERROR');
});

test('exportAgent returns 404 for an agent owned by another user', function (): void {
    $request = Symfony\Component\HttpFoundation\Request::create(
        '/api/v1/agents/9999/export',
        'GET',
    );
    $request->attributes->set('id', 9999);
    $response = $this->controller->exportAgent($request);
    expect($response->getStatusCode())->toBe(404);
});

test('exportAgent returns the template payload + inline_warning for an owned agent', function (): void {
    $agent = Agent::create([
        'user_id'   => $this->userId,
        'name'      => 'Owned',
        'max_steps' => 5,
        'is_active' => true,
    ]);

    $request = Symfony\Component\HttpFoundation\Request::create(
        "/api/v1/agents/{$agent->id}/export",
        'GET',
    );
    $request->attributes->set('id', $agent->id);
    $response = $this->controller->exportAgent($request);
    expect($response->getStatusCode())->toBe(200);

    $data = json_decode($response->getContent(), true)['data'];
    expect($data['template']['name'])->toBe('Owned');
    expect($data['inline_warning'])->toContain('NOT included');
});

test('index lists the built-in core-assistant template', function (): void {
    $response = $this->controller->index();
    expect($response->getStatusCode())->toBe(200);
    $templates = json_decode($response->getContent(), true)['data']['templates'];
    $ids = array_column($templates, 'id');
    expect($ids)->toContain('core/core-assistant');
});

test('show() returns the full template for a namespaced id', function (): void {
    $request = Symfony\Component\HttpFoundation\Request::create(
        '/api/v1/agent-templates/core/core-assistant',
        'GET',
    );
    $request->attributes->set('id', 'core/core-assistant');
    $response = $this->controller->show($request);
    expect($response->getStatusCode())->toBe(200);
    $data = json_decode($response->getContent(), true)['data'];
    expect($data['template']['id'])->toBe('core/core-assistant');
});

test('show() returns 404 for a missing id', function (): void {
    $request = Symfony\Component\HttpFoundation\Request::create(
        '/api/v1/agent-templates/does-not-exist',
        'GET',
    );
    $request->attributes->set('id', 'does-not-exist');
    $response = $this->controller->show($request);
    expect($response->getStatusCode())->toBe(404);
});
