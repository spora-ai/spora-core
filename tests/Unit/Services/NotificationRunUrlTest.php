<?php

declare(strict_types=1);

use Spora\Core\Config;
use Spora\Services\NotificationService;

/**
 * `buildRunUrl()` composes the link in the "your scheduled run finished"
 * email. `RequestOrigin::normalizePrefix()` returns either `''` or
 * `'/something'` — a leading slash and no trailing one — so the method used
 * to add a separator of its own and emit `https://host/spora//tasks/1` for
 * every subdirectory install. It is not a harmless typo: the link goes in an
 * email, and a double slash is not guaranteed to resolve.
 *
 * The method is private, so this reaches it the way the service does.
 */
beforeEach(function (): void {
    $this->runUrl = static function (string $appUrl, string $prefix, int $taskId): string {
        $service = (new ReflectionClass(NotificationService::class))->newInstanceWithoutConstructor();
        (new ReflectionClass(NotificationService::class))->getProperty('config')->setValue(
            $service,
            new Config(['SPORA_APP_URL' => $appUrl, 'SPORA_APP_PREFIX' => $prefix]),
        );

        return (string) (new ReflectionClass(NotificationService::class))
            ->getMethod('buildRunUrl')
            ->invoke($service, $taskId);
    };
});

it('builds a task url with no doubled separator', function (string $appUrl, string $prefix, string $expected): void {
    expect(($this->runUrl)($appUrl, $prefix, 7))->toBe($expected);
})->with([
    'prefix without slashes' => ['https://host.test', 'spora', 'https://host.test/spora/tasks/7'],
    'prefix with slashes'    => ['https://host.test', '/spora/', 'https://host.test/spora/tasks/7'],
    'empty prefix'           => ['https://host.test', '', 'https://host.test/tasks/7'],
    'app_url with a slash'   => ['https://host.test/', 'spora', 'https://host.test/spora/tasks/7'],
    'nested prefix'          => ['https://host.test', 'a/b', 'https://host.test/a/b/tasks/7'],
]);

it('never emits a doubled slash in the path', function (string $appUrl, string $prefix): void {
    $result = ($this->runUrl)($appUrl, $prefix, 1);
    $path = substr($result, (int) strpos($result, '//', strlen('https:')) + 2);

    expect($path)->not->toContain('//');
})->with([
    'subdirectory' => ['https://host.test', 'spora'],
    'no prefix'    => ['https://host.test', ''],
    'deep prefix'  => ['https://host.test', '/a/b/'],
]);