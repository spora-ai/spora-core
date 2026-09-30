<?php

declare(strict_types=1);

namespace Spora\Skills;

/**
 * A read-side source of skills.
 *
 * Core ships {@see Providers\FilesystemSkillProvider}; a plugin ships its own for
 * skills that do not live on disk — user-authored, principal-scoped ones.
 *
 * Two wire types, on purpose: {@see SkillSummary} for listings,
 * {@see SkillDescriptor} with the body. Never both — the `allowed_skills`
 * multi-select loads every skill at once, and a body on the list type is 50 KB
 * per dropdown row.
 *
 * Identity is the frontmatter `name`, not a directory slug — matching
 * `ToolConfigService`'s name→skill map and `SkillController`'s name-keyed route.
 */
interface SkillProviderInterface
{
    /**
     * Hard read cap, in bytes. Enforced before content is materialised; a
     * provider that only checks after reading has already paid the memory the
     * cap exists to avoid.
     */
    public const MAX_FILE_BYTES = 50_000;

    /**
     * Bucket label for this provider, e.g. `filesystem` or a plugin slug. A
     * label for operators and the UI, never a lookup key.
     */
    public function source(): string;

    /**
     * Skills visible to `$principalId`.
     *
     * A principal-scoped implementation returns `[]` for `null` and for any
     * non-live `$principalId` — fail closed. Callers pass `null` on
     * operator-default and strict-mode paths, and widening there would leak one
     * tenant's skills into another's view.
     *
     * @return list<SkillSummary>
     */
    public function getSkills(?int $principalId): array;

    /**
     * Full detail, or `null` for an unknown **or invisible** skill. Callers
     * cannot tell those apart, which is the point: distinguishing them would be a
     * cross-tenant existence oracle.
     */
    public function getSkillDetails(string $name, ?int $principalId): ?SkillDescriptor;

    /**
     * The file listing, or `null` for an unknown/invisible skill. `[]` (known, no
     * files) and `null` (unknown) are different answers; do not collapse them.
     *
     * @return list<array{path: string, bytes: int}>|null
     */
    public function getSkillFiles(string $name, ?int $principalId): ?array;

    /**
     * Raw content of one file, keyed by opaque exact-match `$name`/`$path` from
     * {@see getSkillFiles()}.
     *
     * Security boundary: implementations MUST NOT normalise, resolve, or pass
     * these to a filesystem API, MUST re-validate containment independently of
     * the caller, and MUST enforce {@see MAX_FILE_BYTES} before materialising. A
     * caller cannot enforce this on an implementation's behalf.
     *
     * Separate from {@see getSkillFiles()} because the costs differ: a listing
     * is safe to fetch in bulk every tick, content is not.
     *
     * @return string|null null when `$path` is not a member of the skill
     */
    public function getSkillFile(string $name, string $path, ?int $principalId): ?string;
}
