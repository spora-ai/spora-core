<?php

declare(strict_types=1);

use Spora\Agents\MessageHistoryBuilder;
use Spora\Tools\MediaInspectImageHandler;

/**
 * The archive's asset files live under the consumer's project root, and
 * two readers have to agree on where that is: the tool that shows an
 * image to the model and the history builder that replays one.
 *
 * The fallback branch — the one taken when `BASE_PATH` is undefined —
 * is unreachable from inside the suite, because the Pest bootstrap
 * always defines the constant. So it is exercised in a subprocess that
 * loads the autoloader and nothing else.
 */
test('the fallback base path is the repository root when BASE_PATH is undefined', function (): void {
    $script = sprintf(
        'require %s;'
        . ' $classes = json_decode($argv[1], true);'
        . ' $builder = new ReflectionMethod($classes[0], "basePath");'
        . ' $handler = new ReflectionMethod($classes[1], "basePath");'
        . ' echo $builder->invoke(new $classes[0]()), "\n";'
        . ' echo $handler->invoke(new $classes[1]()), "\n";',
        var_export(BASE_PATH . '/vendor/autoload.php', true),
    );

    $process = proc_open(
        [PHP_BINARY, '-r', $script, json_encode([
            MessageHistoryBuilder::class,
            MediaInspectImageHandler::class,
        ])],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );

    expect($process)->toBeResource();

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    expect($stderr)->toBe('');

    $paths = array_values(array_filter(explode("\n", $stdout)));
    expect($paths)->toHaveCount(2);

    // tests/Unit/Agents → tests/Unit → tests → the repository root.
    $repositoryRoot = dirname(__DIR__, 3);

    expect($paths[0])->toBe($repositoryRoot)
        ->and($paths[1])->toBe($repositoryRoot);
});
