<?php

declare(strict_types=1);

namespace Spora\Http;

use Closure;
use Spora\Auth\AuthService;
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

            $name = $body['name'] ?? null;
            if ($renames && !is_string($name)) {
                return $this->error('BAD_REQUEST', 'name must be a string.', Response::HTTP_BAD_REQUEST);
            }
            $parentId = $this->readParentId($body);
            if ($parentId === self::INVALID_PARENT) {
                return $this->error('BAD_REQUEST', 'parent_id must be a string or null.', Response::HTTP_BAD_REQUEST);
            }

            $principalId = $this->resolvePrincipalId(
                $userId,
                $this->readPrincipalId($body['principal_id'] ?? null),
                $id,
            );

            $folder = $renames
                ? $this->folders->rename($principalId, $id, (string) $name)
                : $this->folders->move($principalId, $id, $parentId);

            if ($renames && $moves) {
                $folder = $this->folders->move($principalId, $id, $parentId);
            }

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
        } catch (MediaFolderNotFoundException) {
            return $this->notFound('NOT_FOUND', self::MSG_NOT_FOUND);
        } catch (MediaFolderUniquenessException $e) {
            return $this->unprocessable('NOT_UNIQUE', $e->getMessage());
        } catch (MediaFolderValidationException $e) {
            return $this->error('BAD_REQUEST', $e->getMessage(), Response::HTTP_BAD_REQUEST);
        }
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
     */
    private function readParentId(array $body): ?string
    {
        if (!array_key_exists('parent_id', $body)) {
            return null;
        }
        $value = $body['parent_id'];
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            return self::INVALID_PARENT;
        }

        return $value;
    }
}
