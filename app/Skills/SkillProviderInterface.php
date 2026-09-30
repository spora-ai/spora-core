<?php

declare(strict_types=1);

namespace Spora\Skills;

/**
 * A read-side source of skills.
 *
 * Core ships {@see Providers\FilesystemSkillProvider} for everything on disk
 * (project, framework, and plugin `skillPaths()` roots). A plugin ships its own
 * implementation for skills that do not live on disk — user-authored ones scoped
 * to a principal, for example.
 *
 * **Two wire types, on purpose.** {@see SkillSummary} carries what a list needs
 * and nothing more; {@see SkillDescriptor} adds the body. Neither summary nor
 * descriptor is ever returned together with the other, because the
 * `allowed_skills` multi-select loads every skill at once: putting a body on the
 * list type would pull 50 KB per skill into a dropdown.
 *
 * **Identity is the frontmatter `name`**, not a directory slug. That matches
 * `ToolConfigService`'s name→skill map, `SkillTool::resolveAndAuthorize()`'s
 * case-insensitive name comparison, and `SkillController::show()`'s
 * name-keyed route.
 *
 * **Implementations own their own security.** `getSkillFile()` receives opaque
 * keys straight from a previous `getSkillFiles()` listing and must not assume
 * they are safe: no normalisation, no path resolution, no filesystem API, and
 * an independent containment check plus the {@see MAX_FILE_BYTES} cap before
 * any content is materialised. A caller cannot enforce this on an
 * implementation's behalf.
 */
interface SkillProviderInterface
{
    /**
     * Hard read cap, in bytes. Enforced by every implementation before content
     * is materialised — a provider that only checks after reading has already
     * paid the memory cost it was meant to avoid.
     */
    public const MAX_FILE_BYTES = 50_000;

    /**
     * Bucket label for this provider, e.g. `filesystem` or a plugin slug.
     *
     * A label, not a lookup key: it never overrides a skill's own `source`
     * label, and nothing resolves skills through it. It exists so operators and
     * the UI can say where a skill came from.
     */
    public function source(): string;

    /**
     * Skills visible to `$principalId`.
     *
     * A principal-scoped implementation returns `[]` for `null` and for any
     * `$principalId` that is not a live principal — fail closed. Callers pass
     * `null` on operator-default and strict-mode paths where no principal is in
     * scope, and widening there would leak one tenant's skills into another's
     * view.
     *
     * @return list<SkillSummary>
     */
    public function getSkills(?int $principalId): array;

    /**
     * Full detail for one skill, or `null` when it does not exist **or is not
     * visible to `$principalId`**. Callers cannot tell those apart, which is the
     * point: a distinct "exists but forbidden" answer would be a cross-tenant
     * existence oracle.
     */
    public function getSkillDetails(string $name, ?int $principalId): ?SkillDescriptor;

    /**
     * The skill's file listing, or `null` for an unknown/invisible skill.
     *
     * `[]` (known, no files) and `null` (unknown) are different answers and
     * callers must not collapse them.
     *
     * @return list<array{path: string, bytes: int}>|null
     */
    public function getSkillFiles(string $name, ?int $principalId): ?array;

    /**
     * Raw content of one file.
     *
     * `$name` and `$path` are opaque exact-match keys as returned by
     * {@see getSkillFiles()}. Implementations MUST NOT normalise, resolve, or
     * pass them to a filesystem API, MUST re-validate containment
     * independently of the caller, and MUST enforce
     * {@see MAX_FILE_BYTES} before materialising.
     *
     * Separate from {@see getSkillFiles()} because listing metadata and
     * content have different costs: a listing is safe to fetch in bulk on every
     * tick, content is not. Folding them together would force every consumer to
     * read every file just to show a list.
     *
     * @return string|null null when `$path` is not a member of the skill
     */
    public function getSkillFile(string $name, string $path, ?int $principalId): ?string;
}
