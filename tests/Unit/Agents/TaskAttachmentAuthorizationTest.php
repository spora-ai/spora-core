<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Agents\Orchestrator;
use Spora\Agents\OrchestratorConfig;
use Spora\Agents\TaskStartOptions;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\LLMDriverInterface;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Models\MediaAsset;
use Spora\Models\Task;
use Spora\Services\MediaArchive\MediaNotOwnedException;
use Spora\Services\PrincipalResolver;

defined('TEST_PASSWORD') || define('TEST_PASSWORD', 'Password1!');

/**
 * A task is shared, so the identity that authorizes its attachments is the
 * caller who sent the turn — not whoever started the task.
 */
function attachmentAuthOrchestrator(): Orchestrator
{
    $driver = Mockery::mock(LLMDriverInterface::class);
    $driver->allows('complete')->andReturn(new LLMResponse('Done.', [], 10, 5, 'cmp_1'));
    $driver->allows('getProviderName')->andReturn('mock');
    $driver->allows('getModelName')->andReturn('mock-model');
    $driver->allows('supportsImageInput')->andReturn(false);

    $factory = Mockery::mock(DriverFactory::class);
    $factory->allows('makeFromAgent')->andReturn($driver);

    return new Orchestrator($factory, new OrchestratorConfig());
}

/**
 * A group with two members, so both can act for the group principal.
 *
 * @return array{0: int, 1: int, 2: int} [groupPrincipalId, memberOneId, memberTwoId]
 */
function attachmentAuthGroup(): array
{
    $auth    = bootAuthLayer();
    $memberOne = $auth->register('attach-one@example.com', TEST_PASSWORD, 'Attach One');
    $memberTwo = $auth->register('attach-two@example.com', TEST_PASSWORD, 'Attach Two');

    $groupId = (int) Capsule::table('groups')->insertGetId([
        'name'               => 'Attachment Group',
        'description'        => null,
        'created_by_user_id' => $memberOne,
        'created_at'         => date('Y-m-d H:i:s'),
        'updated_at'         => date('Y-m-d H:i:s'),
    ]);

    foreach ([$memberOne, $memberTwo] as $memberId) {
        Capsule::table('group_memberships')->insert([
            'group_id'   => $groupId,
            'user_id'    => $memberId,
            'role'       => 'member',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    $groupPrincipalId = (int) Capsule::table('principals')->insertGetId([
        'type'       => 'group',
        'group_id'   => $groupId,
        'user_id'    => null,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    // Both members need their user-principal: that is how a user "acts as"
    // the group principal.
    createUserPrincipalPublic($memberOne);
    createUserPrincipalPublic($memberTwo);

    return [$groupPrincipalId, $memberOne, $memberTwo];
}

function attachmentAuthAsset(?int $userId, ?int $principalId, ?int $agentId): string
{
    $id = testGenerateUuidV4();

    MediaAsset::create([
        'id'                => $id,
        'asset_url'         => '/api/v1/assets/' . $id . '.txt',
        'storage_mode'      => 'data_url',
        'mime_type'         => 'text/plain',
        'media_type'        => 'text',
        'byte_size'         => 4,
        'agent_id'          => $agentId,
        'principal_id'      => $principalId,
        'user_id'           => $userId,
        'plugin_slug'       => null,
        'asset_token'       => bin2hex(random_bytes(16)),
        'public_access_token' => null,
        'filename'          => 'note.txt',
        'payload'           => 'body',
        'migrated_from_inline_data_url' => false,
    ]);

    return $id;
}

it('refuses a continuation that attaches another member\'s private upload', function (): void {
    [$groupPrincipalId, $memberOne, $memberTwo] = attachmentAuthGroup();

    $agentId = (int) Capsule::table('agents')->insertGetId([
        'principal_id' => $groupPrincipalId,
        'name'         => 'Shared Agent',
        'max_steps'    => 10,
        'is_active'    => true,
        'created_at'   => date('Y-m-d H:i:s'),
        'updated_at'   => date('Y-m-d H:i:s'),
    ]);

    // Member one's own personal upload.
    $privateAssetId = attachmentAuthAsset($memberOne, createUserPrincipalPublic($memberOne), null);

    $orchestrator = attachmentAuthOrchestrator();
    $task = $orchestrator->start($agentId, 'First turn', options: new TaskStartOptions(userId: $memberOne));
    Task::where('id', $task->id)->update(['status' => 'COMPLETED']);

    // Member two continues the shared task and reaches for member one's
    // private upload. The task's starter is attribution, not authority.
    expect(static fn() => $orchestrator->continue($task->id, 'Second turn', mediaIds: [$privateAssetId], userId: $memberTwo))
        ->toThrow(MediaNotOwnedException::class);
});

it('refuses an attachment whose asset has no attribution at all', function (): void {
    [$groupPrincipalId, $memberOne] = attachmentAuthGroup();

    $agentId = (int) Capsule::table('agents')->insertGetId([
        'principal_id' => $groupPrincipalId,
        'name'         => 'Shared Agent',
        'max_steps'    => 10,
        'is_active'    => true,
        'created_at'   => date('Y-m-d H:i:s'),
        'updated_at'   => date('Y-m-d H:i:s'),
    ]);

    $orphanAssetId = attachmentAuthAsset(null, null, null);

    $orchestrator = attachmentAuthOrchestrator();

    expect(static fn() => $orchestrator->start($agentId, 'Attach it', options: new TaskStartOptions(mediaIds: [$orphanAssetId], userId: $memberOne)))
        ->toThrow(MediaNotOwnedException::class);
});

it('lets a group member attach the group\'s own media', function (): void {
    [$groupPrincipalId, $memberOne, $memberTwo] = attachmentAuthGroup();

    $agentId = (int) Capsule::table('agents')->insertGetId([
        'principal_id' => $groupPrincipalId,
        'name'         => 'Shared Agent',
        'max_steps'    => 10,
        'is_active'    => true,
        'created_at'   => date('Y-m-d H:i:s'),
        'updated_at'   => date('Y-m-d H:i:s'),
    ]);

    // Filed under the group principal — every member may read it.
    $groupAssetId = attachmentAuthAsset($memberOne, $groupPrincipalId, null);

    $orchestrator = attachmentAuthOrchestrator();
    $task = $orchestrator->start($agentId, 'First turn', options: new TaskStartOptions(userId: $memberOne));
    Task::where('id', $task->id)->update(['status' => 'COMPLETED']);

    $continued = $orchestrator->continue($task->id, 'Second turn', mediaIds: [$groupAssetId], userId: $memberTwo);

    expect($continued->id)->toBe($task->id);
});

it('resolves the runner from the executing task, not the agent\'s newest one', function (): void {
    [$groupPrincipalId, $memberOne, $memberTwo] = attachmentAuthGroup();

    $agentId = (int) Capsule::table('agents')->insertGetId([
        'principal_id' => $groupPrincipalId,
        'name'         => 'Shared Agent',
        'max_steps'    => 10,
        'is_active'    => true,
        'created_at'   => date('Y-m-d H:i:s'),
        'updated_at'   => date('Y-m-d H:i:s'),
    ]);

    $orchestrator = attachmentAuthOrchestrator();
    $olderTask = $orchestrator->start($agentId, 'Older turn', options: new TaskStartOptions(userId: $memberOne));
    $newerTask = $orchestrator->start($agentId, 'Newer turn', options: new TaskStartOptions(userId: $memberTwo));

    $resolver = new PrincipalResolver();

    expect($resolver->runnerUserId($agentId, $olderTask->id))->toBe($memberOne)
        ->and($resolver->runnerUserId($agentId, $newerTask->id))->toBe($memberTwo)
        ->and($resolver->resolveForToolExecute($agentId, $olderTask->id)->runnerUserId)->toBe($memberOne);
});

it('falls back to the agent-wide runner when the task carries no trigger user', function (): void {
    [$groupPrincipalId, $memberOne] = attachmentAuthGroup();

    $agentId = (int) Capsule::table('agents')->insertGetId([
        'principal_id' => $groupPrincipalId,
        'name'         => 'Shared Agent',
        'max_steps'    => 10,
        'is_active'    => true,
        'created_at'   => date('Y-m-d H:i:s'),
        'updated_at'   => date('Y-m-d H:i:s'),
    ]);

    $orchestrator = attachmentAuthOrchestrator();
    $task = $orchestrator->start($agentId, 'A turn', options: new TaskStartOptions(userId: $memberOne));

    // A scheduled task: no trigger user on the row.
    Task::where('id', $task->id)->update(['trigger_user_id' => null]);

    $resolver = new PrincipalResolver();

    expect($resolver->runnerUserId($agentId, $task->id))->toBeNull();
});
