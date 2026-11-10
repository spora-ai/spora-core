<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\Agent;
use Spora\Models\Principal;
use Spora\Services\Exceptions\PromptExpansionTooLargeException;
use Spora\Services\PromptTemplateExpander;

it('substitutes a stored default for its placeholder', function (): void {
    $expander = new PromptTemplateExpander();

    expect($expander->expand('Hello {{name}}!', [
        ['key' => 'name', 'default_value' => 'World'],
    ]))->toBe('Hello World!');
});

it('keeps an unknown placeholder as written', function (): void {
    $expander = new PromptTemplateExpander();

    expect($expander->expand('Hello {{name}}!', []))->toBe('Hello {{name}}!');
});

it('refuses an expansion that repeats one default past the ceiling', function (): void {
    $expander = new PromptTemplateExpander();

    // Each occurrence copies the default in full, so the expanded string
    // grows as the product of the two. Without a ceiling this is a
    // multi-gigabyte allocation in the request process.
    $template = str_repeat('{{blob}}', 4_000);
    $default  = str_repeat('x', 200_000);

    expect(static fn() => $expander->expand($template, [
        ['key' => 'blob', 'default_value' => $default],
    ]))->toThrow(PromptExpansionTooLargeException::class);
});

it('refuses a template that is already over the ceiling', function (): void {
    $expander = new PromptTemplateExpander();

    $template = str_repeat('x', PromptTemplateExpander::MAX_EXPANDED_BYTES + 1);

    expect(static fn() => $expander->expand($template, []))
        ->toThrow(PromptExpansionTooLargeException::class);
});

it('accepts an expansion that stays under the ceiling', function (): void {
    $expander = new PromptTemplateExpander();

    $template = str_repeat('{{blob}}', 4);
    $default  = str_repeat('x', 1_000);

    expect(strlen($expander->expand($template, [
        ['key' => 'blob', 'default_value' => $default],
    ])))->toBe(4_000);
});

describe('the principal placeholders', function (): void {
    beforeEach(function (): void {
        $this->expander = new PromptTemplateExpander();

        $this->userId = (int) Capsule::table('users')->insertGetId([
            'email'      => 'expander-user@example.com',
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

    it('resolves the four placeholders for a user', function (): void {
        Capsule::table('users')->where('id', $this->userId)->update([
            'name'     => 'Alice',
            'about_me' => 'Hello world',
        ]);

        $expanded = $this->expander->expand(
            '{{principal_type}}|{{principal_name}}|{{principal_description}}|{{principal_information}}',
            [],
            Agent::find($this->agentId),
        );

        expect($expanded)->toBe('user|Alice|Hello world|Hello world');
    });

    it('resolves the four placeholders for a group', function (): void {
        $expanded = $this->expander->expand(
            '{{principal_type}}|{{principal_name}}|{{principal_description}}|{{principal_information}}',
            [],
            Agent::find($this->groupAgentId),
        );

        expect($expanded)->toBe('group|Acme Corp|We build things|## Address' . "\n" . 'Musterstraße 1, 10115 Berlin');
    });

    it('falls back to the username when the user has no display name', function (): void {
        $expanded = $this->expander->expand('{{principal_name}}', [], Agent::find($this->agentId));

        expect($expanded)->toBe('alice');
    });

    it('falls back to the placeholder name when there is neither a name nor a username', function (): void {
        Capsule::table('users')->where('id', $this->userId)->update(['name' => null, 'username' => '']);

        $expanded = $this->expander->expand('{{principal_name}}', [], Agent::find($this->agentId));

        expect($expanded)->toBe('principal_name');
    });

    it('resolves an unset content field to an empty string', function (): void {
        $expanded = $this->expander->expand(
            '[{{principal_description}}][{{principal_information}}]',
            [],
            Agent::find($this->agentId),
        );

        expect($expanded)->toBe('[][]');
    });

    it('resolves an unresolvable principal to the placeholder names and empty content', function (): void {
        $agent = new Agent(['principal_id' => 999_999_999, 'name' => 'Orphaned Agent']);

        $expanded = $this->expander->expand(
            '{{principal_type}}|{{principal_name}}|{{principal_description}}',
            [],
            $agent,
        );

        expect($expanded)->toBe('principal_type|principal_name|');
    });

    it('leaves the agent and user placeholders as they were', function (): void {
        $expanded = $this->expander->expand(
            '{{agent_name}}|{{user_name}}',
            [],
            Agent::find($this->agentId),
        );

        expect($expanded)->toBe('Test Agent|alice');
    });

    it('names the group owner for the user placeholder of a group principal', function (): void {
        $ownerId = (int) Capsule::table('users')->insertGetId([
            'email'      => 'expander-owner@example.com',
            'password'   => str_repeat("\0", 60),
            'username'   => 'owner',
            'status'     => 0,
            'verified'   => 1,
            'resettable' => 1,
            'roles_mask' => 0,
            'registered' => time(),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        Capsule::table('group_memberships')->insert([
            'group_id'   => $this->groupId,
            'user_id'    => $ownerId,
            'role'       => 'owner',
            'joined_at'  => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $expanded = $this->expander->expand('{{user_name}}', [], Agent::find($this->groupAgentId));

        expect($expanded)->toBe('owner');
    });

    it('reads the principal once for a template naming every placeholder', function (): void {
        Capsule::connection()->enableQueryLog();

        $this->expander->expand(
            '{{principal_type}}{{principal_name}}{{principal_description}}{{principal_information}}',
            [],
            Agent::find($this->agentId),
        );

        $queries = Capsule::connection()->getQueryLog();
        Capsule::connection()->disableQueryLog();

        $principalReads = array_filter(
            $queries,
            static fn(array $query): bool => str_contains(strtolower((string) $query['query']), 'principals'),
        );

        expect($principalReads)->toHaveCount(1);
    });

    it('does not answer the next expansion from the previous one', function (): void {
        $this->expander->expand('{{principal_name}}', [], Agent::find($this->agentId));

        $expanded = $this->expander->expand('{{principal_name}}', [], Agent::find($this->groupAgentId));

        expect($expanded)->toBe('Acme Corp');
    });
});
