<?php

declare(strict_types=1);

namespace Tests\Feature\ApiKeys;

use Spora\Http\ScopedApi\ScopedApiMediaController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Tests\Feature\AgentApi\AgentApiTestSupport;

/**
 * Files into one of the group's agents and media back out to the caller.
 *
 * The upload path shares the media archive with the admin UI, so what
 * matters is that the bytes land attributed to an agent the key reaches and
 * that the read side refuses anything outside it.
 */
describe('media on the scoped surfaces', function (): void {
    /**
     * A multipart upload for one of the group's agents.
     */
    function uploadRequest(string $key, int $agentId, string $filename = 'chart.png', ?string $bytes = null): Request
    {
        $path = tempnam(sys_get_temp_dir(), 'spora-upload');
        file_put_contents($path, $bytes ?? ScopedApiTestSupport::pngBytes());

        $request = Request::create(
            '/api/v1/group-api/media',
            'POST',
            ['agent_id' => $agentId],
            [],
            ['file' => new UploadedFile($path, $filename, 'image/png', null, true)],
        );
        $request->headers->set('Authorization', 'Bearer ' . $key);

        return $request;
    }

    it('ingests an upload as one of the group\'s agents', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $group = AgentApiTestSupport::seedGroup('media-upload');
        [, $key] = AgentApiTestSupport::mintGroupKey($group['groupId'], 'media');

        $response = ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiMediaController::class,
            'store',
            uploadRequest($key, $group['agentIds'][0]),
        );

        expect($response->getStatusCode())->toBe(201);

        $assetId = ScopedApiTestSupport::data($response)['id'];
        expect($assetId)->not->toBeNull();
    });

    it('reads the asset back inline', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $group = AgentApiTestSupport::seedGroup('media-read');
        [, $key] = AgentApiTestSupport::mintGroupKey($group['groupId'], 'media');

        $upload = ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiMediaController::class,
            'store',
            uploadRequest($key, $group['agentIds'][0]),
        );
        $assetId = ScopedApiTestSupport::data($upload)['id'];

        $request = Request::create("/api/v1/group-api/media/{$assetId}", 'GET');
        $request->headers->set('Authorization', 'Bearer ' . $key);

        $response = ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiMediaController::class,
            'show',
            $request,
            [$assetId],
        );

        expect($response->getStatusCode())->toBe(200);
        expect($response->headers->get('Content-Disposition'))->toStartWith('inline;');
    });

    it('404s an asset the key may not read', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $group = AgentApiTestSupport::seedGroup('media-outside');
        $other = AgentApiTestSupport::seedGroup('media-outside-other');
        [, $key] = AgentApiTestSupport::mintGroupKey($group['groupId'], 'media');

        $upload = ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiMediaController::class,
            'store',
            uploadRequest($key, $other['agentIds'][0]),
        );

        // The upload itself was refused — the agent is outside the reach.
        expect($upload->getStatusCode())->toBe(404);
    });

    it('404s an unknown asset id rather than 403', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $group = AgentApiTestSupport::seedGroup('media-unknown');
        [, $key] = AgentApiTestSupport::mintGroupKey($group['groupId'], 'media');

        $request = Request::create('/api/v1/group-api/media/nope', 'GET');
        $request->headers->set('Authorization', 'Bearer ' . $key);

        $response = ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiMediaController::class,
            'show',
            $request,
            ['nope'],
        );

        expect($response->getStatusCode())->toBe(404);
    });

    it('requires an agent to upload for', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $group = AgentApiTestSupport::seedGroup('media-no-agent');
        [, $key] = AgentApiTestSupport::mintGroupKey($group['groupId'], 'media');

        $path = tempnam(sys_get_temp_dir(), 'spora-upload');
        file_put_contents($path, ScopedApiTestSupport::pngBytes());

        $request = Request::create(
            '/api/v1/group-api/media',
            'POST',
            [],
            [],
            ['file' => new UploadedFile($path, 'chart.png', 'image/png', null, true)],
        );
        $request->headers->set('Authorization', 'Bearer ' . $key);

        $response = ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiMediaController::class,
            'store',
            $request,
        );

        expect($response->getStatusCode())->toBe(422);
    });

    it('refuses a type the agent does not accept', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $group = AgentApiTestSupport::seedGroup('media-type');
        [, $key] = AgentApiTestSupport::mintGroupKey($group['groupId'], 'media');

        $response = ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiMediaController::class,
            'store',
            uploadRequest($key, $group['agentIds'][0], 'payload.sh', "#!/bin/sh\nrm -rf /\n"),
        );

        expect($response->getStatusCode())->toBe(415);
    });

    it('refuses an upload with no file', function (): void {
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $group = AgentApiTestSupport::seedGroup('media-no-file');
        [, $key] = AgentApiTestSupport::mintGroupKey($group['groupId'], 'media');

        $request = Request::create('/api/v1/group-api/media', 'POST', ['agent_id' => $group['agentIds'][0]]);
        $request->headers->set('Authorization', 'Bearer ' . $key);

        $response = ScopedApiTestSupport::callGroup(
            $container,
            ScopedApiMediaController::class,
            'store',
            $request,
        );

        expect($response->getStatusCode())->toBe(400);
    });
});
