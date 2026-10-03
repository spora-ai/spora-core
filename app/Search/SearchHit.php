<?php

declare(strict_types=1);

namespace Spora\Search;

/**
 * One search result, as rendered in the host palette.
 *
 * `$href` is nullable by design, not an oversight: the host has no page for
 * every searchable thing — none for skills at all — so a provider that cannot
 * name a destination says so rather than inventing a 404.
 */
final readonly class SearchHit
{
    /**
     * @param string      $id      Stable within `$type`; what the palette de-duplicates on.
     * @param string|null $subLabel Secondary text — usually a description.
     * @param string|null $href    Host route to open on select, or null when none exists.
     */
    public function __construct(
        public string $type,
        public string $id,
        public string $label,
        public ?string $subLabel = null,
        public ?string $badge = null,
        public ?string $href = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type'      => $this->type,
            'id'        => $this->id,
            'label'     => $this->label,
            'subLabel'  => $this->subLabel,
            'badge'     => $this->badge,
            'href'      => $this->href,
        ];
    }
}
