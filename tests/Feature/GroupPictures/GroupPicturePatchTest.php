<?php

declare(strict_types=1);

namespace Tests\Feature\GroupPictures;

use Spora\Http\GroupController;
use Spora\Services\GroupService;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Spora\Services\ProfilePictures\GroupPictureService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Feature tests for the `profile_picture` nested object on
 * PATCH /api/v1/groups/{id}.
 *
 * Mirrors {@see \Tests\Feature\AgentPictures\AgentPicturePatchTest} —
 * the operator's PATCH body uses the same shape for both agents and
 * groups (the only difference is which service the picture lands on).
 */
function buildGroupPicturePatchController(): GroupController
{
    $principalService = new PrincipalService(new PrincipalResolver());
    $groupService = new GroupService($principalService);
    $pictureService = new GroupPictureService();

    return new GroupController(
        bootAuthLayer(),
        $groupService,
        $principalService,
        $pictureService,
    );
}

function seedGroupForPicture(int $id, int $userId): void
{
    \Illuminate\Database\Capsule\Manager::table('groups')->insert([
        'id' => $id,
        'name' => "group-{$id}",
        'description' => null,
        'created_by_user_id' => $userId,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    \Illuminate\Database\Capsule\Manager::table('principals')->insert([
        'type' => 'group',
        'group_id' => $id,
        'user_id' => null,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
}

function patchGroupPicture(int $groupId, array $body): \Symfony\Component\HttpFoundation\Response
{
    $req = Request::create(
        "/api/v1/groups/{$groupId}",
        'PATCH',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        json_encode($body),
    );
    $req->attributes->set('id', $groupId);
    return buildGroupPicturePatchController()->update($groupId, $req);
}

test('PATCH /groups/{id} writes profile_picture on first call', function (): void {
    $userId = bootAuth(bootAuthLayer());
    seedGroupForPicture(7, $userId);

    $resp = patchGroupPicture(7, [
        'name' => 'Research',
        'profile_picture' => [
            'archetype' => 'researcher',
            'variant_key' => 'v1',
            'palette_key' => 'violet',
        ],
    ]);

    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode($resp->getContent(), true);
    expect($body['data']['group']['profile_picture']['archetype'])->toBe('researcher');
    expect($body['data']['group']['profile_picture']['variant_key'])->toBe('v1');
    expect($body['data']['group']['profile_picture']['palette_key'])->toBe('violet');
});

test('PATCH /groups/{id} rejects an unknown archetype with 422', function (): void {
    $userId = bootAuth(bootAuthLayer());
    seedGroupForPicture(8, $userId);

    $resp = patchGroupPicture(8, [
        'profile_picture' => ['archetype' => 'astronaut'],
    ]);

    expect($resp->getStatusCode())->toBe(422);
    $body = json_decode($resp->getContent(), true);
    expect($body['error']['code'])->toBe('PROFILE_PICTURE_VALUE');
});

test('PATCH /groups/{id} rejects an unknown palette_key with 422', function (): void {
    $userId = bootAuth(bootAuthLayer());
    seedGroupForPicture(9, $userId);

    $resp = patchGroupPicture(9, [
        'profile_picture' => ['palette_key' => 'rainbow'],
    ]);

    expect($resp->getStatusCode())->toBe(422);
});

test('PATCH /groups/{id} rejects an unknown variant_key with 422', function (): void {
    $userId = bootAuth(bootAuthLayer());
    seedGroupForPicture(10, $userId);

    $resp = patchGroupPicture(10, [
        'profile_picture' => ['variant_key' => 'v9'],
    ]);

    expect($resp->getStatusCode())->toBe(422);
});

test('PATCH /groups/{id} rejects unknown profile_picture fields with 422', function (): void {
    $userId = bootAuth(bootAuthLayer());
    seedGroupForPicture(11, $userId);

    $resp = patchGroupPicture(11, [
        'profile_picture' => ['image_url' => 'https://example.com/x.png'],
    ]);

    expect($resp->getStatusCode())->toBe(422);
    $body = json_decode($resp->getContent(), true);
    expect($body['error']['code'])->toBe('PROFILE_PICTURE_UNKNOWN_KEY');
});

test('PATCH /groups/{id} profile_picture changes survive the groups table update', function (): void {
    $userId = bootAuth(bootAuthLayer());
    seedGroupForPicture(12, $userId);

    patchGroupPicture(12, [
        'name' => 'Updated',
        'profile_picture' => ['archetype' => 'researcher', 'palette_key' => 'violet'],
    ]);

    $resp = patchGroupPicture(12, [
        'name' => 'Updated Again',
        'profile_picture' => ['archetype' => 'ensemble'],
    ]);

    $body = json_decode($resp->getContent(), true);
    expect($body['data']['group']['name'])->toBe('Updated Again');
    expect($body['data']['group']['profile_picture']['archetype'])->toBe('ensemble');
    expect($body['data']['group']['profile_picture']['palette_key'])->toBe('violet');
});

test('PATCH /groups/{id} rejects a string profile_picture with 422 and leaves the name unchanged', function (): void {
    $userId = bootAuth(bootAuthLayer());
    seedGroupForPicture(13, $userId);
    \Illuminate\Database\Capsule\Manager::table('groups')->where('id', 13)->update(['name' => 'Original']);

    $resp = patchGroupPicture(13, [
        'name'             => 'Renamed',
        'profile_picture'  => 'not-an-object',
    ]);

    expect($resp->getStatusCode())->toBe(422);
    expect(json_decode($resp->getContent(), true)['error']['code'])->toBe('PROFILE_PICTURE_TYPE');
    // Critical: the groups row is unchanged. The picture payload was
    // validated BEFORE the name write so a 422 doesn't partial-update.
    $group = \Spora\Models\Group::find(13);
    expect($group->name)->toBe('Original');
});

test('PATCH /groups/{id} rejects a null profile_picture with 422 and leaves the name unchanged', function (): void {
    $userId = bootAuth(bootAuthLayer());
    seedGroupForPicture(14, $userId);
    \Illuminate\Database\Capsule\Manager::table('groups')->where('id', 14)->update(['name' => 'Original']);

    $resp = patchGroupPicture(14, [
        'name'             => 'Renamed',
        'profile_picture'  => null,
    ]);

    expect($resp->getStatusCode())->toBe(422);
    expect(json_decode($resp->getContent(), true)['error']['code'])->toBe('PROFILE_PICTURE_TYPE');
    $group = \Spora\Models\Group::find(14);
    expect($group->name)->toBe('Original');
});

test('PATCH /groups/{id} with an invalid picture key does not overwrite the groups row', function (): void {
    $userId = bootAuth(bootAuthLayer());
    seedGroupForPicture(15, $userId);
    \Illuminate\Database\Capsule\Manager::table('groups')->where('id', 15)->update(['name' => 'Original']);

    $resp = patchGroupPicture(15, [
        'name'             => 'Renamed',
        'profile_picture'  => ['archetype' => 'astronaut'],
    ]);

    expect($resp->getStatusCode())->toBe(422);
    expect(json_decode($resp->getContent(), true)['error']['code'])->toBe('PROFILE_PICTURE_VALUE');
    $group = \Spora\Models\Group::find(15);
    expect($group->name)->toBe('Original');
});
