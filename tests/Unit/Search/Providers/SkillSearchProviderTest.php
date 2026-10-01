<?php

declare(strict_types=1);

use Spora\Apps\AppInterface;
use Spora\Apps\AppRegistry;
use Spora\Search\Providers\SkillSearchProvider;
use Spora\Search\SearchContext;
use Spora\Skills\SkillProviderRegistry;
use Tests\Fixtures\Skills\StubSkillProvider;

/**
 * The skill section of the palette. The cross-tenant assertion is the one this
 * file exists for: search asks every visible principal rather than the one a UI
 * happens to have selected, so a scope bug here leaks instead of mislisting.
 */

/** @param list<array{0: string}|array{0: string, 1: string}> $skills name + optional description. */
function scopedSkills(string $label, int $owner, array $skills): StubSkillProvider
{
    $provider = new StubSkillProvider($label);
    foreach ($skills as $skill) {
        $provider->add($skill[0], ['SKILL.md'], 'body', $skill[1] ?? "About {$skill[0]}.", $owner);
    }
    $provider->onlyVisibleTo = $owner;

    return $provider;
}

/** @param list<string> $names Apps to register, so href resolution has something to find. */
function buildAppRegistry(array $names): AppRegistry
{
    $apps = new AppRegistry();
    foreach ($names as $name) {
        $apps->register(match ($name) {
            'custom-skills' => CustomSkillsAppStub::class,
            default => throw new InvalidArgumentException("No app stub named {$name}"),
        });
    }

    return $apps;
}

/**
 * A named stub because `AppRegistry::register()` instantiates by class name on
 * every `get()` — a name living on an instance cannot be found.
 */
final class CustomSkillsAppStub implements AppInterface
{
    public function name(): string
    {
        return 'custom-skills';
    }

    public function displayName(): string
    {
        return 'Custom Skills';
    }

    public function description(): string
    {
        return 'stub';
    }

    public function icon(): string
    {
        return 'puzzle';
    }

    public function accent(): string
    {
        return 'primary';
    }
}

const SEARCH_OWNER = 4242;
const SEARCH_STRANGER = 9999;

it('finds a skill belonging to a principal the caller can see', function () {
    $provider = new SkillSearchProvider(
        new SkillProviderRegistry([
            scopedSkills('custom-skills', SEARCH_OWNER, [['invoice-drafting', 'How to draft an invoice.']]),
        ]),
        buildAppRegistry([]),
    );

    $hits = $provider->search('invoice', new SearchContext([SEARCH_OWNER]));

    expect($hits)->toHaveCount(1)
        ->and($hits[0]->id)->toBe('invoice-drafting')
        ->and($hits[0]->label)->toBe('invoice-drafting')
        ->and($hits[0]->subLabel)->toBe('How to draft an invoice.');
});

it('never returns a skill belonging to a principal the caller cannot see', function () {
    $custom = new StubSkillProvider('custom-skills');
    $custom->add('my-notes', ['SKILL.md'], 'body', 'Mine.', SEARCH_OWNER);
    $custom->add('team-playbook', ['SKILL.md'], 'body', 'Theirs.', SEARCH_STRANGER);
    $custom->onlyVisibleTo = SEARCH_OWNER;

    $provider = new SkillSearchProvider(
        new SkillProviderRegistry([$custom]),
        buildAppRegistry([]),
    );

    expect($provider->search('team', new SearchContext([SEARCH_OWNER])))->toBe([])
        ->and($provider->search('playbook', new SearchContext([SEARCH_OWNER])))->toBe([])
        // Sanity: the provider is not simply returning nothing at all.
        ->and($provider->search('mine', new SearchContext([SEARCH_OWNER])))->toHaveCount(1);
});

it('returns nothing for an empty principal set', function () {
    $provider = new SkillSearchProvider(
        new SkillProviderRegistry([
            scopedSkills('custom-skills', SEARCH_OWNER, [['invoice-drafting', 'How to draft.']]),
        ]),
        buildAppRegistry([]),
    );

    expect($provider->search('invoice', new SearchContext()))->toBe([])
        ->and($provider->search('invoice', new SearchContext([0])))->toBe([]);
});

it('spans every visible principal, not just one', function () {
    $provider = new SkillSearchProvider(
        new SkillProviderRegistry([
            scopedSkills('custom-skills', SEARCH_OWNER, [['mine', 'Personal.']]),
            scopedSkills('studio', SEARCH_STRANGER, [['theirs', 'Group.']]),
        ]),
        buildAppRegistry([]),
    );

    $ids = array_map(
        static fn($h) => $h->id,
        $provider->search('e', new SearchContext([SEARCH_OWNER, SEARCH_STRANGER])),
    );

    expect($ids)->toContain('mine')->toContain('theirs');
});

it('ranks a name match above a description match', function () {
    $provider = new SkillSearchProvider(
        new SkillProviderRegistry([
            scopedSkills('custom-skills', SEARCH_OWNER, [
                ['invoice-drafting', 'Nothing relevant.'],
                ['quarterly-close', 'Mentions invoice once.'],
            ]),
        ]),
        buildAppRegistry([]),
    );

    $ids = array_map(
        static fn($h) => $h->id,
        $provider->search('invoice', new SearchContext([SEARCH_OWNER])),
    );

    expect($ids)->toBe(['invoice-drafting', 'quarterly-close']);
});

it('ranks an exact name above a prefix above a substring', function () {
    $provider = new SkillSearchProvider(
        new SkillProviderRegistry([
            scopedSkills('custom-skills', SEARCH_OWNER, [['draft'], ['drafting'], ['redraft']]),
        ]),
        buildAppRegistry([]),
    );

    $ids = array_map(
        static fn($h) => $h->id,
        $provider->search('draft', new SearchContext([SEARCH_OWNER])),
    );

    expect($ids)->toBe(['draft', 'drafting', 'redraft']);
});

it('matches case-insensitively and ignores surrounding whitespace', function () {
    $provider = new SkillSearchProvider(
        new SkillProviderRegistry([
            scopedSkills('custom-skills', SEARCH_OWNER, [['Invoice-Drafting']]),
        ]),
        buildAppRegistry([]),
    );

    expect($provider->search('  INVOICE  ', new SearchContext([SEARCH_OWNER])))->toHaveCount(1);
});

it('returns nothing for an empty query rather than every skill', function () {
    $provider = new SkillSearchProvider(
        new SkillProviderRegistry([
            scopedSkills('custom-skills', SEARCH_OWNER, [['a'], ['b']]),
        ]),
        buildAppRegistry([]),
    );

    expect($provider->search('', new SearchContext([SEARCH_OWNER])))->toBe([])
        ->and($provider->search('   ', new SearchContext([SEARCH_OWNER])))->toBe([]);
});

it('routes a plugin skill to its own panel', function () {
    $provider = new SkillSearchProvider(
        new SkillProviderRegistry([
            scopedSkills('custom-skills', SEARCH_OWNER, [['invoice-drafting']]),
        ]),
        buildAppRegistry(['custom-skills']),
    );

    expect($provider->search('invoice', new SearchContext([SEARCH_OWNER]))[0]->href)
        ->toBe('/apps/custom-skills?skill=invoice-drafting');
});

it('returns a shipped skill with no href, because the host has no page for one', function () {
    $provider = new SkillSearchProvider(
        new SkillProviderRegistry([
            scopedSkills('core', SEARCH_OWNER, [['typst', 'Typeset documents.']]),
        ]),
        buildAppRegistry(['custom-skills']),
    );

    expect($provider->search('typst', new SearchContext([SEARCH_OWNER]))[0]->href)->toBeNull();
});

it('surfaces a warning as a badge', function () {
    $provider = new SkillSearchProvider(
        new SkillProviderRegistry([
            scopedSkills('custom-skills', SEARCH_OWNER, [['noisy']])->markWarnings('noisy'),
        ]),
        buildAppRegistry([]),
    );

    expect($provider->search('noisy', new SearchContext([SEARCH_OWNER]))[0]->badge)
        ->toBe('1 warning');
});
