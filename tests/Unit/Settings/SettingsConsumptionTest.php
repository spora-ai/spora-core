<?php

declare(strict_types=1);

use Spora\Core\Paths;
use Spora\Settings\SettingsRegistry;

function declaredSettingKeys(): array
{
    return array_keys((new SettingsRegistry(new Paths(BASE_PATH)))->all());
}

/**
 * Keys read somewhere under `app/`, as the argument of a `Config` accessor.
 *
 * A literal occurrence is not a consumption: a key in a docblock, in the legacy
 * migration map, or in a log message is text. Matching the accessor call is what
 * separates a setting that is honoured from one that merely appears.
 *
 * @return list<string>
 */
function settingKeysReadInApp(): array
{
    $read = [];
    $registry = new SettingsRegistry(new Paths(BASE_PATH));
    $declaration = (new ReflectionClass($registry))->getFileName();

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(BASE_PATH . '/app'),
    );

    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        // The registry declares settings; reading one there would be the
        // declaration describing itself.
        if ($file->getPathname() === $declaration) {
            continue;
        }

        preg_match_all(
            '/->get(?:Bool|Int|String|Array|Float)?\(\s*[\'"](SPORA_[A-Z0-9_]+)[\'"]/',
            (string) file_get_contents($file->getPathname()),
            $matches,
        );

        foreach ($matches[1] as $key) {
            $read[$key] = true;
        }
    }

    $read = array_keys($read);
    sort($read);

    return $read;
}

/**
 * `ConfigLegacyMappingTest` walks the `config.php` reads from before the
 * migration and checks each lands on a declared key. That is a naming claim: it
 * would pass just as happily for a key that is declared, listed in the admin
 * page, described in the OpenAPI spec, editable by an operator — and read by
 * nothing at all.
 *
 * The failure this catches is quiet in both directions. A declared setting with
 * no consumer costs a form field, a spec entry and a row in the `settings`
 * table, and changing it has no effect anywhere. A consumer whose key was never
 * declared is a read that silently falls through to the default. Neither is
 * reachable from the legacy map alone.
 */
it('reads every declared setting somewhere in the application', function (): void {
    $orphans = array_values(array_diff(declaredSettingKeys(), settingKeysReadInApp()));

    expect($orphans)->toBe([]);
});
