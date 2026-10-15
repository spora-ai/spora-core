<?php

declare(strict_types=1);

namespace Tests\Unit\Tools;

use Monolog\Logger;
use Spora\Core\SecurityManager;
use Spora\Http\ToolController;
use Spora\Services\ToolConfigOverrides;
use Spora\Services\ToolConfigService;
use Spora\Tools\SharedSettingsResolver;
use Symfony\Component\HttpFoundation\Request;

/**
 * `GET /api/v1/tools/{toolId}/settings` is authenticated but **not**
 * admin-gated. `inherited` therefore carries the plugin-wide credential to
 * every logged-in user, and the masking is the only thing standing between
 * that and a leak introduced by this feature.
 *
 * The first test greps the serialised response body for the plaintext shared
 * key on purpose: asserting `$body['data']['inherited']['api_key'] === '***'`
 * would still pass if the same plaintext appeared anywhere else in the
 * payload, so it proves less than it looks like it proves.
 */

const SHARED_PLAINTEXT = 'super-secret-shared-key';

/**
 * The route binds `{toolId}`; `getSettings()` reads it off the request
 * attributes rather than as a method argument.
 */
function requestWithToolId(string $method, string $uri, string $toolId): Request
{
    $request = jsonRequest($method, $uri);
    $request->attributes->set('toolId', $toolId);

    return $request;
}

function inheritedController(): array
{
    $authService = bootAuthLayer();
    $security    = new SecurityManager(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $toolConfig  = new ToolConfigService(
        $security,
        new Logger('test'),
        [AcmeChatTool::class, AcmeStandaloneTool::class, AcmeMistypedTool::class],
        new ToolConfigOverrides(
            sharedSettings: new SharedSettingsResolver([AcmeSharedSettings::class]),
        ),
    );

    $controller = new ToolController(
        $authService,
        $toolConfig,
        [AcmeChatTool::class, AcmeStandaloneTool::class, AcmeMistypedTool::class],
        null,
        null,
        new SharedSettingsResolver([AcmeSharedSettings::class]),
    );

    return [$controller, $authService, $toolConfig];
}

it('never puts the plaintext shared secret anywhere in the response body', function (): void {
    [$controller, $authService, $toolConfig] = inheritedController();

    $userId = $authService->register('inherited-leak@example.com', 'Password1!', 'Leak Check');
    simulateLoggedInSession($userId, 'inherited-leak@example.com');

    $toolConfig->putGlobalSettings(AcmeSharedSettings::class, [
        'api_key' => SHARED_PLAINTEXT,
        'region'   => 'eu-west',
    ]);

    $response = callController(
        $controller,
        'getSettings',
        requestWithToolId('GET', '/api/v1/tools/acme_chat/settings', 'acme_chat'),
    );

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->not->toContain(SHARED_PLAINTEXT);
});

it('masks the shared secret but reports the plain shared values', function (): void {
    [$controller, $authService, $toolConfig] = inheritedController();

    $userId = $authService->register('inherited-shape@example.com', 'Password1!', 'Shape Check');
    simulateLoggedInSession($userId, 'inherited-shape@example.com');

    $toolConfig->putGlobalSettings(AcmeSharedSettings::class, [
        'api_key' => SHARED_PLAINTEXT,
        'region'   => 'eu-west',
    ]);

    $body = json_decode(callController(
        $controller,
        'getSettings',
        requestWithToolId('GET', '/api/v1/tools/acme_chat/settings', 'acme_chat'),
    )->getContent(), true);

    expect($body['data']['inherited'])->toBe(['api_key' => '***', 'region' => 'eu-west']);
});

it('reports the shared values even when the tool overrides the key itself', function (): void {
    // "Inherited" is about what the group holds, not about which level is
    // currently winning — the form needs the group value to render its
    // read-only row either way.
    [$controller, $authService, $toolConfig] = inheritedController();

    $userId = $authService->register('inherited-overridden@example.com', 'Password1!', 'Override Check');
    simulateLoggedInSession($userId, 'inherited-overridden@example.com');

    $toolConfig->putGlobalSettings(AcmeSharedSettings::class, ['api_key' => SHARED_PLAINTEXT]);
    $toolConfig->putGlobalSettings(AcmeChatTool::class, ['api_key' => 'tool-own-key']);

    $body = json_decode(callController(
        $controller,
        'getSettings',
        requestWithToolId('GET', '/api/v1/tools/acme_chat/settings', 'acme_chat'),
    )->getContent(), true);

    expect($body['data']['settings']['api_key'])->toBe('***')
        ->and($body['data']['inherited']['api_key'])->toBe('***');
});

it('sends an empty inherited map for a tool that names no group', function (): void {
    [$controller, $authService, $toolConfig] = inheritedController();

    $userId = $authService->register('inherited-none@example.com', 'Password1!', 'No Group');
    simulateLoggedInSession($userId, 'inherited-none@example.com');

    $toolConfig->putGlobalSettings(AcmeSharedSettings::class, ['api_key' => SHARED_PLAINTEXT]);

    $body = json_decode(callController(
        $controller,
        'getSettings',
        requestWithToolId('GET', '/api/v1/tools/acme_standalone/settings', 'acme_standalone'),
    )->getContent(), true);

    expect($body['data']['inherited'])->toBe([])
        ->and($body['data'])->not->toHaveKey('missing_required');
});

it('never leaks the secret when the tool misdeclares the shared key as plain text', function (): void {
    // AcmeMistypedTool types `api_key` as `text` while the group types it as
    // `password`. Masking by the tool's own schema would publish the plugin
    // credential to every logged-in user, so this asserts the masking keys
    // off the group.
    [$controller, $authService, $toolConfig] = inheritedController();

    $userId = $authService->register('inherited-mistyped@example.com', 'Password1!', 'Mistyped');
    simulateLoggedInSession($userId, 'inherited-mistyped@example.com');

    $toolConfig->putGlobalSettings(AcmeSharedSettings::class, ['api_key' => SHARED_PLAINTEXT]);

    $response = callController(
        $controller,
        'getSettings',
        requestWithToolId('GET', '/api/v1/tools/acme_mistyped/settings', 'acme_mistyped'),
    );

    expect(json_decode($response->getContent(), true)['data']['inherited']['api_key'])->toBe('***')
        ->and($response->getContent())->not->toContain(SHARED_PLAINTEXT);
});
