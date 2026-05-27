<?php

declare(strict_types=1);

namespace Tests\Unit\Tools;

use Monolog\Logger;
use Spora\Core\SecurityManager;
use Spora\Models\Agent;
use Spora\Services\ToolConfigService;
use Tests\Fixtures\TestTool;

function makeServiceForSentinel(): array
{
    $authService = bootAuthLayer();
    $key      = random_bytes(\SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new SecurityManager($key);
    $logger   = new Logger('test');
    $service  = new ToolConfigService($security, $logger, [TestTool::class]);

    return [$service, $authService];
}

test('putGlobalSettings preserves password field when sentinel *** is sent', function () {
    [$service] = makeServiceForSentinel();

    // Initial save with real password
    $service->putGlobalSettings(TestTool::class, [
        'api_key' => 'real-global-secret',
        'max_results' => '100',
    ]);

    // Update with sentinel
    $service->putGlobalSettings(TestTool::class, [
        'api_key' => '***',
        'max_results' => '50',
    ]);

    // Should be preserved
    $saved = $service->getGlobalSettings(TestTool::class);
    expect($saved['api_key'])->toBe('real-global-secret')
        ->and($saved['max_results'])->toBe('50');
});

test('putUserSettings preserves password field when sentinel *** is sent', function () {
    [$service, $authService] = makeServiceForSentinel();
    $userId = $authService->register('sentinel-user@example.com', 'Password1!', 'Sentinel User');

    // Initial save with real password
    $service->putUserSettings(TestTool::class, $userId, [
        'api_key' => 'real-user-secret',
    ]);

    // Update with sentinel
    $service->putUserSettings(TestTool::class, $userId, [
        'api_key' => '***',
    ]);

    // Should be preserved
    $saved = $service->getUserSettings(TestTool::class, $userId);
    expect($saved['api_key'])->toBe('real-user-secret');
});

test('putAgentOverride preserves password field when sentinel *** is sent', function () {
    [$service, $authService] = makeServiceForSentinel();
    $userId = $authService->register('sentinel-agent@example.com', 'Password1!', 'Sentinel Agent');

    $agent = new Agent();
    $agent->user_id = $userId;
    $agent->name = 'Sentinel Agent';
    $agent->save();
    $agentId = (int) $agent->id;

    // Initial save with real password
    $service->putAgentOverride(TestTool::class, $agentId, [
        'api_key' => 'real-agent-secret',
    ]);

    // Update with sentinel
    $service->putAgentOverride(TestTool::class, $agentId, [
        'api_key' => '***',
    ]);

    // Should be preserved
    $effective = $service->getEffectiveSettings(TestTool::class, $agentId);
    expect($effective['api_key'])->toBe('real-agent-secret');
});
