<?php

declare(strict_types=1);

namespace Tests\Feature\ApiKeys;

use Spora\Http\ScopedApi\ScopedApiAgentController;
use Spora\Models\Agent;
use Tests\Feature\AgentApi\AgentApiTestSupport;

/**
 * Agent CRUD through the scoped surfaces, in the Agent Template format.
 *
 * The payload is the one `POST /api/v1/agent-templates/import` accepts, so
 * the format is pinned here as well: a create takes it verbatim, an update
 * takes it as a merge, and a read returns it in export shape.
 */
describe('agent CRUD on the group surface', function (): void {
    function templatePayload(string $suffix = 'scoped'): array
    {
        return [
            'id' => "scoped/{$suffix}",
            'name' => "Scoped Agent {$suffix}",
            'version' => '1.0.0',
            'agent' => [
                'system_prompt' => 'Be helpful.',
                'description' => 'An agent created over the API.',
                'max_steps' => 5,
                'allow_followup' => true,
            ],
        ];
    }

    it('creates an agent in the group from an import payload', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $group = AgentApiTestSupport::seedGroup('crud-create');
        [, $key] = AgentApiTestSupport::mintGroupKey($group['groupId'], 'crud');

        $response = ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiAgentController::class,
            'store',
            ScopedApiTestSupport::bearer($key, ScopedApiTestSupport::GROUP_PREFIX . '/agents', templatePayload()),
        );

        expect($response->getStatusCode())->toBe(201);

        $agentId = ScopedApiTestSupport::data($response)['agent']['id'];
        $agent = Agent::findOrFail($agentId);

        expect($agent->name)->toBe('Scoped Agent scoped');
        expect($agent->system_prompt)->toBe('Be helpful.');
        // The agent belongs to the group's principal, not to the key's owner.
        expect((int) $agent->principal_id)->toBe($group['principalId']);
    });

    it('lists the agents of the group and nothing else', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $group = AgentApiTestSupport::seedGroup('crud-list', 2);
        $other = AgentApiTestSupport::seedGroup('crud-list-other', 1);
        [, $key] = AgentApiTestSupport::mintGroupKey($group['groupId'], 'crud');

        $response = ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiAgentController::class,
            'index',
            ScopedApiTestSupport::bearer($key, ScopedApiTestSupport::GROUP_PREFIX . '/agents', [], 'GET'),
        );

        expect($response->getStatusCode())->toBe(200);
        expect(ScopedApiTestSupport::data($response)['total'])->toBe(2);

        $ids = array_column(ScopedApiTestSupport::data($response)['agents'], 'id');
        expect($ids)->not->toContain($other['agentIds'][0]);
    });

    it('reads an agent back in the export shape', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $group = AgentApiTestSupport::seedGroup('crud-read');
        [, $key] = AgentApiTestSupport::mintGroupKey($group['groupId'], 'crud');

        Agent::findOrFail($group['agentIds'][0])->forceFill([
            'system_prompt' => 'Be helpful.',
        ])->save();

        $response = ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiAgentController::class,
            'show',
            ScopedApiTestSupport::bearer($key, ScopedApiTestSupport::GROUP_PREFIX . '/agents/1', [], 'GET'),
            [$group['agentIds'][0]],
        );

        expect($response->getStatusCode())->toBe(200);

        // The export shape is the import shape: what the API reads back can
        // be fed straight back to it.
        $agent = ScopedApiTestSupport::data($response)['agent'];
        expect($agent)->toHaveKeys(['id', 'name', 'version', 'agent']);
        expect($agent['agent']['system_prompt'])->toBe('Be helpful.');
    });

    it('merges an update and leaves the fields it does not name alone', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $group = AgentApiTestSupport::seedGroup('crud-update');
        [, $key] = AgentApiTestSupport::mintGroupKey($group['groupId'], 'crud');
        $agentId = $group['agentIds'][0];

        $before = Agent::findOrFail($agentId);

        $response = ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiAgentController::class,
            'update',
            ScopedApiTestSupport::bearer(
                $key,
                ScopedApiTestSupport::GROUP_PREFIX . '/agents/' . $agentId,
                [
                    'name' => 'Renamed agent',
                    'agent' => [
                        'system_prompt' => 'A new prompt.',
                        'description' => 'A new description.',
                        'notes' => 'Some notes.',
                        'max_steps' => 7,
                        'allow_followup' => false,
                        'retry_after_minutes' => 3,
                        'max_retries' => 2,
                    ],
                ],
                'PATCH',
            ),
            [$agentId],
        );

        expect($response->getStatusCode())->toBe(200);

        $after = Agent::findOrFail($agentId);
        expect($after->system_prompt)->toBe('A new prompt.');
        expect($after->name)->toBe('Renamed agent');
        expect($after->description)->toBe('A new description.');
        expect($after->notes)->toBe('Some notes.');
        expect((int) $after->max_steps)->toBe(7);
        expect((bool) $after->allow_followup)->toBeFalse();
        expect((int) $after->retry_after_minutes)->toBe(3);
        expect((int) $after->max_retries)->toBe(2);
        // The principal the agent belongs to is not the template's to change.
        expect((int) $after->principal_id)->toBe((int) $before->principal_id);
    });

    it('404s an agent outside the group\'s reach', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $group = AgentApiTestSupport::seedGroup('crud-outside');
        $other = AgentApiTestSupport::seedGroup('crud-outside-other');
        [, $key] = AgentApiTestSupport::mintGroupKey($group['groupId'], 'crud');

        $response = ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiAgentController::class,
            'show',
            ScopedApiTestSupport::bearer($key, ScopedApiTestSupport::GROUP_PREFIX . '/agents/1', [], 'GET'),
            [$other['agentIds'][0]],
        );

        expect($response->getStatusCode())->toBe(404);
    });

    it('deletes an agent in the group', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $group = AgentApiTestSupport::seedGroup('crud-delete');
        [, $key] = AgentApiTestSupport::mintGroupKey($group['groupId'], 'crud');
        $agentId = $group['agentIds'][0];

        $response = ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiAgentController::class,
            'destroy',
            ScopedApiTestSupport::bearer($key, ScopedApiTestSupport::GROUP_PREFIX . '/agents/1', [], 'DELETE'),
            [$agentId],
        );

        expect($response->getStatusCode())->toBe(200);
        expect(Agent::find($agentId))->toBeNull();
    });

    it('refuses a payload that is not an import format', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $group = AgentApiTestSupport::seedGroup('crud-invalid');
        [, $key] = AgentApiTestSupport::mintGroupKey($group['groupId'], 'crud');

        $response = ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiAgentController::class,
            'store',
            ScopedApiTestSupport::bearer(
                $key,
                ScopedApiTestSupport::GROUP_PREFIX . '/agents',
                ['name' => 'No agent block'],
            ),
        );

        expect($response->getStatusCode())->toBe(422);
    });
});
