<?php

declare(strict_types=1);

/**
 * The i18n fixtures are written into the shared system temp directory, so
 * the helper that builds them has to be as careful as the code it stands in
 * for: a directory it did not create is never adopted, and a fixture is
 * never written through a symlink.
 */
test('the fixture directory is fresh, private, and never one that already existed', function (): void {
    $name = 'planted-' . bin2hex(random_bytes(4));

    // What another run (or another user) could leave behind: a directory
    // with the predictable name, whose child points at a file the test
    // runner can write.
    $victim = sys_get_temp_dir() . '/spora-i18n-victim-' . bin2hex(random_bytes(4));
    file_put_contents($victim, 'original');

    $planted = sys_get_temp_dir() . '/spora-i18n-' . $name;
    mkdir($planted, 0755, true);
    symlink($victim, $planted . '/de.json');

    try {
        $dir = makeLocaleDir(['de' => ['Save' => 'Speichern']], $name);

        expect($dir)->not->toBe($planted)
            ->and(file_get_contents($victim))->toBe('original')
            ->and(fileperms($dir) & 0o777)->toBe(0o700)
            ->and(json_decode((string) file_get_contents($dir . '/de.json'), true))
            ->toBe(['Save' => 'Speichern']);

        removeLocaleDir($dir);
    } finally {
        @unlink($planted . '/de.json');
        @rmdir($planted);
        @unlink($victim);
    }
});

test('two fixture directories with the same name do not collide', function (): void {
    $name = 'twice-' . bin2hex(random_bytes(4));

    $first = makeLocaleDir(['de' => ['Save' => 'Speichern']], $name);
    $second = makeLocaleDir(['de' => ['Save' => 'Sparen']], $name);

    try {
        expect($first)->not->toBe($second)
            ->and(json_decode((string) file_get_contents($first . '/de.json'), true))
            ->toBe(['Save' => 'Speichern'])
            ->and(json_decode((string) file_get_contents($second . '/de.json'), true))
            ->toBe(['Save' => 'Sparen']);
    } finally {
        removeLocaleDir($first);
        removeLocaleDir($second);
    }
});
