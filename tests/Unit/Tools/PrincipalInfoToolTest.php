<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\Principal;
use Spora\Services\PrincipalContext;
use Spora\Tools\PrincipalInfoTool;

/**
 * The tool answers for the principal behind the agent.
 *
 * A user principal resolves to the user's own row; a group principal to the
 * group's row. The health data is the one thing a group does not have, and
 * the refusal has to say so — a blank field would be read as "no
 * measurement recorded".
 */
beforeEach(function (): void {
    $this->tool = new PrincipalInfoTool();

    $this->userId = (int) Capsule::table('users')->insertGetId([
        'email'      => 'principal-info-user@example.com',
        'password'   => str_repeat("\0", 60),
        'username'   => 'alice',
        'status'     => 0,
        'verified'   => 1,
        'resettable' => 1,
        'roles_mask' => 0,
        'registered' => time(),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $this->principalId = (int) Capsule::table('principals')->insertGetId([
        'type'       => Principal::TYPE_USER,
        'user_id'    => $this->userId,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $this->agentId = (int) Capsule::table('agents')->insertGetId([
        'principal_id' => $this->principalId,
        'name'         => 'Test Agent',
        'created_at'   => date('Y-m-d H:i:s'),
        'updated_at'   => date('Y-m-d H:i:s'),
    ]);

    $this->groupId = (int) Capsule::table('groups')->insertGetId([
        'name'               => 'Acme Corp',
        'description'        => 'We build things',
        'information'        => "## Address\nMusterstraße 1, 10115 Berlin",
        'created_by_user_id' => $this->userId,
        'created_at'         => date('Y-m-d H:i:s'),
        'updated_at'         => date('Y-m-d H:i:s'),
    ]);

    $this->groupPrincipalId = (int) Capsule::table('principals')->insertGetId([
        'type'       => Principal::TYPE_GROUP,
        'group_id'   => $this->groupId,
        'user_id'    => null,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $this->groupAgentId = (int) Capsule::table('agents')->insertGetId([
        'principal_id' => $this->groupPrincipalId,
        'name'         => 'Group Agent',
        'created_at'   => date('Y-m-d H:i:s'),
        'updated_at'   => date('Y-m-d H:i:s'),
    ]);
});

it('answers for the user behind the agent', function (): void {
    Capsule::table('users')->where('id', $this->userId)->update([
        'name'          => 'Alice',
        'about_me'      => 'Hello world',
        'date_of_birth' => '1990-05-15',
    ]);

    $result = $this->tool->execute(['action' => 'get_base_data'], $this->agentId);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toBe(
            "Principal Type: user\n"
            . "Name: Alice\n"
            . "Description: Hello world\n"
            . "Date of Birth: 1990-05-15\n",
        );
});

it('answers for the group behind the agent', function (): void {
    $result = $this->tool->execute(['action' => 'get_base_data'], $this->groupAgentId);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toBe(
            "Principal Type: group\n"
            . "Name: Acme Corp\n"
            . "Description: We build things\n"
            . "Information:\n"
            . "## Address\n"
            . "Musterstraße 1, 10115 Berlin\n",
        );
});

it('marks an absent value as not set', function (): void {
    $result = $this->tool->execute(['action' => 'get_base_data'], $this->agentId);

    expect($result->content)->toBe(
        "Principal Type: user\n"
        . "Name: (not set)\n"
        . "Description: (not set)\n"
        . "Date of Birth: (not set)\n",
    );
});

it('omits the date of birth for a group and marks an absent information as not set', function (): void {
    Capsule::table('groups')->where('id', $this->groupId)->update(['information' => null]);

    $result = $this->tool->execute(['action' => 'get_base_data'], $this->groupAgentId);

    expect($result->content)->toBe(
        "Principal Type: group\n"
        . "Name: Acme Corp\n"
        . "Description: We build things\n"
        . "Information:\n"
        . "(not set)\n",
    );
});

it('answers with the health data of a user', function (): void {
    Capsule::table('users')->where('id', $this->userId)->update([
        'height_cm' => 175,
        'weight_kg' => 70.5,
    ]);

    $result = $this->tool->execute(['action' => 'get_health_data'], $this->agentId);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toBe("Health Data:\nHeight: 175 cm\nWeight: 70.5 kg\n");
});

it('refuses the health data of a group', function (): void {
    $result = $this->tool->execute(['action' => 'get_health_data'], $this->groupAgentId);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toBe(
            'Health data is only available for user principals. This agent belongs to a group.',
        );
});

it('lists the locations of the principal', function (): void {
    Capsule::table('principal_locations')->insert([
        'principal_id' => $this->principalId,
        'name'         => 'HQ',
        'address'      => 'Musterstraße 1, 10115 Berlin',
        'is_default'   => true,
        'created_at'   => date('Y-m-d H:i:s'),
        'updated_at'   => date('Y-m-d H:i:s'),
    ]);

    $result = $this->tool->execute(['action' => 'get_locations'], $this->agentId);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toBe(
            "Locations:\n\n— HQ (default)\n  Address: Musterstraße 1, 10115 Berlin\n\n",
        );
});

it('lists the locations of a group principal', function (): void {
    Capsule::table('principal_locations')->insert([
        'principal_id' => $this->groupPrincipalId,
        'name'         => 'Warehouse',
        'address'      => 'Hafenstraße 2',
        'is_default'   => false,
        'created_at'   => date('Y-m-d H:i:s'),
        'updated_at'   => date('Y-m-d H:i:s'),
    ]);

    $result = $this->tool->execute(['action' => 'get_locations'], $this->groupAgentId);

    expect($result->content)->toBe("Locations:\n\n— Warehouse\n  Address: Hafenstraße 2\n\n");
});

it('reports that a principal has no locations', function (): void {
    $result = $this->tool->execute(['action' => 'get_locations'], $this->agentId);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toBe('No locations saved.');
});

it('answers for the principal the context names, not the one the agent owns', function (): void {
    Capsule::table('users')->where('id', $this->userId)->update(['name' => 'Alice']);

    $context = new PrincipalContext(
        principalId: $this->groupPrincipalId,
        type: Principal::TYPE_GROUP,
        ownerUserId: $this->userId,
        runnerUserId: $this->userId,
    );

    $result = $this->tool->execute(['action' => 'get_base_data'], $this->agentId, null, $context);

    expect($result->content)->toContain('Principal Type: group');
});

it('reports a principal it cannot resolve', function (): void {
    $result = $this->tool->execute(['action' => 'get_base_data'], 999_999_999);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toBe('Principal not found.');
});

it('reports an unresolvable context as a missing principal', function (): void {
    $context = new PrincipalContext(
        principalId: 0,
        type: Principal::TYPE_USER,
        ownerUserId: null,
        runnerUserId: null,
    );

    $result = $this->tool->execute(['action' => 'get_base_data'], $this->agentId, null, $context);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toBe('Principal not found.');
});

it('describes what each operation will do in principal wording', function (): void {
    expect($this->tool->describeAction(['action' => 'get_base_data']))
        ->toBe('Read the base data of the principal (name, description, date of birth)')
        ->and($this->tool->describeAction(['action' => 'get_locations']))
        ->toBe('Read the locations saved for the principal')
        ->and($this->tool->describeAction(['action' => 'get_health_data']))
        ->toBe('Read the health measurements of the principal (height, weight)');
});

it('offers the three operations in the schema', function (): void {
    $schema = $this->tool->getParametersSchema();

    expect($schema['properties']['action']['enum'])->toBe([
        'get_base_data',
        'get_locations',
        'get_health_data',
    ]);
});
