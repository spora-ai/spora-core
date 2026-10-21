<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Spora\Core\Router;
use Spora\I18n\CatalogLoader;
use Spora\I18n\Translator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers with the response the test hands it, so the router is the only
 * thing under test.
 */
final class RouterTranslationController
{
    public function __construct(private readonly Response $response) {}

    public function handle(Request $request): Response
    {
        return $this->response;
    }
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir() . '/spora-i18n-*') ?: [] as $dir) {
        removeLocaleDir($dir);
    }
});

/**
 * A router whose only route answers with `$response`, in German.
 *
 * @param array<string, string> $messages the German catalogue
 */
function germanRouter(Response $response, array $messages): Router
{
    $translator = new Translator(new CatalogLoader([], makeLocaleDir(['de' => $messages])));
    $translator->useLocale('de');

    $container = (new ContainerBuilder())->build();
    $container->set(RouterTranslationController::class, new RouterTranslationController($response));

    return new Router(
        $container,
        function (FastRoute\RouteCollector $r): void {
            $r->addRoute('GET', '/answer', [RouterTranslationController::class, 'handle']);
        },
        $translator,
    );
}

test('an error envelope message is translated when the catalogue has an entry', function (): void {
    $router = germanRouter(
        new JsonResponse([
            'error' => ['code' => 'NOT_FOUND', 'message' => 'The requested resource was not found.'],
        ], Response::HTTP_NOT_FOUND),
        ['The requested resource was not found.' => 'Die angeforderte Ressource wurde nicht gefunden.'],
    );

    $body = json_decode((string) $router->dispatch(Request::create('/answer', 'GET'))->getContent(), true);

    expect($body['error']['message'])->toBe('Die angeforderte Ressource wurde nicht gefunden.');
});

test('the error code is left alone while the message is translated', function (): void {
    $router = germanRouter(
        new JsonResponse([
            'error' => ['code' => 'NOT_FOUND', 'message' => 'The requested resource was not found.'],
        ], Response::HTTP_NOT_FOUND),
        [
            'The requested resource was not found.' => 'Die angeforderte Ressource wurde nicht gefunden.',
            // A code is what a client branches on, so it must survive the
            // translation untouched even when the catalogue has an entry.
            'NOT_FOUND' => 'NICHT_GEFUNDEN',
        ],
    );

    $body = json_decode((string) $router->dispatch(Request::create('/answer', 'GET'))->getContent(), true);

    expect($body['error']['code'])->toBe('NOT_FOUND')
        ->and($body['error']['message'])->toBe('Die angeforderte Ressource wurde nicht gefunden.');
});

test('a message with no catalogue entry is returned unchanged', function (): void {
    $router = germanRouter(
        new JsonResponse([
            'error' => ['code' => 'NOT_FOUND', 'message' => 'Not in the catalogue.'],
        ], Response::HTTP_NOT_FOUND),
        ['The requested resource was not found.' => 'Die angeforderte Ressource wurde nicht gefunden.'],
    );

    $body = json_decode((string) $router->dispatch(Request::create('/answer', 'GET'))->getContent(), true);

    // The English source is the fallback, not an empty message.
    expect($body['error']['message'])->toBe('Not in the catalogue.');
});

test('a response without an error key is untouched', function (): void {
    $response = new JsonResponse(['data' => ['message' => 'The requested resource was not found.']]);

    $router = germanRouter($response, [
        'The requested resource was not found.' => 'Die angeforderte Ressource wurde nicht gefunden.',
    ]);

    $dispatched = $router->dispatch(Request::create('/answer', 'GET'));

    // Only the error envelope carries operator copy; a payload's own
    // `message` key is data and belongs to the caller.
    expect($dispatched->getContent())->toBe($response->getContent());
});

test('a response that is not JSON is untouched', function (): void {
    $response = new Response('The requested resource was not found.', Response::HTTP_OK);

    $router = germanRouter($response, [
        'The requested resource was not found.' => 'Die angeforderte Ressource wurde nicht gefunden.',
    ]);

    $dispatched = $router->dispatch(Request::create('/answer', 'GET'));

    expect($dispatched->getContent())->toBe('The requested resource was not found.');
});

test('a response whose body is not an array is untouched', function (): void {
    $response = new JsonResponse('The requested resource was not found.');

    $router = germanRouter($response, [
        'The requested resource was not found.' => 'Die angeforderte Ressource wurde nicht gefunden.',
    ]);

    $dispatched = $router->dispatch(Request::create('/answer', 'GET'));

    expect($dispatched->getContent())->toBe($response->getContent());
});

test("the router's own not-found envelope is translated", function (): void {
    $translator = new Translator(new CatalogLoader([], makeLocaleDir([
        'de' => ['The requested resource was not found.' => 'Die angeforderte Ressource wurde nicht gefunden.'],
    ])));
    $translator->useLocale('de');

    $router = new Router(
        (new ContainerBuilder())->build(),
        function (FastRoute\RouteCollector $r): void {
            $r->addRoute('GET', '/answer', [RouterTranslationController::class, 'handle']);
        },
        $translator,
    );

    $body = json_decode((string) $router->dispatch(Request::create('/nowhere', 'GET'))->getContent(), true);

    expect($body['error']['message'])->toBe('Die angeforderte Ressource wurde nicht gefunden.');
});
