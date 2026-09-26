<?php

declare(strict_types=1);

namespace Tests\Unit\Tools\Attributes;

use InvalidArgumentException;
use Spora\Tools\Attributes\Tool;

/**
 * Pure-attribute tests for `#[Tool(recommendsSkills: ...)]`. These pin
 * the slug regex + trim semantics so plugin authors see the failure at
 * registration time (HTTP 500 the first time the attribute is
 * instantiated), not as a silent runtime oddity.
 */

test('valid slugs are accepted and exposed via getRecommendsSkills()', function (): void {
    $attr = new Tool(
        name: 'a_tool',
        description: 'd',
        recommendsSkills: ['git', 'time-arithmetic'],
    );

    expect($attr->getRecommendsSkills())->toBe(['git', 'time-arithmetic']);
});

test('null and [] both produce an empty recommendsSkills list', function (): void {
    $nullCase = new Tool(name: 'a_tool', description: 'd', recommendsSkills: null);
    $emptyCase = new Tool(name: 'b_tool', description: 'd', recommendsSkills: []);

    expect($nullCase->getRecommendsSkills())->toBe([])
        ->and($nullCase->recommendsSkills)->toBe([])
        ->and($emptyCase->getRecommendsSkills())->toBe([])
        ->and($emptyCase->recommendsSkills)->toBe([]);
});

test('default constructor (no recommendsSkills) produces an empty list', function (): void {
    $attr = new Tool(name: 'a_tool', description: 'd');

    expect($attr->getRecommendsSkills())->toBe([])
        ->and($attr->recommendsSkills)->toBe([]);
});

test('leading and trailing whitespace is trimmed, empty entries are skipped', function (): void {
    $attr = new Tool(
        name: 'a_tool',
        description: 'd',
        recommendsSkills: ['  git  ', '', "\t", 'time-arithmetic'],
    );

    expect($attr->getRecommendsSkills())->toBe(['git', 'time-arithmetic']);
});

test('double-hyphen slug is rejected', function (): void {
    new Tool(
        name: 'a_tool',
        description: 'd',
        recommendsSkills: ['foo--bar'],
    );
})->throws(InvalidArgumentException::class, 'foo--bar');

test('uppercase slug is rejected', function (): void {
    new Tool(
        name: 'a_tool',
        description: 'd',
        recommendsSkills: ['Git'],
    );
})->throws(InvalidArgumentException::class, 'Git');

test('leading-hyphen slug is rejected', function (): void {
    new Tool(
        name: 'a_tool',
        description: 'd',
        recommendsSkills: ['-git'],
    );
})->throws(InvalidArgumentException::class, '-git');

test('trailing-hyphen slug is rejected', function (): void {
    new Tool(
        name: 'a_tool',
        description: 'd',
        recommendsSkills: ['git-'],
    );
})->throws(InvalidArgumentException::class, 'git-');

test('slug longer than 64 characters is rejected', function (): void {
    new Tool(
        name: 'a_tool',
        description: 'd',
        recommendsSkills: [str_repeat('a', 65)],
    );
})->throws(InvalidArgumentException::class, str_repeat('a', 65));

test('slug exactly 64 characters is accepted', function (): void {
    $maxLen = str_repeat('a', 64);

    $attr = new Tool(
        name: 'a_tool',
        description: 'd',
        recommendsSkills: [$maxLen],
    );

    expect($attr->getRecommendsSkills())->toBe([$maxLen]);
});

test('non-string recommendsSkills entry is rejected', function (): void {
    new Tool(
        name: 'a_tool',
        description: 'd',
        recommendsSkills: [123],
    );
})->throws(InvalidArgumentException::class, 'non-string');

test('slug containing underscore (illegal under the agentskills.io rule) is rejected', function (): void {
    new Tool(
        name: 'a_tool',
        description: 'd',
        recommendsSkills: ['my_skill'],
    );
})->throws(InvalidArgumentException::class, 'my_skill');

test('slug with internal single hyphen is accepted', function (): void {
    $attr = new Tool(
        name: 'a_tool',
        description: 'd',
        recommendsSkills: ['time-arithmetic'],
    );

    expect($attr->getRecommendsSkills())->toBe(['time-arithmetic']);
});

test('single-character slug is accepted', function (): void {
    $attr = new Tool(
        name: 'a_tool',
        description: 'd',
        recommendsSkills: ['g'],
    );

    expect($attr->getRecommendsSkills())->toBe(['g']);
});
