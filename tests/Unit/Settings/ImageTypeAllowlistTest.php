<?php

declare(strict_types=1);

use Spora\Core\Paths;
use Spora\Settings\SettingDefinition;
use Spora\Settings\SettingsRegistry;

/**
 * `SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES` is the allowlist behind
 * `MediaAllowedTypesService`, which `MediaDerivativeService` calls the
 * deliberate surface for images. SVG is excluded because there is no
 * sanitization pass: serving an unrendered SVG from that allowlist is script
 * execution.
 *
 * Every input form is asserted deliberately. The exclusion originally lived
 * in `ConfigMerger::parseImageTypesCsv()`, which only ever saw a string from
 * the environment. Moving it into the registry put it behind a `decode()`
 * that short-circuited on JSON — so the JSON branch, used by both the
 * database and the admin API, accepted `svg` while the `.env` branch still
 * rejected it. A filter that runs on only one path is not a filter.
 */
it('rejects svg from every input form', function (mixed $input): void {
    $registry = new SettingsRegistry(new Paths(BASE_PATH));
    $definition = $registry->get('SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES');

    $result = is_array($input)
        ? $registry->coerce($definition, $input)
        : $registry->decode($definition, $input);

    expect($result)->not->toContain('svg')
        ->and($result)->not->toContain('svg+xml');
})->with([
    'env var, comma separated' => ['png,svg,jpeg'],
    'env var, whitespace'      => ["png\nsvg\njpeg"],
    'env var, uppercase'       => ['PNG,SVG,JPEG'],
    'env var, dot prefix'      => ['.svg,png'],
    'stored JSON'              => ['["png","svg","jpeg"]'],
    'stored JSON, svg+xml'     => ['["svg+xml"]'],
    'JSON array body'          => [['png', 'svg', 'jpeg']],
    'JSON array body, upper'   => [['png', '.SVG']],
    'gzipped svg'              => ['png,svgz'],
    'full mime type'           => ['image/svg+xml,png'],
    'json mime type'           => ['["image/png","image/svg+xml"]'],
]);

it('rejects svg from the write path, not only the read path', function (): void {
    // `encode()` only formats, so a caller that skipped the registry could
    // persist anything. This is the shape the admin endpoint will arrive in.
    $registry = new SettingsRegistry(new Paths(BASE_PATH));
    $definition = $registry->get('SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES');

    expect($registry->coerce($definition, ['png', 'svg', 'image/svg+xml']))->toBe(['png']);
});

it('survives the encode/decode round trip', function (): void {
    $registry = new SettingsRegistry(new Paths(BASE_PATH));
    $definition = $registry->get('SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES');

    expect($registry->decode($definition, $registry->encode($definition, ['png', 'svg', 'jpeg'])))
        ->toBe(['png', 'jpeg']);
});

it('still allows the documented formats and collapses jpg to jpeg', function (): void {
    $registry = new SettingsRegistry(new Paths(BASE_PATH));
    $definition = $registry->get('SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES');

    expect($registry->decode($definition, 'png,jpg,jpeg'))->toBe(['png', 'jpeg'])
        ->and($registry->decode($definition, 'PNG JPEG WEBP'))->toBe(['png', 'jpeg', 'webp']);
});

it('distinguishes an empty list from an absent one', function (): void {
    // `[]` is the operator explicitly disabling image uploads; the registry
    // default is what an absent setting means. If the two collapsed, an
    // emptied field would silently re-enable uploads.
    $registry = new SettingsRegistry(new Paths(BASE_PATH));
    $definition = $registry->get('SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES');

    expect($registry->decode($definition, ''))->toBe([])
        ->and($definition->default)->toBe(['png', 'jpeg', 'webp']);
});

it('does not apply the svg rule to lists that do not declare it', function (): void {
    // The exclusion belongs to the image allowlist specifically. Applying it
    // globally would quietly mangle an unrelated list that legitimately holds
    // the string "svg".
    $registry = new SettingsRegistry(new Paths(BASE_PATH));
    $plain = new SettingDefinition(key: 'SPORA_TEST_PLAIN_LIST', label: 't', type: SettingDefinition::TYPE_LIST);

    expect($registry->decode($plain, 'png,svg'))->toBe(['png', 'svg']);
});
