<?php

declare(strict_types=1);

use OpenApi\Annotations\OpenApi;
use Spora\Console\Commands\OpenApiGenerateCommand;
use Spora\Http\HealthController;
use Spora\OpenApi\RouteSpecCollector;
use Spora\OpenApi\RouteToOpenApi;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Mirrors `RouteDefinitions::register()` but captures the rows into a plain list, so the
 * test can assert the OpenApi document covers every route.
 */
function openApiTestCollectRoutes(): array
{
    $collector = new RouteSpecCollector();
    Spora\Core\RouteDefinitions::register($collector);

    return $collector->routes();
}

function openApiTestSpec(): OpenApi
{
    return (new RouteToOpenApi())->build();
}

function openApiTestFindOperation(OpenApi $spec, string $method, string $path): ?object
{
    $method = strtolower($method);
    $pathItem = $spec->paths[$path] ?? null;

    return $pathItem ? ($pathItem->{$method} ?? null) : null;
}

describe('OpenAPI specification generation', function (): void {
    it('produces an OpenAPI document with the expected root metadata', function (): void {
        $spec = openApiTestSpec();

        expect($spec->openapi)->toStartWith('3.');
        expect($spec->info->title)->toBe('Spora API');
        expect($spec->info->version)->not->toBeEmpty();
        expect($spec->servers)->toHaveCount(1);
        expect($spec->servers[0]->url)->toBe('/');
    });

    it('declares cookieAuth and csrfToken security schemes', function (): void {
        $spec = openApiTestSpec();

        expect($spec->components->securitySchemes)->toHaveCount(2);

        $names = array_map(static fn($s) => $s->securityScheme, $spec->components->securitySchemes);
        expect($names)->toContain('cookieAuth');
        expect($names)->toContain('csrfToken');
    });

    it('covers every route registered in RouteDefinitions', function (): void {
        $spec = openApiTestSpec();
        $missing = [];

        foreach (openApiTestCollectRoutes() as $entry) {
            $op = openApiTestFindOperation(
                $spec,
                $entry['method'],
                preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*):[^\}]+\}/', '{$1}', $entry['route']) ?? $entry['route'],
            );
            if ($op === null) {
                $missing[] = sprintf('%s %s', $entry['method'], $entry['route']);
            }
        }

        expect($missing)->toBe([], sprintf('Spec is missing routes: %s', implode(', ', $missing)));
    });

    it('derives security from AuthMiddleware', function (): void {
        $spec = openApiTestSpec();

        $op = openApiTestFindOperation($spec, 'GET', '/api/v1/agents');
        expect($op)->not->toBeNull();
        expect(array_column($op->security, 'cookieAuth'))->not->toBe([]);
    });

    it('derives csrfToken security from CsrfMiddleware', function (): void {
        $spec = openApiTestSpec();

        $op = openApiTestFindOperation($spec, 'POST', '/api/v1/agents');
        expect($op)->not->toBeNull();
        expect(array_column($op->security, 'csrfToken'))->not->toBe([]);
    });

    it('leaves security empty for public (login) routes', function (): void {
        $spec = openApiTestSpec();

        $op = openApiTestFindOperation($spec, 'POST', '/api/v1/auth/login');
        expect($op)->not->toBeNull();
        expect($op->security)->toBe([]);
    });

    it('tags operations by the first /api/v1/ segment', function (): void {
        $spec = openApiTestSpec();

        $op = openApiTestFindOperation($spec, 'GET', '/api/v1/agents');
        expect($op->tags)->toContain('Agents');
    });

    it('drops FastRoute regex constraints on path params', function (): void {
        $spec = openApiTestSpec();

        if (!isset($spec->paths['/api/v1/agent-templates/{id}'])) {
            // The route exists in RouteDefinitions with the {id:.+} form; the adapter
            // should normalise it. If absent, the test target is wrong — fail loudly.
            expect($spec->paths)->toHaveKey('/api/v1/agent-templates/{id}');
        }

        $op = $spec->paths['/api/v1/agent-templates/{id}']->get ?? null;
        expect($op)->not->toBeNull();

        $paramNames = array_map(static fn($p) => $p->name, $op->parameters);
        expect($paramNames)->toContain('id');
    });
});

describe('RouteSpecCollector', function (): void {
    it('records routes with uppercased method, split handler, and preserved middleware', function (): void {
        $collector = new RouteSpecCollector();

        $collector->addRoute('get', '/x', [HealthController::class, 'check'], []);
        $collector->addRoute('post', '/y', [HealthController::class, 'check'], ['Some\Middleware']);
        $collector->addRoute('Delete', '/z', [HealthController::class, 'check'], ['A\Mw', 'B\Mw']);

        $routes = $collector->routes();
        expect($routes)->toHaveCount(3);

        expect($routes[0])->toBe([
            'method' => 'GET',
            'route' => '/x',
            'handler' => [HealthController::class, 'check'],
            'middleware' => [],
        ]);

        expect($routes[1]['method'])->toBe('POST');
        expect($routes[1]['route'])->toBe('/y');
        expect($routes[1]['handler'])->toBe([HealthController::class, 'check']);
        expect($routes[1]['middleware'])->toBe(['Some\Middleware']);

        expect($routes[2]['method'])->toBe('DELETE');
        expect($routes[2]['middleware'])->toBe(['A\Mw', 'B\Mw']);
    });
});

describe('OpenApiGenerateCommand::regenerate', function (): void {
    it('writes a spec whose JSON parses back with the expected info.title', function (): void {
        $path = tempnam(sys_get_temp_dir(), 'openapi-regen-');
        expect($path)->not->toBeFalse();

        try {
            $status = OpenApiGenerateCommand::regenerate($path);
            expect($status)->toBe(Command::SUCCESS);

            $decoded = json_decode((string) file_get_contents($path), true);
            expect($decoded)->toBeArray();
            expect($decoded['info']['title'])->toBe('Spora API');
        } finally {
            @unlink($path);
        }
    });

    it('returns FAILURE when the output path is not writable', function (): void {
        $dir = sys_get_temp_dir() . '/openapi-regen-nowrite-' . uniqid();
        mkdir($dir, 0755);
        $target = $dir . '/spec.json';

        try {
            // POSIX: strip write perms so file_put_contents fails. On systems where
            // chmod is a no-op (or running as root), fall back to targeting a path
            // inside a removed directory, which also makes file_put_contents return false.
            @chmod($dir, 0000);

            if (is_writable($dir)) {
                @chmod($dir, 0755);
                rmdir($dir);
                $target = $dir . '/spec.json';
            }

            $status = OpenApiGenerateCommand::regenerate($target);
            expect($status)->toBe(Command::FAILURE);
        } finally {
            @chmod($dir, 0755);
            @unlink($target);
            @rmdir($dir);
        }
    });
});

describe('OpenApiGenerateCommand::checkAgainstFile', function (): void {
    it('returns SUCCESS when the committed spec matches the freshly generated one', function (): void {
        $path = tempnam(sys_get_temp_dir(), 'openapi-check-ok-');
        expect($path)->not->toBeFalse();

        try {
            expect(OpenApiGenerateCommand::regenerate($path))->toBe(Command::SUCCESS);
            expect(OpenApiGenerateCommand::checkAgainstFile($path))->toBe(Command::SUCCESS);
        } finally {
            @unlink($path);
        }
    });

    it('returns FAILURE when the committed spec is missing', function (): void {
        $status = OpenApiGenerateCommand::checkAgainstFile('/nonexistent/path/spec.json');
        expect($status)->toBe(Command::FAILURE);
    });

    it('returns FAILURE when the committed spec is stale', function (): void {
        $path = tempnam(sys_get_temp_dir(), 'openapi-check-stale-');
        expect($path)->not->toBeFalse();

        try {
            file_put_contents($path, '{not json}');
            expect(OpenApiGenerateCommand::checkAgainstFile($path))->toBe(Command::FAILURE);
        } finally {
            @unlink($path);
        }
    });
});

describe('OpenApiGenerateCommand construction', function (): void {
    it('constructs without booting the DI graph', function (): void {
        $command = new OpenApiGenerateCommand();

        expect($command->getName())->toBe('spora:openapi');
    });
});

describe('OpenApiGenerateCommand::execute', function (): void {
    it('writes the spec to an absolute --output path and reports success', function (): void {
        $path = tempnam(sys_get_temp_dir(), 'openapi-exec-');
        expect($path)->not->toBeFalse();

        try {
            $tester = new CommandTester(new OpenApiGenerateCommand());
            $exit = $tester->execute(['--output' => $path]);

            expect($exit)->toBe(Command::SUCCESS);
            expect($tester->getDisplay())->toContain('Wrote');

            $decoded = json_decode((string) file_get_contents($path), true);
            expect($decoded['info']['title'])->toBe('Spora API');
        } finally {
            @unlink($path);
        }
    });

    it('resolves a relative --output path against the working directory', function (): void {
        $name = 'openapi-exec-rel-' . uniqid() . '.json';
        $target = rtrim((string) getcwd(), '/') . '/' . $name;

        try {
            $tester = new CommandTester(new OpenApiGenerateCommand());
            $exit = $tester->execute(['--output' => $name]);

            expect($exit)->toBe(Command::SUCCESS);
            expect(is_file($target))->toBeTrue();
        } finally {
            @unlink($target);
        }
    });

    it('reports FAILURE from --check when no committed spec exists', function (): void {
        $tester = new CommandTester(new OpenApiGenerateCommand());
        $exit = $tester->execute([
            '--output' => '/nonexistent/openapi-exec/spec.json',
            '--check' => true,
        ]);

        expect($exit)->toBe(Command::FAILURE);
        expect($tester->getDisplay())->toContain('No committed spec');
    });

    it('reports SUCCESS from --check when the committed spec is current', function (): void {
        $path = tempnam(sys_get_temp_dir(), 'openapi-exec-check-');
        expect($path)->not->toBeFalse();

        try {
            (new CommandTester(new OpenApiGenerateCommand()))->execute(['--output' => $path]);

            $tester = new CommandTester(new OpenApiGenerateCommand());
            $exit = $tester->execute(['--output' => $path, '--check' => true]);

            expect($exit)->toBe(Command::SUCCESS);
            expect($tester->getDisplay())->toContain('up to date');
        } finally {
            @unlink($path);
        }
    });

    it('reports FAILURE from --check when the committed spec is stale', function (): void {
        $path = tempnam(sys_get_temp_dir(), 'openapi-exec-stale-');
        expect($path)->not->toBeFalse();

        try {
            file_put_contents($path, '{not json}');

            $tester = new CommandTester(new OpenApiGenerateCommand());
            $exit = $tester->execute(['--output' => $path, '--check' => true]);

            expect($exit)->toBe(Command::FAILURE);
            expect($tester->getDisplay())->toContain('stale');
        } finally {
            @unlink($path);
        }
    });
});
