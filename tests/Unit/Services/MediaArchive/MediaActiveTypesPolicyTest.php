<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MediaArchive;

use Spora\Core\Config;
use Spora\Services\MediaArchive\MediaActiveTypesPolicy;

/**
 * The operator's two decisions about the script-capable formats.
 *
 * The distinction the tests pin is the one that matters: whether the
 * formats may *exist* is a different question from whether an embed may
 * *run* them, and the second never widens the first.
 */
test('script-capable formats are not permitted by default', function (): void {
    $policy = new MediaActiveTypesPolicy(new Config());

    expect($policy->allowActiveTypes())->toBeFalse();
    expect($policy->allowEmbedScripts())->toBeFalse();
    expect($policy->permitsCreationOf('text/html'))->toBeFalse();
    expect($policy->permitsCreationOf('image/svg+xml'))->toBeFalse();
});

test('non-script-capable formats are always permitted', function (): void {
    $policy = new MediaActiveTypesPolicy(new Config());

    expect($policy->permitsCreationOf('image/png'))->toBeTrue();
    expect($policy->permitsCreationOf('application/pdf'))->toBeTrue();
    expect($policy->permitsCreationOf('text/plain'))->toBeTrue();
});

test('enabling active types permits the script-capable formats', function (): void {
    $policy = new MediaActiveTypesPolicy(configWith(['SPORA_MEDIA_ARCHIVE_ALLOW_ACTIVE_TYPES' => true]));

    expect($policy->allowActiveTypes())->toBeTrue();
    expect($policy->permitsCreationOf('text/html'))->toBeTrue();
    expect($policy->permitsCreationOf('image/svg+xml'))->toBeTrue();
});

test('embed scripts stay off while active types are off', function (): void {
    // Both toggles on, but the embed-script toggle alone is not enough:
    // there is nothing to embed if the formats may not exist.
    $policy = new MediaActiveTypesPolicy(configWith([
        'SPORA_MEDIA_ARCHIVE_ALLOW_ACTIVE_TYPES'   => false,
        'SPORA_MEDIA_ARCHIVE_ALLOW_EMBED_SCRIPTS' => true,
    ]));

    expect($policy->allowEmbedScripts())->toBeFalse();
});

test('embed scripts follow the second toggle once active types are on', function (): void {
    $policy = new MediaActiveTypesPolicy(configWith([
        'SPORA_MEDIA_ARCHIVE_ALLOW_ACTIVE_TYPES'   => true,
        'SPORA_MEDIA_ARCHIVE_ALLOW_EMBED_SCRIPTS' => true,
    ]));

    expect($policy->allowEmbedScripts())->toBeTrue();
});

test('embed scripts stay off when only active types are on', function (): void {
    $policy = new MediaActiveTypesPolicy(configWith(['SPORA_MEDIA_ARCHIVE_ALLOW_ACTIVE_TYPES' => true]));

    expect($policy->allowActiveTypes())->toBeTrue();
    expect($policy->allowEmbedScripts())->toBeFalse();
});

/**
 * @param array<string, mixed> $values
 */
function configWith(array $values): Config
{
    return new Config($values, array_fill_keys(array_keys($values), Config::SOURCE_ENV));
}
