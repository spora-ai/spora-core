<?php

declare(strict_types=1);

namespace Spora\Http;

use Spora\Auth\AuthService;
use Spora\Skills\Skill;
use Spora\Skills\SkillScanner;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Skill endpoints:
 *
 * - GET   /api/v1/skills             list discovered skills (powers the
 *                                    Skill tool's `allowed_skills` multi-
 *                                    select `dataSource`).
 * - GET   /api/v1/skills/{slug}      one skill, full `files` listing +
 *                                    raw SKILL.md body for the admin
 *                                    UI's skill-detail view.
 *
 * Both behind AuthMiddleware + CsrfMiddleware (same as the other
 * read-only browsing endpoints).
 */
final class SkillController
{
    use JsonControllerHelpers;
    private const MSG_AUTH_REQUIRED = 'Authentication required.';

    public function __construct(
        private readonly AuthService $auth,
        private readonly SkillScanner $scanner,
    ) {}

    /**
     * GET /api/v1/skills
     */
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

    /**
     * GET /api/v1/skills/{slug}
     */
    public function show(Request $request): JsonResponse
    {
        $userId = $this->auth->currentUserId();
        if ($userId === null) {
            return $this->unauthenticated();
        }

        $slug = (string) $request->attributes->get('slug', '');

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
