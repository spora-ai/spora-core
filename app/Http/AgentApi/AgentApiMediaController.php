<?php

declare(strict_types=1);

namespace Spora\Http\AgentApi;

use LogicException;
use OpenApi\Attributes as OA;
use Spora\Http\JsonControllerHelpers;
use Spora\Services\AgentApi\AgentApiContext;
use Spora\Services\AgentApi\AgentApiFileIngest;
use Spora\Services\AgentApi\AgentApiScope;
use Spora\Services\AssetStreamer;
use Spora\Services\MediaArchive\MediaAssetSerializer;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * File input and output for the Agent API.
 *
 * An agent that can see a picture should be able to send one, and a caller
 * that reads the answer should be able to fetch the images in it. Both are
 * scoped to the key's own agent, and both answer 404 rather than 403 for an
 * asset outside it — a key must not be able to probe which media ids exist.
 */
#[OA\Tag(name: 'Agent-api', description: 'Per-agent API for external tools')]
final class AgentApiMediaController
{
    use JsonControllerHelpers;

    public function __construct(
        private readonly AgentApiScope $scope,
        private readonly AgentApiFileIngest $ingest,
        private readonly AssetStreamer $streamer,
        private readonly MediaAssetSerializer $serializer,
    ) {}

    #[OA\Post(
        path: '/api/v1/agent-api/media',
        summary: 'Upload a file for this agent',
        tags: ['Agent-api'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'file', type: 'string', format: 'binary'),
                    ],
                ),
            ),
        ),
    )]
    #[OA\Response(response: 201, description: 'The asset was ingested.')]
    #[OA\Response(response: 400, description: 'No file was sent under "file".')]
    #[OA\Response(response: 415, description: 'The file type is not in the agent\'s allowlist.')]
    public function store(Request $request): JsonResponse
    {

        $file = $request->files->get('file');

        if (!$file instanceof UploadedFile) {
            return $this->error('BAD_REQUEST', 'No file uploaded under the "file" field.', Response::HTTP_BAD_REQUEST);
        }

        if (!$file->isValid()) {
            return $this->error('BAD_REQUEST', 'Upload failed: ' . $file->getErrorMessage(), Response::HTTP_BAD_REQUEST);
        }

        $bytes = file_get_contents($file->getPathname());

        if ($bytes === false) {
            return $this->error('READ_FAILED', 'Could not read uploaded file.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $context = AgentApiContext::fromRequest($request);
        $mediaId = $this->ingest->ingestBytes($context, $bytes, $this->safeName($file));

        if ($mediaId === null) {
            return $this->error(
                'UNSUPPORTED_MEDIA_TYPE',
                'This file type is not accepted by the agent.',
                Response::HTTP_UNSUPPORTED_MEDIA_TYPE,
            );
        }

        $asset = $this->scope->findMedia($mediaId, $context->agentId());

        if ($asset === null) {
            // The ingest stamped the agent id, so an id that cannot be read
            // back means the write and the scope predicate disagree — a bug
            // worth failing loudly on rather than answering with a half-result.
            throw new LogicException('Ingested media is immediately out of scope.');
        }

        return new JsonResponse(
            ['data' => $this->serializer->serialize($asset, null)],
            Response::HTTP_CREATED,
        );
    }

    #[OA\Get(
        path: '/api/v1/agent-api/media/{mediaId}',
        summary: 'Fetch a media asset this agent produced',
        tags: ['Agent-api'],
        parameters: [new OA\Parameter(name: 'mediaId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
    )]
    #[OA\Response(response: 200, description: 'The asset bytes.')]
    #[OA\Response(response: 404, description: 'No such asset, or not this agent\'s to read.')]
    public function show(Request $request, string $mediaId): Response
    {

        $asset = $this->scope->findMedia($mediaId, AgentApiContext::fromRequest($request)->agentId());

        if ($asset === null) {
            return $this->notFound('NOT_FOUND', 'Media not found.');
        }

        // `inline` rather than the admin route's `attachment`: an agent's
        // output is usually consumed by an LLM or shown to a person, not
        // saved to disk by a browser.
        return $this->streamer->stream($asset, 'inline') ?? $this->notFound('NOT_FOUND', 'Media not found.');
    }

    /**
     * The client's filename is attacker-influenced and ends up in a
     * `Content-Disposition` header on the read path, so it is reduced to its
     * basename before it reaches storage.
     */
    private function safeName(UploadedFile $file): string
    {
        $name = basename($file->getClientOriginalName());

        return $name === '' ? 'upload' : $name;
    }

}
