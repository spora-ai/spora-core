<?php

declare(strict_types=1);

namespace Spora\Http;

use Closure;
use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Auth\AuthService;
use Spora\Models\MediaFolder;
use Spora\Services\MediaArchive\Exceptions\MediaFolderNotFoundException;
use Spora\Services\MediaArchive\Exceptions\MediaFolderUniquenessException;
use Spora\Services\MediaArchive\Exceptions\MediaFolderValidationException;
use Spora\Services\MediaArchive\MediaFolderService;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * REST surface for the Media Archive's folders.
 *
 * - `GET    /api/v1/media/folders`        — the principal's folders, flat.
 * - `POST   /api/v1/media/folders`        — create.
 * - `PATCH  /api/v1/media/folders/{id}`   — rename and/or move.
 * - `DELETE /api/v1/media/folders/{id}`   — delete; subfolders go with it
 *   and the assets fall back to the root.
 *
 * Folders are principal-scoped. A request may name the principal it acts
 * on (`principal_id`); without one it acts on the caller's own
 * user-principal, or — for a request that names a folder — on the
 * folder's own principal. A folder outside the caller's visible
 * principals is a **404**, not a 403: the caller has no business knowing
 * that it exists.
 *
 * Auth and CSRF are enforced by the route's middleware; the controller
 * does not duplicate the check.
 */
final class MediaFolderController
{
    use JsonControllerHelpers;

    private const MSG_NOT_FOUND = 'Media folder not found.';

    /**
     * Sentinel for a `parent_id` that is neither a string nor null.
     * `null` is a legitimate value — it moves the folder to the root — so
     * an unusable value needs its own marker.
     */
    private const INVALID_PARENT = "\0invalid";

    public function __construct(
        private readonly MediaFolderService $folders,
        private readonly AuthService $auth,
        private readonly PrincipalResolver $principalResolver,
        private readonly PrincipalService $principalService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $userId = $this->auth->currentUserId();
        if ($userId === null) {
            return $this->unauthenticated();
        }

        return $this->respond(function () use ($request, $userId): JsonResponse {
            $principalId = $this->resolvePrincipalId(
                $userId,
                $this->readPrincipalId($request->query->get('principal_id')),
                null,
            );

            return new JsonResponse([
                'data' => ['folders' => $this->folders->listForPrincipal($principalId)],
            ]);
        });
    }

    public function store(Request $request): JsonResponse
    {
        $userId = $this->auth->currentUserId();
        if ($userId === null) {
            return $this->unauthenticated();
        }

        $body = $this->safeDecodeJson($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        return $this->respond(function () use ($body, $userId): JsonResponse {
            $name = $body['name'] ?? null;
            if (!is_string($name)) {
                return $this->error('BAD_REQUEST', 'name is required.', Response::HTTP_BAD_REQUEST);
            }
            $parentId = $this->readParentId($body);
            if ($parentId === self::INVALID_PARENT) {
                return $this->error('BAD_REQUEST', 'parent_id must be a string or null.', Response::HTTP_BAD_REQUEST);
            }

            $principalId = $this->resolvePrincipalId(
                $userId,
                $this->readPrincipalId($body['principal_id'] ?? null),
                null,
            );
            $folder = $this->folders->create($principalId, $name, $parentId);

            return new JsonResponse(
                ['data' => $this->folders->describe($folder)],
                Response::HTTP_CREATED,
            );
        });
    }

    public function update(string $id, Request $request): JsonResponse
    {
        $userId = $this->auth->currentUserId();
        if ($userId === null) {
            return $this->unauthenticated();
        }

        $body = $this->safeDecodeJson($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        return $this->respond(function () use ($id, $body, $userId): JsonResponse {
            $renames = array_key_exists('name', $body);
            $moves = array_key_exists('parent_id', $body);
            if (!$renames && !$moves) {
                return $this->error(
                    'BAD_REQUEST',
                    'Send "name" to rename the folder, "parent_id" to move it.',
                    Response::HTTP_BAD_REQUEST,
                );
            }

            $invalid = $this->invalidField($body);
            if ($invalid !== null) {
                return $invalid;
            }

            $principalId = $this->resolvePrincipalId(
                $userId,
                $this->readPrincipalId($body['principal_id'] ?? null),
                $id,
            );

            $folder = $this->applyPatch($principalId, $id, $body);

            return new JsonResponse(['data' => $this->folders->describe($folder)]);
        });
    }

    public function destroy(string $id): JsonResponse
    {
        $userId = $this->auth->currentUserId();
        if ($userId === null) {
            return $this->unauthenticated();
        }

        return $this->respond(function () use ($id, $userId): JsonResponse {
            $principalId = $this->resolvePrincipalId($userId, null, $id);
            $this->folders->delete($principalId, $id);

            return new JsonResponse(['data' => ['deleted' => true, 'id' => $id]]);
        });
    }

    /**
     * Run the folder operation and map its domain errors onto HTTP.
     *
     * The uniqueness rule is reported as a 422 and a malformed request as
     * a 400 — neither reaches the caller as a database exception.
     *
     * @param Closure(): JsonResponse $operation
     */
    private function respond(Closure $operation): JsonResponse
    {
        try {
            return $operation();
        } catch (MediaFolderNotFoundException | MediaFolderUniquenessException | MediaFolderValidationException $e) {
            return $this->folderError($e);
        }
    }

    /**
     * The HTTP shape of a folder domain error.
     *
     * Split out of {@see respond()} so that method stays under the
     * 3-return brain-overload ceiling.
     */
    private function folderError(
        MediaFolderNotFoundException | MediaFolderUniquenessException | MediaFolderValidationException $e,
    ): JsonResponse {
        if ($e instanceof MediaFolderNotFoundException) {
            return $this->notFound('NOT_FOUND', self::MSG_NOT_FOUND);
        }
        if ($e instanceof MediaFolderUniquenessException) {
            return $this->unprocessable('NOT_UNIQUE', $e->getMessage());
        }

        return $this->error('BAD_REQUEST', $e->getMessage(), Response::HTTP_BAD_REQUEST);
    }

    /**
     * The error for a field the patch carries in an unusable shape, or
     * null when both are usable.
     */
    private function invalidField(array $body): ?JsonResponse
    {
        if (array_key_exists('name', $body) && !is_string($body['name'] ?? null)) {
            return $this->error('BAD_REQUEST', 'name must be a string.', Response::HTTP_BAD_REQUEST);
        }
        if (array_key_exists('parent_id', $body) && $this->readParentId($body) === self::INVALID_PARENT) {
            return $this->error('BAD_REQUEST', 'parent_id must be a string or null.', Response::HTTP_BAD_REQUEST);
        }

        return null;
    }

    /**
     * The rename and the move, as one operation.
     *
     * The two writes share a transaction, so a move that collides with a
     * sibling rolls the rename back: the caller never sees a 422 next to
     * a folder that was renamed anyway.
     */
    private function renameAndMove(int $principalId, string $id, string $name, ?string $parentId): MediaFolder
    {
        return Capsule::connection()->transaction(
            function () use ($principalId, $id, $name, $parentId): MediaFolder {
                $this->folders->rename($principalId, $id, $name);

                return $this->folders->move($principalId, $id, $parentId);
            },
        );
    }

    /**
     * What the patch asks for: a rename, a move, or both.
     */
    private function applyPatch(int $principalId, string $id, array $body): MediaFolder
    {
        $renames = array_key_exists('name', $body);
        $moves = array_key_exists('parent_id', $body);
        $name = (string) ($body['name'] ?? '');
        $parentId = $this->readParentId($body);

        if ($renames && $moves) {
            return $this->renameAndMove($principalId, $id, $name, $parentId);
        }
        if ($renames) {
            return $this->folders->rename($principalId, $id, $name);
        }

        return $this->folders->move($principalId, $id, $parentId);
    }

    /**
     * The principal the request acts on.
     *
     * A named principal must be one the caller can act for. A request
     * that names a folder and no principal acts on the folder's own
     * principal.
     *
     * @throws MediaFolderNotFoundException
     */
    private function resolvePrincipalId(int $userId, ?int $named, ?string $folderId): int
    {
        $visible = $this->visiblePrincipalIds($userId);

        if ($named !== null) {
            if (!in_array($named, $visible, true)) {
                throw new MediaFolderNotFoundException(self::MSG_NOT_FOUND);
            }
            return $named;
        }

        if ($folderId !== null) {
            return $this->folderPrincipalId($visible, $folderId);
        }

        return $visible[0];
    }

    /**
     * The principal a folder belongs to, when the caller may act for it.
     *
     * @param list<int> $visible
     * @throws MediaFolderNotFoundException
     */
    private function folderPrincipalId(array $visible, string $folderId): int
    {
        foreach ($visible as $principalId) {
            if ($this->folders->find($principalId, $folderId) !== null) {
                return $principalId;
            }
        }

        throw new MediaFolderNotFoundException(self::MSG_NOT_FOUND);
    }

    /**
     * The principals the caller can act for.
     *
     * @return list<int>
     */
    private function visiblePrincipalIds(int $userId): array
    {
        $visible = $this->principalResolver->visiblePrincipalIds($userId);
        if ($visible === []) {
            // The user-principal is materialised on demand; without it the
            // caller can act for no principal at all.
            $visible = [$this->principalService->ensureUserPrincipal($userId)->id];
        }

        return $visible;
    }

    /**
     * A principal id from the query string or the request body, or null
     * when the request names none.
     */
    private function readPrincipalId(mixed $raw): ?int
    {
        if (is_int($raw)) {
            return $raw;
        }
        if (is_string($raw) && ctype_digit($raw)) {
            return (int) $raw;
        }

        return null;
    }

    /**
     * The parent folder id from the request body.
     *
     * `null` is a valid value — it moves the folder to the root — so an
     * unusable value needs its own sentinel to be told apart from it.
     * An absent key and an explicit `null` both mean the root.
     */
    private function readParentId(array $body): ?string
    {
        $value = $body['parent_id'] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            return self::INVALID_PARENT;
        }

        return $value;
    }
}
