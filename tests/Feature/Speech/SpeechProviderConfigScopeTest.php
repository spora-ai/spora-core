<?php

declare(strict_types=1);

namespace Tests\Feature\Speech;

use Illuminate\Database\Capsule\Manager;
use Spora\Auth\AuthService;
use Spora\Core\SecurityManager;
use Spora\Http\SpeechProviderConfigController;
use Spora\Models\GroupMembership;
use Spora\Services\AgentPrincipalService;
use Spora\Services\AgentService;
use Spora\Services\GroupService;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Spora\Services\SpeechProviderConfigPersistence;
use Spora\Services\SpeechProviderConfigPreferences;
use Spora\Services\SpeechProviderConfigService;
use Spora\Services\SpeechProviderConfigValidator;
use Spora\Services\ToolConfigService;
use Spora\Speech\OpenAiCompatibleTranscriber;
use Spora\Speech\SpeechToTextRegistry;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Response;

/**
 * A speech provider configuration belongs to the principal that pays for
 * it. Writing one under a group needs authority over that group — being a
 * member of it is not enough.
 */
function buildScopedSpeechConfigController(AuthService $authService): array
{
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $principalResolver = new PrincipalResolver();
    $principalService = new PrincipalService($principalResolver);
    $registry = new SpeechToTextRegistry(
        [new OpenAiCompatibleTranscriber(new MockHttpClient(), new ToolConfigService($security, new \Psr\Log\NullLogger(), []))],
        $principalService,
    );
    $validator = new SpeechProviderConfigValidator($registry);
    $persistence = new SpeechProviderConfigPersistence($security, $validator, static fn(): SpeechToTextRegistry => $registry);
    $preferences = new SpeechProviderConfigPreferences($principalService);
    $service = new SpeechProviderConfigService($validator, $persistence, $preferences, $principalService);

    $agentService = new AgentService(
        null,
        null,
        $principalResolver,
        new AgentPrincipalService($principalService),
    );

    $controller = new SpeechProviderConfigController(
        $authService,
        $service,
        $registry,
        $agentService,
        $principalResolver,
        $principalService,
    );

    return [$controller, $service, $principalService];
}

function speechScopeGroup(PrincipalService $principalService, AuthService $authService, GroupService $groupService): array
{
    $ownerId = $authService->register('speech-scope-owner@example.com', 'Password1!', 'Owner');
    $group = $groupService->createGroup($ownerId, 'Speech Scope Group');
    $groupPrincipalId = (int) $principalService->principalForGroup((int) $group->id)->id;

    $memberId = $authService->register('speech-scope-member@example.com', 'Password1!', 'Member');
    $groupService->addMember((int) $group->id, $memberId, GroupMembership::ROLE_MEMBER, $ownerId);

    // The member's own user-principal: without it the member has no
    // visible principals at all, and the group configuration would be
    // invisible rather than visible-but-unmanageable.
    $principalService->ensureUserPrincipal($memberId);
    $principalService->ensureUserPrincipal($ownerId);

    return [$ownerId, $memberId, $groupPrincipalId];
}

describe('speech provider configuration creation stays inside the caller\'s authority', function (): void {
    beforeEach(function (): void {
        clearSession();
    });

    afterEach(function (): void {
        clearSession();
    });

    it('does not create a group-owned configuration for an ordinary group member', function (): void {
        $authService = bootAuthLayer();
        $principalService = new PrincipalService(new PrincipalResolver());
        $groupService = new GroupService($principalService);
        [$ownerId, $memberId, $groupPrincipalId] = speechScopeGroup($principalService, $authService, $groupService);

        [$controller] = buildScopedSpeechConfigController($authService);

        simulateLoggedInSession($memberId, 'speech-scope-member@example.com');

        $request = jsonRequest('POST', '/api/v1/speech/provider-configs', [
            'scope'        => 'user',
            'principal_id' => $groupPrincipalId,
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'settings'     => [
                'api_key'      => 'sk-member',
                'display_name' => 'Member Config',
            ],
        ]);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_CREATED);

        $stored = Manager::table('speech_provider_configurations')->orderByDesc('id')->first();
        expect((int) $stored->principal_id)->not->toBe($groupPrincipalId);
        expect((int) $stored->principal_id)
            ->toBe((int) $principalService->ensureUserPrincipal($memberId)->id);
    });

    it('still creates a group-owned configuration for the group owner', function (): void {
        $authService = bootAuthLayer();
        $principalService = new PrincipalService(new PrincipalResolver());
        $groupService = new GroupService($principalService);
        [$ownerId, , $groupPrincipalId] = speechScopeGroup($principalService, $authService, $groupService);

        [$controller] = buildScopedSpeechConfigController($authService);

        simulateLoggedInSession($ownerId, 'speech-scope-owner@example.com');

        $request = jsonRequest('POST', '/api/v1/speech/provider-configs', [
            'scope'        => 'user',
            'principal_id' => $groupPrincipalId,
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'settings'     => [
                'api_key'      => 'sk-owner',
                'display_name' => 'Owner Config',
            ],
        ]);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_CREATED);

        $stored = Manager::table('speech_provider_configurations')->orderByDesc('id')->first();
        expect((int) $stored->principal_id)->toBe($groupPrincipalId);
    });

    it('refuses a group-owned configuration when the service is called directly', function (): void {
        $authService = bootAuthLayer();
        $principalService = new PrincipalService(new PrincipalResolver());
        $groupService = new GroupService($principalService);
        [, $memberId, $groupPrincipalId] = speechScopeGroup($principalService, $authService, $groupService);

        [, $service] = buildScopedSpeechConfigController($authService);

        $threw = false;
        try {
            $service->createConfiguration($memberId, [
                'principal_id'   => $groupPrincipalId,
                'provider_class' => OpenAiCompatibleTranscriber::class,
                'settings'       => [
                    'api_key'      => 'sk-member',
                    'display_name' => 'Member Config',
                ],
            ], false);
        } catch (\Spora\Services\Exceptions\PrincipalNotAccessibleException) {
            $threw = true;
        }

        expect($threw)->toBeTrue();
        expect(Manager::table('speech_provider_configurations')->count())->toBe(0);
    });
});
