<?php

declare(strict_types=1);

namespace Tests\Feature\AgentApi;

use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Http\AgentApi\AgentApiChatController;
use Spora\Http\AgentApi\AgentApiMediaController;
use Spora\Models\TaskHistory;
use Tests\Fixtures\StubInputTool;

/**
 * Files into the agent and media back out to the caller.
 *
 * The upload path shares the media archive with the admin UI, so what
 * matters is that the bytes land attributed to the *key's* agent and that
 * the read side refuses anything outside its scope.
 */
describe('Agent API media', function (): void {
    function mediaContainer(): \Psr\Container\ContainerInterface
    {
        return AgentApiTestSupport::container(
            new ScriptedLlmDriver(new LLMResponse(content: 'x', toolCalls: [], inputTokens: 1, outputTokens: 1, completionId: 'm')),
        );
    }

    /**
     * The smallest byte string the sniffer names as an image, so the agent's
     * accept-list is consulted for `image/png` rather than something exotic.
     */
    function pngBytes(): string
    {
        return "\x89PNG\r\n\x1a\n" . str_repeat("\0", 64);
    }

    it('ingests an upload as the key\'s agent', function (): void {
        $seed = AgentApiTestSupport::seedAgent('upload');
        [, $key] = AgentApiTestSupport::mintKey($seed['agentId'], 'upload');

        $container = mediaContainer();
        $context = new \Spora\Services\AgentApi\AgentApiContext(
            AgentApiTestSupport::mintKey($seed['agentId'], 'probe')[0],
            \Spora\Models\Agent::findOrFail($seed['agentId']),
            $seed['userId'],
        );

        $mediaId = $container->get(\Spora\Services\AgentApi\AgentApiFileIngest::class)
            ->ingestBytes($context, pngBytes(), 'chart.png');

        expect($mediaId)->not->toBeNull();

        $asset = $container->get(\Spora\Services\MediaArchive\MediaArchiveService::class)->find($mediaId);
        expect((int) $asset->agent_id)->toBe($seed['agentId']);
        expect((int) $asset->user_id)->toBe($seed['userId']);
        expect($asset->mime_type)->toBe('image/png');
        expect($key)->toStartWith('spk_');
    });

    it('refuses a type the agent does not accept', function (): void {
        $seed = AgentApiTestSupport::seedAgent('badtype');
        $context = new \Spora\Services\AgentApi\AgentApiContext(
            AgentApiTestSupport::mintKey($seed['agentId'], 'probe')[0],
            \Spora\Models\Agent::findOrFail($seed['agentId']),
            $seed['userId'],
        );

        $container = mediaContainer();
        $ingest = $container->get(\Spora\Services\AgentApi\AgentApiFileIngest::class);

        // Executable content: the sniff names it, the allow-list refuses it.
        expect($ingest->ingestBytes($context, "#!/bin/sh\nrm -rf /\n", 'payload.sh'))->toBeNull();
    });

    it('reads back its own agent\'s media inline', function (): void {
        $seed = AgentApiTestSupport::seedAgent('readback');
        [, $key] = AgentApiTestSupport::mintKey($seed['agentId'], 'readback');

        $container = mediaContainer();
        $context = new \Spora\Services\AgentApi\AgentApiContext(
            AgentApiTestSupport::mintKey($seed['agentId'], 'probe')[0],
            \Spora\Models\Agent::findOrFail($seed['agentId']),
            $seed['userId'],
        );
        $mediaId = $container->get(\Spora\Services\AgentApi\AgentApiFileIngest::class)
            ->ingestBytes($context, pngBytes(), 'chart.png');

        $request = \Symfony\Component\HttpFoundation\Request::create("/api/v1/agent-api/media/{$mediaId}", 'GET');
        $request->attributes->set('agent_api_context', $context);

        $response = $container->get(AgentApiMediaController::class)->show($request, $mediaId);

        expect($response->getStatusCode())->toBe(200);
        expect($response->headers->get('Content-Type'))->toBe('image/png');
        // `inline` rather than the admin route's `attachment`: the point of
        // this route is that a caller can render the bytes.
        expect($response->headers->get('Content-Disposition'))->toStartWith('inline;');
        expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
        expect($key)->toStartWith('spk_');
    });

    it('cannot read another agent\'s media', function (): void {
        $mine = AgentApiTestSupport::seedAgent('mmine');
        $theirs = AgentApiTestSupport::seedAgent('mtheirs');

        $container = mediaContainer();
        $scope = $container->get(\Spora\Services\AgentApi\AgentApiScope::class);

        $theirContext = new \Spora\Services\AgentApi\AgentApiContext(
            AgentApiTestSupport::mintKey($theirs['agentId'], 'probe')[0],
            \Spora\Models\Agent::findOrFail($theirs['agentId']),
            $theirs['userId'],
        );
        $mediaId = $container->get(\Spora\Services\AgentApi\AgentApiFileIngest::class)
            ->ingestBytes($theirContext, pngBytes(), 'theirs.png');

        expect($scope->isMediaReadable($mediaId, $mine['agentId']))->toBeFalse();
        expect($scope->isMediaReadable($mediaId, $theirs['agentId']))->toBeTrue();

        $request = \Symfony\Component\HttpFoundation\Request::create("/api/v1/agent-api/media/{$mediaId}", 'GET');
        $request->attributes->set('agent_api_context', new \Spora\Services\AgentApi\AgentApiContext(
            AgentApiTestSupport::mintKey($mine['agentId'], 'probe')[0],
            \Spora\Models\Agent::findOrFail($mine['agentId']),
            $mine['userId'],
        ));

        $response = $container->get(AgentApiMediaController::class)->show($request, $mediaId);

        expect($response->getStatusCode())->toBe(404);
    });

    it('404s an unknown media id rather than 403', function (): void {
        $seed = AgentApiTestSupport::seedAgent('unknown');
        $container = mediaContainer();
        $request = \Symfony\Component\HttpFoundation\Request::create('/api/v1/agent-api/media/nope', 'GET');
        $request->attributes->set('agent_api_context', new \Spora\Services\AgentApi\AgentApiContext(
            AgentApiTestSupport::mintKey($seed['agentId'], 'probe')[0],
            \Spora\Models\Agent::findOrFail($seed['agentId']),
            $seed['userId'],
        ));

        expect($container->get(AgentApiMediaController::class)->show($request, 'nope')->getStatusCode())->toBe(404);
    });

    it('reads a sub-agent\'s asset through its parent\'s scope', function (): void {
        $parent = AgentApiTestSupport::seedAgent('parent');
        $child = AgentApiTestSupport::seedAgent('child');

        $container = mediaContainer();
        $childContext = new \Spora\Services\AgentApi\AgentApiContext(
            AgentApiTestSupport::mintKey($child['agentId'], 'probe')[0],
            \Spora\Models\Agent::findOrFail($child['agentId']),
            $child['userId'],
        );
        $mediaId = $container->get(\Spora\Services\AgentApi\AgentApiFileIngest::class)
            ->ingestBytes($childContext, pngBytes(), 'from-child.png');

        $parentTask = \Spora\Models\Task::create([
            'agent_id' => $parent['agentId'],
            'principal_id' => $parent['principalId'],
            'status' => 'RUNNING',
            'user_prompt' => 'delegate',
        ]);
        $childTask = \Spora\Models\Task::create([
            'agent_id' => $child['agentId'],
            'principal_id' => $child['principalId'],
            'status' => 'COMPLETED',
            'user_prompt' => 'do it',
            'parent_task_id' => $parentTask->id,
        ]);

        \Illuminate\Database\Capsule\Manager::table('media_assets')
            ->where('id', $mediaId)
            ->update(['task_id' => $childTask->id, 'agent_id' => null]);

        // Without the subtree rule a sub-agent's output would be unreachable
        // from the parent that delegated to it.
        expect($container->get(\Spora\Services\AgentApi\AgentApiScope::class)
            ->isMediaReadable($mediaId, $parent['agentId']))->toBeTrue();
    });

    it('reads an asset one of its own chats attached', function (): void {
        $agent = AgentApiTestSupport::seedAgent('attached');
        $container = mediaContainer();

        $context = new \Spora\Services\AgentApi\AgentApiContext(
            AgentApiTestSupport::mintKey($agent['agentId'], 'probe')[0],
            \Spora\Models\Agent::findOrFail($agent['agentId']),
            $agent['userId'],
        );
        $mediaId = $container->get(\Spora\Services\AgentApi\AgentApiFileIngest::class)
            ->ingestBytes($context, pngBytes(), 'uploaded.png');

        // An inbound upload is attributed to the agent but carries no task_id;
        // the only link back to a chat is the history row.
        $task = \Spora\Models\Task::create([
            'agent_id' => $agent['agentId'],
            'principal_id' => $agent['principalId'],
            'status' => 'COMPLETED',
            'user_prompt' => 'look at this',
        ]);
        TaskHistory::create([
            'task_id' => $task->id,
            'sequence' => 0,
            'role' => 'attachment',
            'content' => '',
            'attachments' => [['media_id' => $mediaId, 'kind' => 'image']],
        ]);

        expect($container->get(\Spora\Services\AgentApi\AgentApiScope::class)
            ->isMediaReadable($mediaId, $agent['agentId']))->toBeTrue();
    });

    it('decodes an inline data URL and ignores what it should not', function (): void {
        $seed = AgentApiTestSupport::seedAgent('decode');
        $context = new \Spora\Services\AgentApi\AgentApiContext(
            AgentApiTestSupport::mintKey($seed['agentId'], 'probe')[0],
            \Spora\Models\Agent::findOrFail($seed['agentId']),
            $seed['userId'],
        );

        $ingest = mediaContainer()->get(\Spora\Services\AgentApi\AgentApiFileIngest::class);
        $png = pngBytes();
        $good = 'data:image/png;base64,' . base64_encode($png);

        // Driven through the public entry point rather than the decoder, so
        // what is asserted is what a caller actually gets.
        expect($ingest->ingestInlineFiles($context, [['filename' => 'a.png', 'data_url' => $good]]))->toHaveCount(1);

        // A URL that is not a data URL, a non-base64 data URL, undecodable
        // base64, and an entry that is not an object are all skipped rather
        // than raising.
        expect($ingest->ingestInlineFiles($context, [
            ['filename' => 'b.png', 'data_url' => 'https://example.com/x.png'],
            ['filename' => 'c.png', 'data_url' => 'data:image/png,notbase64'],
            ['filename' => 'd.png', 'data_url' => 'data:image/png;base64,!!!'],
            ['filename' => 'e.png'],
            'not-an-object',
        ]))->toBe([]);

        // A bare base64 payload without the data: prefix is accepted too.
        expect($ingest->ingestInlineFiles($context, [
            ['filename' => 'f.png', 'base64' => base64_encode($png)],
        ]))->toHaveCount(1);
    });

    it('refuses an inline file above the size ceiling', function (): void {
        $seed = AgentApiTestSupport::seedAgent('toobig');
        $context = new \Spora\Services\AgentApi\AgentApiContext(
            AgentApiTestSupport::mintKey($seed['agentId'], 'probe')[0],
            \Spora\Models\Agent::findOrFail($seed['agentId']),
            $seed['userId'],
        );

        $container = mediaContainer();
        $ingest = $container->get(\Spora\Services\AgentApi\AgentApiFileIngest::class);

        // 16 MiB is the ceiling on what is persisted. The bytes are decoded in
        // full before the check, so this is not a memory bound — `post_max_size`
        // is.
        $oversized = 'data:image/png;base64,' . base64_encode(str_repeat('a', 17 * 1024 * 1024));

        expect($ingest->ingestInlineFiles($context, [['filename' => 'big.bin', 'data_url' => $oversized]]))->toBe([]);
    });

    it('attaches an inline file to a new chat', function (): void {
        $seed = AgentApiTestSupport::seedAgent('inline');
        [, $key] = AgentApiTestSupport::mintKey($seed['agentId'], 'inline');

        $llm = new ScriptedLlmDriver(new LLMResponse(content: 'ok', toolCalls: [], inputTokens: 1, outputTokens: 1, completionId: 'i'));
        $container = AgentApiTestSupport::container($llm, null, [new StubInputTool()]);

        $png = pngBytes();
        $response = AgentApiTestSupport::call(
            $container,
            AgentApiChatController::class,
            'store',
            AgentApiTestSupport::bearer($key, [
                'text' => 'describe this',
                'files' => [[
                    'filename' => 'chart.png',
                    'data_url' => 'data:image/png;base64,' . base64_encode($png),
                ]],
            ]),
        );

        expect($response->getStatusCode())->toBe(202);

        $chatId = (int) AgentApiTestSupport::decode($response)['data']['chat']['id'];
        $attachments = TaskHistory::where('task_id', $chatId)->where('role', 'attachment')->get();

        expect($attachments)->toHaveCount(1);

        // Asserted through the projection rather than the raw column: what a
        // caller receives is the resolved URL, which is the part that can be
        // wrong even when the stored row looks fine.
        $messages = (new \Spora\Services\AgentApi\AgentApiChatProjection())
            ->messages($chatId, null, 'https://spora.test');

        $refs = $messages[0]['attachments'];
        expect($refs)->toHaveCount(1);
        expect($refs[0]['kind'])->toBe('image');
        expect($refs[0]['media_id'])->not->toBeEmpty();
        expect($refs[0]['url'])->toStartWith('https://spora.test/api/v1/agent-api/media/');
    });
});
