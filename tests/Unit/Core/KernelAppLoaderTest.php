<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use Spora\Core\Kernel;
use Spora\Core\Paths;
use Spora\Extensions\AppLoader;
use Spora\Extensions\SporaExtensionInterface;

afterEach(function (): void {
    unset($_ENV['SPORA_SECRET_KEY'], $_ENV['SPORA_APP_DIR']);
    putenv('SPORA_SECRET_KEY');
    putenv('SPORA_APP_DIR');
    $_SESSION = [];
    gc_collect_cycles();
});

test('getAppLoader() returns an AppLoader instance even when no app/App.php is shipped', function (): void {
    // Kernel constructs an AppLoader unconditionally so container-managed
    // services (Database, RecipeScanner, AppRegistry, tool_instances) can
    // resolve one — even an empty one with no App loaded.
    $kernel = new Kernel();

    expect($kernel->getAppLoader())->toBeInstanceOf(AppLoader::class);
});

test('getAppLoader() returns the same instance on every call (singleton property)', function (): void {
    $kernel = new Kernel();

    expect($kernel->getAppLoader())->toBe($kernel->getAppLoader());
});

test('getAppLoader() exposes the loaded App via getApp() when app/App.php is shipped', function (): void {
    // We can't just `extends \\Spora\\Extensions\\AbstractExtension` inside
    // App.php — that would force the autoloader to register AbstractExtension
    // during `require_once`, which AppLoader's resolveAppFqcn() would then
    // incorrectly identify as the App (it picks the last implementer of
    // SporaExtensionInterface, and AbstractExtension is itself one).
    //
    // Instead we declare an existing, non-abstract App class once (via require
    // — not require_once — so it stays declared for the rest of this test
    // even if App.php gets required twice), then write App.php to extend
    // that locally-named class. require_once in App.php is then a no-op for
    // class re-declaration; the App class is picked up correctly.
    $root = sys_get_temp_dir() . '/spora-kernel-apploader-' . bin2hex(random_bytes(6));
    mkdir($root . '/app', 0755, true);

    $proxyFile = $root . '/_KAppProxy.php';
    $appClass  = 'KApp_' . bin2hex(random_bytes(6));
    file_put_contents(
        $proxyFile,
        "<?php class $appClass extends \\Spora\\Extensions\\AbstractExtension { public function getName(): string { return 'KApp'; } }",
    );
    require $proxyFile;

    $subClass = 'ExtendKApp_' . bin2hex(random_bytes(6));
    file_put_contents(
        $root . '/app/App.php',
        "<?php class $subClass extends \\$appClass {}",
    );

    try {
        $kernel = new Kernel(new Paths($root));

        /** @var SporaExtensionInterface $app */
        $app = $kernel->getAppLoader()->getApp();

        expect($app)->toBeInstanceOf(SporaExtensionInterface::class);
        expect($app->getName())->toBe('KApp');
    } finally {
        @unlink($root . '/app/App.php');
        @unlink($proxyFile);
        @rmdir($root . '/app');
        @rmdir($root);
    }
});
