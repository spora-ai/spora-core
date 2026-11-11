<?php

declare(strict_types=1);

namespace Tests\Feature\ApiKeys;

use Spora\Http\ScopedApi\ScopedApiGroupController;
use Spora\Models\Group;
use Spora\Models\GroupMembership;
use Tests\Feature\AgentApi\AgentApiTestSupport;

/**
 * Group CRUD through the global surface.
 *
 * A group key may read its own group and nothing else; the global key does
 * all of it. The group it creates is owned by the admin who minted the key,
 * which is what keeps the last-owner guard meaningful.
 */
describe('group CRUD on the global surface', function (): void {
    it('lists every group', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        AgentApiTestSupport::seedGroup('global-list-a');
        AgentApiTestSupport::seedGroup('global-list-b');
        [, $key] = AgentApiTestSupport::mintGlobalKey('global-list');

        $response = ScopedApiTestSupport::callGlobal(
            $container,
            ScopedApiGroupController::class,
            'index',
            ScopedApiTestSupport::bearer($key, ScopedApiTestSupport::GLOBAL_PREFIX . '/groups', [], 'GET'),
        );

        expect($response->getStatusCode())->toBe(200);
        expect(count(ScopedApiTestSupport::data($response)['groups']))->toBeGreaterThanOrEqual(2);
    });

    it('creates a group owned by the admin who minted the key', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $admin = AgentApiTestSupport::seedAgent('global-owner');
        [, $key] = AgentApiTestSupport::mintGlobalKey('global-create', $admin['userId']);

        $response = ScopedApiTestSupport::callGlobal(
            $container,
            ScopedApiGroupController::class,
            'store',
            ScopedApiTestSupport::bearer(
                $key,
                ScopedApiTestSupport::GLOBAL_PREFIX . '/groups',
                ['name' => 'Made over the API', 'description' => 'by a global key'],
            ),
        );

        expect($response->getStatusCode())->toBe(201);

        $groupId = ScopedApiTestSupport::data($response)['group']['id'];
        $group = Group::findOrFail($groupId);

        expect($group->name)->toBe('Made over the API');
        expect((int) $group->created_by_user_id)->toBe($admin['userId']);

        // The creator is the group's owner, not merely a member.
        $role = GroupMembership::where('group_id', $groupId)
            ->where('user_id', $admin['userId'])
            ->value('role');
        expect($role)->toBe(GroupMembership::ROLE_OWNER);
    });

    it('refuses to create a group without an owner', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        [, $key] = AgentApiTestSupport::mintGlobalKey('global-no-owner');

        $response = ScopedApiTestSupport::callGlobal(
            $container,
            ScopedApiGroupController::class,
            'store',
            ScopedApiTestSupport::bearer(
                $key,
                ScopedApiTestSupport::GLOBAL_PREFIX . '/groups',
                ['name' => 'Ownerless'],
            ),
        );

        expect($response->getStatusCode())->toBe(403);
    });

    it('reads and updates a group', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $group = AgentApiTestSupport::seedGroup('global-update');
        [, $key] = AgentApiTestSupport::mintGlobalKey('global-update');

        $read = ScopedApiTestSupport::callGlobal(
            $container,
            ScopedApiGroupController::class,
            'show',
            ScopedApiTestSupport::bearer($key, ScopedApiTestSupport::GLOBAL_PREFIX . '/groups/1', [], 'GET'),
            [$group['groupId']],
        );

        expect($read->getStatusCode())->toBe(200);
        expect(ScopedApiTestSupport::data($read)['group']['id'])->toBe($group['groupId']);

        $update = ScopedApiTestSupport::callGlobal(
            $container,
            ScopedApiGroupController::class,
            'update',
            ScopedApiTestSupport::bearer(
                $key,
                ScopedApiTestSupport::GLOBAL_PREFIX . '/groups/1',
                ['description' => 'A new description'],
                'PATCH',
            ),
            [$group['groupId']],
        );

        expect($update->getStatusCode())->toBe(200);
        expect(Group::findOrFail($group['groupId'])->description)->toBe('A new description');
    });

    it('writes the group information the payload names', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $group = AgentApiTestSupport::seedGroup('global-information');
        [, $key] = AgentApiTestSupport::mintGlobalKey('global-information');

        $update = ScopedApiTestSupport::callGlobal(
            $container,
            ScopedApiGroupController::class,
            'update',
            ScopedApiTestSupport::bearer(
                $key,
                ScopedApiTestSupport::GLOBAL_PREFIX . '/groups/1',
                ['information' => "## Address\nMusterstraße 1"],
                'PATCH',
            ),
            [$group['groupId']],
        );

        expect($update->getStatusCode())->toBe(200);
        expect(Group::findOrFail($group['groupId'])->information)->toBe("## Address\nMusterstraße 1");
    });

    it('refuses an information value that is not a string', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $group = AgentApiTestSupport::seedGroup('global-information-type');
        [, $key] = AgentApiTestSupport::mintGlobalKey('global-information-type');

        $update = ScopedApiTestSupport::callGlobal(
            $container,
            ScopedApiGroupController::class,
            'update',
            ScopedApiTestSupport::bearer(
                $key,
                ScopedApiTestSupport::GLOBAL_PREFIX . '/groups/1',
                ['information' => ['x']],
                'PATCH',
            ),
            [$group['groupId']],
        );

        expect($update->getStatusCode())->toBe(422);
    });

    it('refuses to delete a group that still owns agents', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $group = AgentApiTestSupport::seedGroup('global-delete-agents');
        [, $key] = AgentApiTestSupport::mintGlobalKey('global-delete');

        $response = ScopedApiTestSupport::callGlobal(
            $container,
            ScopedApiGroupController::class,
            'destroy',
            ScopedApiTestSupport::bearer($key, ScopedApiTestSupport::GLOBAL_PREFIX . '/groups/1', [], 'DELETE'),
            [$group['groupId']],
        );

        expect($response->getStatusCode())->toBe(409);
        expect(Group::find($group['groupId']))->not->toBeNull();
    });

    it('refuses to delete a group that still owns a media folder', function (): void {
        // A folder is not an agent: the transfer endpoint cannot re-target
        // one, so the answer names the folder route and the agent payload
        // is absent.
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $group = AgentApiTestSupport::seedGroup('global-delete-folders', 0);
        [, $key] = AgentApiTestSupport::mintGlobalKey('global-delete-folders');

        $folder = (new \Spora\Services\MediaArchive\MediaFolderService())->create(
            $group['principalId'],
            'Corporate',
        );

        $response = ScopedApiTestSupport::callGlobal(
            $container,
            ScopedApiGroupController::class,
            'destroy',
            ScopedApiTestSupport::bearer($key, ScopedApiTestSupport::GLOBAL_PREFIX . '/groups/1', [], 'DELETE'),
            [$group['groupId']],
        );

        expect($response->getStatusCode())->toBe(409);
        $body = json_decode($response->getContent(), true);

        expect($body['error']['code'])->toBe('GROUP_HAS_MEDIA_FOLDERS');
        expect($body['error']['folder_ids'])->toBe([(string) $folder->id]);
        expect($body['error'])->not->toHaveKey('agent_ids');
        expect(Group::find($group['groupId']))->not->toBeNull();
    });

    it('deletes a group that owns no agents', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $group = AgentApiTestSupport::seedGroup('global-delete', 0);
        [, $key] = AgentApiTestSupport::mintGlobalKey('global-delete');

        $response = ScopedApiTestSupport::callGlobal(
            $container,
            ScopedApiGroupController::class,
            'destroy',
            ScopedApiTestSupport::bearer($key, ScopedApiTestSupport::GLOBAL_PREFIX . '/groups/1', [], 'DELETE'),
            [$group['groupId']],
        );

        expect($response->getStatusCode())->toBe(200);
        expect(Group::find($group['groupId']))->toBeNull();
    });
});

describe('the group surface reads one group', function (): void {
    it('reads the group the key belongs to', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $group = AgentApiTestSupport::seedGroup('own-group');
        [, $key] = AgentApiTestSupport::mintGroupKey($group['groupId'], 'own-group');

        $response = ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiGroupController::class,
            'ownGroup',
            ScopedApiTestSupport::bearer($key, ScopedApiTestSupport::GROUP_PREFIX . '/group', [], 'GET'),
        );

        expect($response->getStatusCode())->toBe(200);
        expect(ScopedApiTestSupport::data($response)['group']['id'])->toBe($group['groupId']);
    });
});
