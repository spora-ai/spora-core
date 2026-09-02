<?php

declare(strict_types=1);

namespace Tests\Feature\AgentPictures;

use RuntimeException;
use Spora\Http\AgentController;
use Spora\Services\AgentPictures\AgentPictureService;
use Spora\Services\AgentServiceInterface;
use Spora\Services\AgentToolSettingsServiceInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Feature tests for the `profile_picture` nested object on
 * PATCH /api/v1/agents/{id}.
 *
 * Uses the same in-memory DB + auth helper pattern as AgentShowTest, so
 * the controller's AgentServiceInterface / AgentToolSettingsServiceInterface
 * stubs are duplicated here rather than refactored (the existing test
 * pattern is deliberately inline).
 */
function buildProfilePictureAgentController(): AgentController
{
    $agentService = new class implements AgentServiceInterface {
        public function getAgentsForUser(int $userId, ?array $principalIds = null): array
        {
            return [];
        }
        public function createAgent(int $userId, array $data, ?int $principalId = null): \Spora\Models\Agent
        {
            throw new RuntimeException('not implemented in test');
        }
        public function getAgent(int $agentId, int $userId): ?\Spora\Models\Agent
        {
            return \Spora\Models\Agent::query()->find($agentId);
        }
        public function updateAgent(int $agentId, int $userId, array $data): ?\Spora\Models\Agent
        {
            // Touch the agent's row so the in-memory DB has something to return.
            $agent = \Spora\Models\Agent::query()->find($agentId);
            if ($agent === null) {
                return null;
            }
            $agent->name = $data['name'] ?? $agent->name;
            $agent->save();
            return $agent->refresh();
        }
        public function updateAgentByAgentId(int $agentId, array $data): ?\Spora\Models\Agent
        {
            return \Spora\Models\Agent::query()->find($agentId);
        }
        public function getAgentByAgentId(int $agentId): ?\Spora\Models\Agent
        {
            return \Spora\Models\Agent::query()->find($agentId);
        }
        public function deleteAgent(int $agentId, int $userId): bool
        {
            return true;
        }
        public function setPinned(int $userId, int $agentId, bool $pinned): \Spora\Models\Agent
        {
            return \Spora\Models\Agent::query()->find($agentId) ?? throw new RuntimeException('not found');
        }
        public function setArchived(int $userId, int $agentId, bool $archived): \Spora\Models\Agent
        {
            return \Spora\Models\Agent::query()->find($agentId) ?? throw new RuntimeException('not found');
        }
        public function setFavorite(int $userId, int $agentId): \Spora\Models\Agent
        {
            throw new RuntimeException('not used');
        }
        public function unsetFavorite(int $userId, int $agentId): \Spora\Models\Agent
        {
            throw new RuntimeException('not used');
        }
        public function transferAgent(int $agentId, int $targetPrincipalId, int $callerUserId): \Spora\Models\Agent
        {
            throw new RuntimeException('not implemented in test');
        }
    };
    $toolSettings = new class implements AgentToolSettingsServiceInterface {
        /** @phpstan-ignore return.unusedType */
        public function enableTool(int $agentId, int $userId, string $toolClass): array
        {
            return ['tool' => [], 'warning' => ''];
        }
        public function disableTool(int $agentId, int $userId, string $toolClass): void {}
        public function getToolStatus(int $agentId, int $userId, string $toolClass): ?array
        {
            return null;
        }
        public function getAllToolsStatus(int $agentId, int $userId): ?array
        {
            return null;
        }
        public function getOverride(int $agentId, int $userId, string $toolClass, bool $rawOnly = false): array
        {
            return [];
        }
        public function putOverride(int $agentId, int $userId, string $toolClass, array $settings): array
        {
            return [];
        }
        public function deleteOverride(int $agentId, int $userId, string $toolClass): void {}
        public function getToolsOperations(int $agentId, int $userId): ?array
        {
            return null;
        }
        /** @phpstan-ignore return.unusedType */
        public function getOperationOverride(int $agentId, int $userId, string $toolClass, string $operation): array
        {
            return [
                'operation' => $operation,
                'tool_class' => $toolClass,
                'enabled' => null,
                'default_requires_approval' => null,
                'effective_enabled' => false,
                'effective_requires_approval' => false,
            ];
        }
        public function patchOperationOverride(int $agentId, int $userId, string $toolClass, string $operation, array $data): array
        {
            return [];
        }
    };
    return new AgentController(
        bootAuthLayer(),
        $agentService,
        null,
        null,
        new AgentPictureService(),
    );
}

function seedProfilePictureAgent(int $id, int $userId): void
{
    \Spora\Models\Agent::query()->insert([
        'id' => $id,
        'principal_id' => createUserPrincipalPublic($userId),
        'name' => "agent-{$id}",
        'description' => '',
        'system_prompt' => '',
        'max_steps' => 5,
        'is_active' => 1,
        'allow_followup' => 1,
        'retry_after_minutes' => 0,
        'max_retries' => 0,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
}

function patchProfilePicture(int $agentId, array $body): \Symfony\Component\HttpFoundation\Response
{
    $req = Request::create(
        "/api/v1/agents/{$agentId}",
        'PATCH',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        json_encode($body),
    );
    $req->attributes->set('id', $agentId);
    return buildProfilePictureAgentController()->update($req);
}

test('PATCH /agents/{id} writes profile_picture on first call', function (): void {
    $userId = bootAuth(bootAuthLayer());
    seedProfilePictureAgent(7, $userId);

    $resp = patchProfilePicture(7, [
        'name' => 'Researcher Bot',
        'profile_picture' => [
            'archetype' => 'researcher',
            'variant_key' => 'v1',
            'palette_key' => 'violet',
        ],
    ]);

    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode($resp->getContent(), true);
    expect($body['data']['agent']['profile_picture']['archetype'])->toBe('researcher');
    expect($body['data']['agent']['profile_picture']['variant_key'])->toBe('v1');
    expect($body['data']['agent']['profile_picture']['palette_key'])->toBe('violet');
});

test('PATCH /agents/{id} rejects an unknown archetype with 422', function (): void {
    $userId = bootAuth(bootAuthLayer());
    seedProfilePictureAgent(8, $userId);

    $resp = patchProfilePicture(8, [
        'profile_picture' => ['archetype' => 'astronaut'],
    ]);

    expect($resp->getStatusCode())->toBe(422);
    $body = json_decode($resp->getContent(), true);
    expect($body['error']['code'])->toBe('PROFILE_PICTURE_VALUE');
});

test('PATCH /agents/{id} rejects an unknown palette_key with 422', function (): void {
    $userId = bootAuth(bootAuthLayer());
    seedProfilePictureAgent(9, $userId);

    $resp = patchProfilePicture(9, [
        'profile_picture' => ['palette_key' => 'rainbow'],
    ]);

    expect($resp->getStatusCode())->toBe(422);
});

test('PATCH /agents/{id} rejects an unknown variant_key with 422', function (): void {
    $userId = bootAuth(bootAuthLayer());
    seedProfilePictureAgent(10, $userId);

    $resp = patchProfilePicture(10, [
        'profile_picture' => ['variant_key' => 'v9'],
    ]);

    expect($resp->getStatusCode())->toBe(422);
});

test('PATCH /agents/{id} rejects unknown profile_picture fields with 422', function (): void {
    $userId = bootAuth(bootAuthLayer());
    seedProfilePictureAgent(11, $userId);

    $resp = patchProfilePicture(11, [
        'profile_picture' => ['image_url' => 'https://example.com/x.png'],
    ]);

    expect($resp->getStatusCode())->toBe(422);
    $body = json_decode($resp->getContent(), true);
    expect($body['error']['code'])->toBe('PROFILE_PICTURE_UNKNOWN_KEY');
});

test('PATCH /agents/{id} profile_picture changes survive the agents table update', function (): void {
    $userId = bootAuth(bootAuthLayer());
    seedProfilePictureAgent(12, $userId);

    patchProfilePicture(12, [
        'name' => 'Updated',
        'profile_picture' => ['archetype' => 'researcher', 'palette_key' => 'violet'],
    ]);

    $resp = patchProfilePicture(12, [
        'name' => 'Updated Again',
        'profile_picture' => ['archetype' => 'analyst'],
    ]);

    $body = json_decode($resp->getContent(), true);
    expect($body['data']['agent']['name'])->toBe('Updated Again');
    expect($body['data']['agent']['profile_picture']['archetype'])->toBe('analyst');
    expect($body['data']['agent']['profile_picture']['palette_key'])->toBe('violet');
});

test('PATCH /agents/{id} rejects a string profile_picture with 422 and leaves the name unchanged', function (): void {
    $userId = bootAuth(bootAuthLayer());
    seedProfilePictureAgent(13, $userId);
    \Spora\Models\Agent::query()->find(13)->update(['name' => 'Original']);

    $resp = patchProfilePicture(13, [
        'name'             => 'Renamed',
        'profile_picture'  => 'not-an-object',
    ]);

    expect($resp->getStatusCode())->toBe(422);
    expect(json_decode($resp->getContent(), true)['error']['code'])->toBe('PROFILE_PICTURE_TYPE');
    // Critical: the agents row is unchanged. The picture payload was
    // validated BEFORE the name write so a 422 doesn't partial-update.
    $agent = \Spora\Models\Agent::query()->find(13);
    expect($agent->name)->toBe('Original');
});

test('PATCH /agents/{id} rejects a null profile_picture with 422 and leaves the name unchanged', function (): void {
    $userId = bootAuth(bootAuthLayer());
    seedProfilePictureAgent(14, $userId);
    \Spora\Models\Agent::query()->find(14)->update(['name' => 'Original']);

    $resp = patchProfilePicture(14, [
        'name'             => 'Renamed',
        'profile_picture'  => null,
    ]);

    expect($resp->getStatusCode())->toBe(422);
    expect(json_decode($resp->getContent(), true)['error']['code'])->toBe('PROFILE_PICTURE_TYPE');
    $agent = \Spora\Models\Agent::query()->find(14);
    expect($agent->name)->toBe('Original');
});

test('PATCH /agents/{id} with an invalid picture key does not overwrite the agents row', function (): void {
    $userId = bootAuth(bootAuthLayer());
    seedProfilePictureAgent(15, $userId);
    \Spora\Models\Agent::query()->find(15)->update(['name' => 'Original']);

    $resp = patchProfilePicture(15, [
        'name'             => 'Renamed',
        'profile_picture'  => ['archetype' => 'astronaut'],
    ]);

    expect($resp->getStatusCode())->toBe(422);
    expect(json_decode($resp->getContent(), true)['error']['code'])->toBe('PROFILE_PICTURE_VALUE');
    $agent = \Spora\Models\Agent::query()->find(15);
    expect($agent->name)->toBe('Original');
});
