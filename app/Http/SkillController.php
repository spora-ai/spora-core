<?php

declare(strict_types=1);

namespace Spora\Http;

use Spora\Auth\AuthService;
use Spora\Services\PrincipalService;
use Spora\Skills\SkillDescriptor;
use Spora\Skills\SkillProviderRegistry;
use Spora\Skills\SkillSummary;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Skill browsing endpoints powering the Skill tool's `allowed_skills`
 * multi-select (via GET /api/v1/skills → data_source) and the admin
 * UI's skill-detail view (via GET /api/v1/skills/{slug}).
 *
 * `?principal_id=N` narrows the listing to one principal. The SPA already sends
 * it and derives it per editor mode (agent / group / personal), so honouring it
 * is what stops a group admin's personal skills appearing in the group's picker.
 * Without it the listing is the union over every principal the caller can see,
 * which is the previous behaviour. Shipped skills appear either way.
 *
 * A name the caller cannot see is a **404**, not a 403: a 403 confirms the skill
 * exists, which is a cross-tenant existence oracle.
 */
final class SkillController
{
    use JsonControllerHelpers;

    public function __construct(
        private readonly AuthService $auth,
        private readonly SkillProviderRegistry $skills,
        private readonly PrincipalService $principals,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $userId = $this->auth->currentUserId();
        if ($userId === null) {
            return $this->unauthenticated();
        }

        $requested = $this->requestedPrincipalId($request);
        $ids = $requested === null
            ? $this->principals->visiblePrincipalIdsFor($userId)
            : [$requested];

        $seen = [];
        $summaries = [];
        foreach ($ids as $principalId) {
            foreach ($this->skills->getSkills($principalId) as $summary) {
                $key = $summary->source . '::' . $summary->name;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $summaries[] = self::summarize($summary);
            }
        }

        return new JsonResponse(['data' => ['skills' => $summaries]]);
    }

    public function show(Request $request): JsonResponse
    {
        $userId = $this->auth->currentUserId();
        if ($userId === null) {
            return $this->unauthenticated();
        }

        // Skill names are case-insensitive (SkillTool lowercases+trims the
        // LLM's choice); mirror the same normalisation here so
        // /api/v1/skills/Git and /api/v1/skills/git are equivalent.
        $name = strtolower(trim((string) $request->attributes->get('slug', '')));

        $requested = $this->requestedPrincipalId($request);
        $ids = $requested === null
            ? $this->principals->visiblePrincipalIdsFor($userId)
            : [$requested];

        foreach ($ids as $principalId) {
            $descriptor = $this->skills->getSkillDetails($name, $principalId);
            if ($descriptor !== null) {
                return new JsonResponse([
                    'data' => [
                        'skill'  => self::detail($descriptor),
                        'source' => $descriptor->summary->source,
                    ],
                ]);
            }
        }

        return $this->notFound('SKILL_NOT_FOUND', "Skill '{$name}' not found.");
    }

    /**
     * The `?principal_id=` the caller asked for, or null when absent.
     *
     * A malformed or non-positive value is treated as absent rather than
     * rejected: the parameter is a narrowing hint, so falling back to the
     * caller's union is the answer they would have got by omitting it. A 400
     * would break the SPA's picker for a value it should never send.
     */
    private function requestedPrincipalId(Request $request): ?int
    {
        $raw = $request->query->get('principal_id');
        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return null;
        }

        $id = (int) $raw;

        return $id > 0 ? $id : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function summarize(SkillSummary $s): array
    {
        return [
            'name'        => $s->name,
            'description' => $s->description,
            'source'      => $s->source,
            'license'     => $s->license,
            'files_count' => $s->fileCount,
            'has_warnings' => $s->hasWarnings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function detail(SkillDescriptor $d): array
    {
        return [
            'name'          => $d->summary->name,
            'description'   => $d->summary->description,
            'license'       => $d->summary->license,
            'compatibility' => $d->compatibility,
            'metadata'      => $d->metadata,
            'allowed_tools' => $d->allowedTools,
            'body'          => $d->body,
            'body_bytes'    => $d->bodyBytes(),
            'files'         => $d->files,
            'warnings'      => $d->warnings,
        ];
    }
}
