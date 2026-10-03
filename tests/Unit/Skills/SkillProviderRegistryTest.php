<?php

declare(strict_types=1);

use Spora\Skills\SkillProviderRegistry;
use Tests\Fixtures\Skills\StubSkillProvider;

/**
 * The registry is the one place the provider precedence rule lives, and that
 * rule is a correctness property rather than a preference: it is what stops a
 * plugin from changing what an existing agent's `allowed_skills` resolves to.
 */
describe('SkillProviderRegistry::getSkills', function (): void {
    it('merges providers in order', function (): void {
        $first = (new StubSkillProvider('a'))->add('alpha');
        $second = (new StubSkillProvider('b'))->add('beta');

        $names = array_map(
            static fn($s) => $s->name,
            (new SkillProviderRegistry([$first, $second]))->getSkills(null),
        );

        expect($names)->toBe(['alpha', 'beta']);
    });

    it('lets the first provider win a cross-provider name collision', function (): void {
        $filesystem = (new StubSkillProvider('filesystem'))->add('typst', [], 'core body');
        $plugin = (new StubSkillProvider('plugin'))->add('typst', [], 'plugin body');

        $summaries = (new SkillProviderRegistry([$filesystem, $plugin]))->getSkills(null);

        expect($summaries)->toHaveCount(1)
            ->and($summaries[0]->source)->toBe('filesystem');
    });

    it('does not let a later provider shadow core even when listed first by accident', function (): void {
        // The container puts core first; this asserts the *rule* the container
        // relies on, not the container's own behaviour.
        $plugin = (new StubSkillProvider('plugin'))->add('shipped', [], 'plugin copy');
        $core = (new StubSkillProvider('filesystem'))->add('shipped', [], 'core copy');

        $registry = new SkillProviderRegistry([$core, $plugin]);
        $summaries = $registry->getSkills(null);

        expect($summaries)->toHaveCount(1)
            ->and($registry->getSkillDetails('shipped', null)?->body)->toBe('core copy');
    });

    it('keeps a provider\'s own duplicate names', function (): void {
        // A provider surfacing two same-named skills is reporting a real
        // defect; suppressing the second would discard the evidence. The
        // scanner already turns the on-disk case into a SKILL_NAME_CONFLICT
        // warning, and the UI shows it.
        $provider = (new StubSkillProvider('a'))->add('twin')->add('twin', [], 'second body');

        $summaries = (new SkillProviderRegistry([$provider]))->getSkills(null);

        expect($summaries)->toHaveCount(2)
            ->and($summaries[0]->name)->toBe('twin')
            ->and($summaries[1]->name)->toBe('twin');
    });

    it('drops a later provider duplicate of a name the first provider itself duplicated', function (): void {
        $first = (new StubSkillProvider('a'))->add('twin')->add('twin');
        $second = (new StubSkillProvider('b'))->add('twin');

        expect((new SkillProviderRegistry([$first, $second]))->getSkills(null))->toHaveCount(2);
    });

    it('forwards the principal to every provider', function (): void {
        $first = (new StubSkillProvider('a'))->add('alpha');
        $second = (new StubSkillProvider('b'))->add('beta');

        (new SkillProviderRegistry([$first, $second]))->getSkills(42);

        expect($first->lastPrincipalId)->toBe(42)
            ->and($second->lastPrincipalId)->toBe(42);
    });

    it('returns an empty list when no providers are registered', function (): void {
        expect((new SkillProviderRegistry())->getSkills(1))->toBe([]);
    });
});

describe('SkillProviderRegistry detail lookups', function (): void {
    it('returns the first provider that knows the name', function (): void {
        $registry = new SkillProviderRegistry([
            (new StubSkillProvider('a'))->add('known'),
            (new StubSkillProvider('b'))->add('known', [], 'second body'),
        ]);

        expect($registry->getSkillDetails('known', null)?->body)->toBe('body');
    });

    it('distinguishes an empty listing from an unknown skill', function (): void {
        $registry = new SkillProviderRegistry([(new StubSkillProvider('a'))->add('bare')]);

        // [] means "known, no files". null means "not visible here". Collapsing
        // them would turn a fileless skill into a 404.
        expect($registry->getSkillFiles('bare', 1))->toBe([])
            ->and($registry->getSkillFiles('missing', 1))->toBeNull()
            ->and($registry->getSkillDetails('missing', 1))->toBeNull();
    });

    it('routes the file read to the same provider that owns the name', function (): void {
        // Two providers, both knowing the name, with different file contents.
        // If the listing and the read could land in different providers, a
        // membership check would be checking one provider's answer while the
        // bytes came from another's.
        $owner = (new StubSkillProvider('owner'))->add('shared', ['only-here.md']);
        $other = (new StubSkillProvider('other'))->add('shared', ['other.md']);

        $registry = new SkillProviderRegistry([$owner, $other]);

        expect($registry->getSkillFiles('shared', 1))->toHaveCount(1)
            ->and($registry->getSkillFile('shared', 'only-here.md', 1))->toBe('content of only-here.md')
            // The owner's listing does not include it, and the owner is
            // authoritative — no falling through to the second provider.
            ->and($registry->getSkillFile('shared', 'other.md', 1))->toBeNull();
    });

    it('forwards the principal on every lookup, not just the list', function (): void {
        $provider = (new StubSkillProvider('a'))->add('x', ['f.md']);
        $registry = new SkillProviderRegistry([$provider]);

        $registry->getSkillDetails('x', 7);
        expect($provider->lastPrincipalId)->toBe(7);

        $registry->getSkillFiles('x', 8);
        expect($provider->lastPrincipalId)->toBe(8);

        $registry->getSkillFile('x', 'f.md', 9);
        expect($provider->lastPrincipalId)->toBe(9);
    });

    it('returns null from getSkillFile for an unknown skill', function (): void {
        $registry = new SkillProviderRegistry([(new StubSkillProvider('a'))->add('x')]);

        expect($registry->getSkillFile('nope', 'f.md', 1))->toBeNull();
    });
});

it('reports the provider labels in precedence order', function (): void {
    $registry = new SkillProviderRegistry([
        new StubSkillProvider('filesystem'),
        new StubSkillProvider('custom-skills'),
    ]);

    expect($registry->sources())->toBe(['filesystem', 'custom-skills']);
});
