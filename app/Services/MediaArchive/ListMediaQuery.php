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

    public function __construct(
        public ?MediaType $mediaType = null,
        public ?int $agentId = null,
        public ?string $pluginSlug = null,
        public ?string $toolName = null,
        public ?DateTimeInterface $from = null,
        public ?DateTimeInterface $to = null,
        public ?string $search = null,
        public string $sort = self::SORT_CREATED_DESC,
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
            'agentId'    => $this->agentId,
            'pluginSlug' => $this->pluginSlug,
            'toolName'   => $this->toolName,
            'search'     => $this->search,
            'sort'       => $this->sort,
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
