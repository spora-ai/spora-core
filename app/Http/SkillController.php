<?php

declare(strict_types=1);

namespace Spora\Http;

use Spora\Auth\AuthService;
use Spora\Skills\Skill;
use Spora\Skills\SkillScanner;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Skill browsing endpoints powering the Skill tool's `allowed_skills`
 * multi-select (via GET /api/v1/skills → data_source) and the admin
 * UI's skill-detail view (via GET /api/v1/skills/{slug}).
 */
final class SkillController
{
    use JsonControllerHelpers;
    private const MSG_AUTH_REQUIRED = 'Authentication required.';

    public function __construct(
        private readonly AuthService $auth,
        private readonly SkillScanner $scanner,
    ) {}

    public function index(): JsonResponse
    {
        $userId = $this->auth->currentUserId();
        if ($userId === null) {
            return $this->unauthenticated();
        }

        $summaries = array_map(
            static fn(Skill $s) => self::summarize($s),
            $this->scanner->scan(),
        );

        return new JsonResponse(['data' => ['skills' => $summaries]]);
    }

    public function show(Request $request): JsonResponse
    {
        $userId = $this->auth->currentUserId();
        if ($userId === null) {
            return $this->unauthenticated();
        }

        // Skill names are case-insensitive (SkillTool::resolveAndAuthorize
        // lowercases+trims the LLM's choice); mirror the same normalisation
        // here so /api/v1/skills/Git and /api/v1/skills/git are equivalent.
        $slug = strtolower(trim((string) $request->attributes->get('slug', '')));

        foreach ($this->scanner->scan() as $skill) {
            if ($skill->name() === $slug) {
                return new JsonResponse([
                    'data' => [
                        'skill'  => self::detail($skill),
                        'source' => $skill->source(),
                    ],
                ]);
            }
        }

        return $this->notFound('SKILL_NOT_FOUND', "Skill '{$slug}' not found.");
    }

    private function unauthenticated(): JsonResponse
    {
        return $this->unprocessable('UNAUTHENTICATED', self::MSG_AUTH_REQUIRED);
    }

    /**
     * @return array<string, mixed>
     */
    private static function summarize(Skill $s): array
    {
        return [
            'name'        => $s->name(),
            'description' => $s->description(),
            'source'      => $s->source(),
            'license'     => $s->license(),
            'files_count' => count($s->files()),
            'has_warnings' => $s->hasWarnings(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function detail(Skill $s): array
    {
        return [
            'name'          => $s->name(),
            'description'   => $s->description(),
            'license'       => $s->license(),
            'compatibility' => $s->compatibility(),
            'metadata'      => $s->metadata(),
            'allowed_tools' => $s->allowedTools(),
            'body'          => $s->body(),
            'body_bytes'    => $s->bodyBytes(),
            'files'         => $s->files(),
            'warnings'      => $s->warnings(),
        ];
    }
}
