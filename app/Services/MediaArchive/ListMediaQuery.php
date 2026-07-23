<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use DateTimeInterface;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Readonly filter DTO consumed by {@see MediaArchiveService::list()}.
 *
 * `page` and `perPage` map directly onto the underlying Eloquent
 * LengthAwarePaginator. The service clamps `perPage` to a hard ceiling so a
 * careless caller can't pin the DB.
 *
 * `search` matches against `prompt` and the original `asset_url` / `source_url`
 * with a case-insensitive LIKE — fine for human-typed queries, not full-text.
 */
final readonly class ListMediaQuery
{
    public const PER_PAGE_DEFAULT = 20;
    public const PER_PAGE_MAX = 100;

    public const SORT_CREATED_DESC = 'created_at_desc';
    public const SORT_CREATED_ASC = 'created_at_asc';
    public const SORT_SIZE_DESC = 'size_desc';

    public const ALLOWED_SORTS = [
        self::SORT_CREATED_DESC,
        self::SORT_CREATED_ASC,
        self::SORT_SIZE_DESC,
    ];

    public const UPLOAD_SOURCE_UPLOAD = 'upload';
    public const UPLOAD_SOURCE_TOOL = 'tool';
    /**
     * Input-only sentinel for `?source=all` — the HTTP builder normalises
     * this to null so the WHERE clause is skipped. NOT a valid value for
     * direct DTO construction: `MediaArchiveService::list()` will execute
     * `WHERE upload_source = 'all'` if anyone passes it through. Use null
     * (or omit the arg) for the unfiltered case.
     */
    public const UPLOAD_SOURCE_ALL = 'all';

    /**
     * Persisted filter values — the subset of {@see self::UPLOAD_SOURCE_*}
     * that {@see MediaArchiveService::list()} is safe to apply as a WHERE
     * clause. Excludes the `'all'` sentinel, which the builder converts
     * to null upstream.
     */
    public const ALLOWED_UPLOAD_SOURCES = [
        self::UPLOAD_SOURCE_UPLOAD,
        self::UPLOAD_SOURCE_TOOL,
    ];

    /**
     * Ownership filter (`?ownership=mine`) — replaces the user-only
     * `?scope=mine` semantic for callers that need tool-generated media
     * for the current user's agents.
     *
     * `'mine'` expands to the union "uploads where user_id = me OR tool
     * rows where agent_id IN (agents owned by me)". `'all'` is an explicit
     * "no filter" sentinel; the builder normalises it to null upstream.
     */
    public const OWNERSHIP_MINE = 'mine';
    public const OWNERSHIP_ALL = 'all';

    /**
     * Persisted ownership values — anything outside this set (typos,
     * older clients) is silently dropped by the builder so the listing
     * endpoint never crashes on a bad query string.
     */
    public const ALLOWED_OWNERSHIP_VALUES = [
        self::OWNERSHIP_MINE,
        self::OWNERSHIP_ALL,
    ];

    /**
     * @param list<MediaType>|null $mediaTypes Multi-value media-type filter
     *        (`?types=image,document`). Null means no filter. When set,
     *        takes precedence over the singular `$mediaType` so the picker
     *        can request `image,document` in one round-trip without audio
     *        or unknown rows leaking in.
     * @param string|null $uploadSource One of {@see self::ALLOWED_UPLOAD_SOURCES}.
     *        Null = no filter (alias for `'all'`). `'upload'` restricts to
     *        rows created by the upload pipeline
     *        (`MediaUploadController`); `'tool'` restricts to rows generated
     *        by tool calls. Anything else should be normalised to null by
     *        the builder.
     */
    public function __construct(
        public ?MediaType $mediaType = null,
        public ?array $mediaTypes = null,
        public ?int $agentId = null,
        public ?int $userId = null,
        public ?string $pluginSlug = null,
        public ?string $toolName = null,
        public ?DateTimeInterface $from = null,
        public ?DateTimeInterface $to = null,
        public ?string $search = null,
        public string $sort = self::SORT_CREATED_DESC,
        public ?string $uploadSource = null,
        // `?ownership=mine` semantic — union of uploads owned by the user
        // and tool-generated rows for agents owned by the user. The
        // builder sets `agentOwnerUserId` (the subquery target) and clears
        // `userId` when ownership is in play; the legacy `userId` branch
        // stays for callers that only need the upload-only scope.
        public ?string $ownership = null,
        public ?int $agentOwnerUserId = null,
        public int $page = 1,
        public int $perPage = self::PER_PAGE_DEFAULT,
    ) {}

    public function perPage(): int
    {
        return max(1, min(self::PER_PAGE_MAX, $this->perPage));
    }

    public function page(): int
    {
        return max(1, $this->page);
    }

    /**
     * The `LengthAwarePaginator` returned by the service uses this hook to
     * reconstruct the query-string for `previousPageUrl` / `nextPageUrl`.
     * Keeping the implementation close to the DTO avoids leaking the
     * paginator's internal `$pageName` into controller code.
     */
    public function toArray(): array
    {
        return [
            'mediaType'  => $this->mediaType?->value,
            'mediaTypes' => $this->mediaTypes !== null
                ? array_map(static fn(MediaType $t): string => $t->value, $this->mediaTypes)
                : null,
            'agentId'    => $this->agentId,
            'userId'     => $this->userId,
            'pluginSlug' => $this->pluginSlug,
            'toolName'   => $this->toolName,
            'search'     => $this->search,
            'sort'         => $this->sort,
            'uploadSource' => $this->uploadSource,
            'ownership'    => $this->ownership,
            'agentOwnerUserId' => $this->agentOwnerUserId,
        ];
    }

    /**
     * Convenience for `LengthAwarePaginator::appends()` — only the filters
     * that callers typically want to preserve across page clicks.
     */
    public function appendsForPagination(): array
    {
        $arr = $this->toArray();
        if ($this->from !== null) {
            $arr['from'] = $this->from->format(DateTimeInterface::ATOM);
        }
        if ($this->to !== null) {
            $arr['to'] = $this->to->format(DateTimeInterface::ATOM);
        }
        return array_filter($arr, static fn($v): bool => $v !== null && $v !== '');
    }
}
