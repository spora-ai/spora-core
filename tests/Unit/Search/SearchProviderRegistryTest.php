<?php

declare(strict_types=1);

use Psr\Log\LoggerInterface;
use Spora\Search\SearchContext;
use Spora\Search\SearchHit;
use Spora\Search\SearchProviderInterface;
use Spora\Search\SearchProviderRegistry;

function stubSearchProvider(string $type, array $hits, ?Throwable $throw = null): SearchProviderInterface
{
    return new class ($type, $hits, $throw) implements SearchProviderInterface {
        /** @param list<SearchHit> $hits */
        public function __construct(
            private readonly string $type,
            private readonly array $hits,
            private readonly ?Throwable $throw,
        ) {}

        public function type(): string
        {
            return $this->type;
        }

        public function search(string $query, SearchContext $context): array
        {
            if ($this->throw !== null) {
                throw $this->throw;
            }

            return $this->hits;
        }
    };
}

function hit(string $type, string $id, string $label = 'x'): SearchHit
{
    return new SearchHit(type: $type, id: $id, label: $label);
}

it('keeps the first provider on a cross-provider collision', function () {
    $registry = new SearchProviderRegistry([
        stubSearchProvider('skill', [hit('skill', 'invoice', 'core wins')]),
        stubSearchProvider('skill', [hit('skill', 'invoice', 'plugin loses')]),
    ]);

    $hits = $registry->search('inv', new SearchContext([1]));

    expect($hits)->toHaveCount(1)
        ->and($hits[0]->label)->toBe('core wins');
});

it('preserves duplicates inside a single provider as that provider reporting a defect', function () {
    $registry = new SearchProviderRegistry([
        stubSearchProvider('skill', [hit('skill', 'dup', 'first'), hit('skill', 'dup', 'second')]),
    ]);

    $hits = $registry->search('d', new SearchContext([1]));

    expect($hits)->toHaveCount(2);
});

it('contains a throwing provider so the rest of the palette survives', function () {
    $registry = new SearchProviderRegistry([
        stubSearchProvider('broken', [], new RuntimeException('provider exploded')),
        stubSearchProvider('agent', [hit('agent', '7', 'Invoicer')]),
    ]);

    $hits = $registry->search('inv', new SearchContext([1]));

    expect($hits)->toHaveCount(1)
        ->and($hits[0]->type)->toBe('agent');
});

it('logs a throwing provider, so a silent failure is distinguishable from no results', function () {
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('warning')
        ->once()
        ->withArgs(static function (string $message, array $context): bool {
            return str_contains($message, 'provider failed')
                && ($context['provider'] ?? null) !== null;
        });

    $registry = new SearchProviderRegistry(
        [stubSearchProvider('broken', [], new RuntimeException('provider exploded'))],
        $logger,
    );

    expect($registry->search('inv', new SearchContext([1])))->toBe([]);
});

it('lists provider types in precedence order', function () {
    $registry = new SearchProviderRegistry([
        stubSearchProvider('skill', []),
        stubSearchProvider('media-archive', []),
    ]);

    expect($registry->types())->toBe(['skill', 'media-archive']);
});

it('de-duplicates on type plus id, not id alone', function () {
    // An agent #7 and a group #7 are both legitimate and distinct.
    $registry = new SearchProviderRegistry([
        stubSearchProvider('agent', [hit('agent', '7')]),
        stubSearchProvider('group', [hit('group', '7')]),
    ]);

    expect($registry->search('7', new SearchContext([1])))->toHaveCount(2);
});

it('serialises a hit to the wire shape the palette consumes', function () {
    $h = new SearchHit(
        type: 'skill',
        id: 'invoice',
        label: 'invoice',
        subLabel: 'How to draft one.',
        badge: '1 warning',
        href: '/apps/custom-skills/skill/invoice',
    );

    expect($h->toArray())->toBe([
        'type'     => 'skill',
        'id'       => 'invoice',
        'label'    => 'invoice',
        'subLabel' => 'How to draft one.',
        'badge'    => '1 warning',
        'href'     => '/apps/custom-skills/skill/invoice',
    ]);
});

it('reports a hit with no destination as a null href rather than inventing one', function () {
    expect((new SearchHit(type: 'skill', id: 'x', label: 'x'))->toArray()['href'])->toBeNull();
});
