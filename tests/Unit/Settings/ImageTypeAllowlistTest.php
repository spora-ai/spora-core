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

    // Joined and compared as one string rather than by array membership.
    // Membership let this pass against the bug it was written for: a JSON
    // input yields the token `"svg"` — quotes still attached — and
    // `'"svg"' !== 'svg'`, so the assertion held while the allowlist carried
    // an SVG entry. This test reported the control as covered; it was not.
    //
    // One assertion over the joined value rather than a loop, so a fully
    // rejected list still counts as an assertion instead of a risky test.
    expect(strtolower(implode(' ', array_map('strval', $result))))->not->toContain('svg');
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

    expect($registry->decode($definition, $registry->encode(['png', 'svg', 'jpeg'])))
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

/**
 * The admin page renders a `list` setting into a textarea. The datasets
 * above all pass, and did so while the control was open: an array input
 * reaches `coerce()` and an array-shaped string reaches `decode()`, which
 * JSON-decodes before tokenising — so neither of them ever produced the
 * quotes that defeat the `svg` check.
 *
 * The write path is the one that sees them. `SettingsRepository::put()`
 * passes the textarea's text to `coerce()`, where it is split on whitespace
 * and commas *without* being JSON-decoded first, so `[` and `"` survive into
 * the tokens. `'svg'` quoted is not `svg`-prefixed, so it was allowed.
 */
it('rejects svg from the write path when the value arrives as textarea text', function (string $input): void {
    $registry = new SettingsRegistry(new Paths(BASE_PATH));
    $definition = $registry->get('SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES');

    $result = $registry->coerce($definition, $input);

    expect(strtolower(implode(' ', array_map('strval', $result))))->not->toContain('svg');
})->with([
    'as the textarea renders an untouched list' => '["png","svg"]',
    'with an operator edit below it'            => "[\"png\",\"svg\"]\ngif",
    'single quoted'                             => "'svg'",
    'bracketed without quotes'                  => '[svg]',
]);

it('stores clean tokens when a textarea-sent list is coerced', function (): void {
    // Without this the allowlist is silently corrupted for every edit: the
    // quotes become part of each stored entry, so `png` stops matching the
    // real MIME subtype and the allowlist permits nothing at all.
    $registry = new SettingsRegistry(new Paths(BASE_PATH));
    $definition = $registry->get('SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES');

    expect($registry->coerce($definition, "[\"png\",\"jpeg\"]\ngif"))->toBe(['png', 'jpeg', 'gif']);
});
