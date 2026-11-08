<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Illuminate\Database\Capsule\Manager;
use Spora\Core\SecurityManager;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Http\AgentController;
use Spora\Models\Agent;
use Spora\Services\AgentFavoriteService;
use Spora\Services\AgentPrincipalService;
use Spora\Services\AgentService;
use Spora\Services\LLMConfigPreferences;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Spora\Services\ToolConfigService;
use Spora\Speech\OpenAiCompatibleTranscriber;
use Spora\Speech\SpeechToTextRegistry;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Response;

/**
 * A provider configuration belongs to the principal that pays for it, so
 * an agent may only point at a global configuration or one owned by the
 * agent's own principal — at write time and at run time.
 */
function buildScopedAgentController(): array
{
    $authService = bootAuthLayer();
    $principalResolver = new PrincipalResolver();
    $principalService = new PrincipalService($principalResolver);
    $agentService = new AgentService(
        null,
        null,
        $principalResolver,
        new AgentPrincipalService($principalService),
    );

    $controller = new AgentController(
        $authService,
        $agentService,
        new AgentFavoriteService($agentService),
        null,
        null,
        null,
        $principalService,
        $principalResolver,
    );

    return [$controller, $authService, $principalService, $agentService];
}

function seedScopedAgent(int $principalId, string $name, ?int $speechConfigId = null): int
{
    $now = date('Y-m-d H:i:s');

    return (int) Manager::table('agents')->insertGetId([
        'principal_id'           => $principalId,
        'name'                   => $name,
        'max_steps'              => 10,
        'is_active'              => 1,
        'speech_driver_config_id' => $speechConfigId,
        'created_at'             => $now,
        'updated_at'             => $now,
    ]);
}

function seedPrivateLlmConfig(int $principalId, string $name): int
{
    return (int) Manager::table('llm_driver_configurations')->insertGetId([
        'principal_id' => $principalId,
        'name'         => $name,
        'driver_class' => OpenAICompatibleDriver::class,
        'settings'     => json_encode(['api_key' => 'sk-private', 'model' => 'gpt-4o']),
        'is_default'   => false,
        'is_global'    => false,
        'created_at'   => date('Y-m-d H:i:s'),
        'updated_at'   => date('Y-m-d H:i:s'),
    ]);
}

function seedPrivateSpeechConfig(int $principalId, string $displayName): int
{
    return (int) Manager::table('speech_provider_configurations')->insertGetId([
        'principal_id'   => $principalId,
        'provider_class' => OpenAiCompatibleTranscriber::class,
        'display_name'   => $displayName,
        'settings'       => '{}',
        'is_default'     => false,
        'is_global'      => false,
        'created_at'     => date('Y-m-d H:i:s'),
        'updated_at'     => date('Y-m-d H:i:s'),
    ]);
}

describe('agent provider references stay inside the agent\'s principal', function (): void {
    beforeEach(function (): void {
        clearSession();
    });

    afterEach(function (): void {
        clearSession();
    });

    it('refuses an llm_driver_config_id owned by another principal', function (): void {
        [$controller, $authService, $principalService] = buildScopedAgentController();
        $callerId = bootAuth($authService, 'agent-scope-caller@example.com');
        $callerPrincipalId = (int) $principalService->ensureUserPrincipal($callerId)->id;

        $victimId = $authService->register('agent-scope-victim@example.com', 'Password1!', 'Victim');
        $victimPrincipalId = (int) $principalService->ensureUserPrincipal($victimId)->id;
        $victimConfigId = seedPrivateLlmConfig($victimPrincipalId, 'Victim LLM Config');

        $agentId = seedScopedAgent($callerPrincipalId, 'Scoped Agent');

        $request = jsonRequest('PATCH', '/api/v1/agents/' . $agentId, [
            'llm_driver_config_id' => $victimConfigId,
        ]);
        $request->attributes->set('id', $agentId);
        $response = $controller->update($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
        expect(Manager::table('agents')->where('id', $agentId)->value('llm_driver_config_id'))->toBeNull();
    });

    it('refuses a speech_driver_config_id owned by another principal', function (): void {
        [$controller, $authService, $principalService] = buildScopedAgentController();
        $callerId = bootAuth($authService, 'agent-scope-caller2@example.com');
        $callerPrincipalId = (int) $principalService->ensureUserPrincipal($callerId)->id;

        $victimId = $authService->register('agent-scope-victim2@example.com', 'Password1!', 'Victim');
        $victimPrincipalId = (int) $principalService->ensureUserPrincipal($victimId)->id;
        $victimConfigId = seedPrivateSpeechConfig($victimPrincipalId, 'Victim Speech Config');

        $agentId = seedScopedAgent($callerPrincipalId, 'Scoped Agent 2');

        $request = jsonRequest('PATCH', '/api/v1/agents/' . $agentId, [
            'speech_driver_config_id' => $victimConfigId,
        ]);
        $request->attributes->set('id', $agentId);
        $response = $controller->update($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
        expect(Manager::table('agents')->where('id', $agentId)->value('speech_driver_config_id'))->toBeNull();
    });

    it('accepts a configuration owned by the agent\'s own principal', function (): void {
        [$controller, $authService, $principalService] = buildScopedAgentController();
        $callerId = bootAuth($authService, 'agent-scope-own@example.com');
        $callerPrincipalId = (int) $principalService->ensureUserPrincipal($callerId)->id;
        $ownConfigId = seedPrivateLlmConfig($callerPrincipalId, 'Own LLM Config');

        $agentId = seedScopedAgent($callerPrincipalId, 'Scoped Agent 3');

        $request = jsonRequest('PATCH', '/api/v1/agents/' . $agentId, [
            'llm_driver_config_id' => $ownConfigId,
        ]);
        $request->attributes->set('id', $agentId);
        $response = $controller->update($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        expect((int) Manager::table('agents')->where('id', $agentId)->value('llm_driver_config_id'))
            ->toBe($ownConfigId);
    });

    it('does not resolve a foreign LLM configuration at run time', function (): void {
        $authService = bootAuthLayer();
        $principalService = new PrincipalService(new PrincipalResolver());
        $callerId = bootAuth($authService, 'agent-scope-runtime@example.com');
        $callerPrincipalId = (int) $principalService->ensureUserPrincipal($callerId)->id;

        $victimId = $authService->register('agent-scope-runtime-victim@example.com', 'Password1!', 'Victim');
        $victimPrincipalId = (int) $principalService->ensureUserPrincipal($victimId)->id;
        $victimConfigId = seedPrivateLlmConfig($victimPrincipalId, 'Victim Runtime Config');

        $globalDefaultId = (int) Manager::table('llm_driver_configurations')->insertGetId([
            'principal_id' => null,
            'name'         => 'Global Default',
            'driver_class' => OpenAICompatibleDriver::class,
            'settings'     => json_encode(['api_key' => 'sk-global', 'model' => 'gpt-4o']),
            'is_default'   => true,
            'is_global'    => true,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        $agentId = seedScopedAgent($callerPrincipalId, 'Runtime Agent');
        Manager::table('agents')->where('id', $agentId)->update(['llm_driver_config_id' => $victimConfigId]);

        $agent = Agent::find($agentId);
        $resolved = (new LLMConfigPreferences($principalService))->getEffectiveConfigForAgent($agent);

        expect($resolved)->not->toBeNull();
        expect((int) $resolved->id)->toBe($globalDefaultId);
    });

    it('does not resolve a foreign speech configuration at run time', function (): void {
        $authService = bootAuthLayer();
        $principalService = new PrincipalService(new PrincipalResolver());
        $callerId = bootAuth($authService, 'agent-scope-speech-runtime@example.com');
        $callerPrincipalId = (int) $principalService->ensureUserPrincipal($callerId)->id;

        $victimId = $authService->register('agent-scope-speech-victim@example.com', 'Password1!', 'Victim');
        $victimPrincipalId = (int) $principalService->ensureUserPrincipal($victimId)->id;
        $victimConfigId = seedPrivateSpeechConfig($victimPrincipalId, 'Victim Speech Runtime Config');

        $globalDefaultId = (int) Manager::table('speech_provider_configurations')->insertGetId([
            'principal_id'   => null,
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'display_name'   => 'Global Speech Default',
            'settings'       => '{}',
            'is_default'     => true,
            'is_global'      => true,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);

        $agentId = seedScopedAgent($callerPrincipalId, 'Speech Runtime Agent', $victimConfigId);

        $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $registry = new SpeechToTextRegistry(
            [new OpenAiCompatibleTranscriber(new MockHttpClient(), new ToolConfigService($security, new \Psr\Log\NullLogger(), []))],
            $principalService,
        );

        $rows = $registry->describeWithConfig($callerId, $agentId);

        expect($rows)->not->toBeEmpty();
        expect($rows[0]['effective_source'])->toBe('global_default');
        expect((int) $rows[0]['effective_config_id'])->toBe($globalDefaultId);
    });
});
