<?php

declare(strict_types=1);

namespace Tests\Unit\Events;

use DI\Container;
use DI\ContainerBuilder;
use Spora\Core\ContainerDefinitions;
use Spora\Events\BootingEvent;
use Spora\Events\ContainerBuildingEvent;
use Spora\Events\EventDispatcherFactory;
use Spora\Events\RoutesRegisteringEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;

test('EventDispatcherFactory::create() returns a working EventDispatcher instance', function (): void {
    $dispatcher = EventDispatcherFactory::create();

    expect($dispatcher)->toBeInstanceOf(EventDispatcher::class);
});

test('EventDispatcherFactory::create() returns a fresh dispatcher on each call (factory semantics)', function (): void {
    $first  = EventDispatcherFactory::create();
    $second = EventDispatcherFactory::create();

    expect($first)->not->toBe($second);
});

test('the factory-built dispatcher dispatches Spora\\Events\\* instances and invokes listeners', function (): void {
    $dispatcher = EventDispatcherFactory::create();

    $seen = null;
    $dispatcher->addListener(ContainerBuildingEvent::class, function (ContainerBuildingEvent $event) use (&$seen): void {
        $seen = $event;
    });

    $builder = new ContainerBuilder();
    $dispatcher->dispatch(new ContainerBuildingEvent($builder));

    expect($seen)->toBeInstanceOf(ContainerBuildingEvent::class);
    expect($seen->builder())->toBe($builder);
});

test('RoutesRegisteringEvent exposes the route collector that was passed in', function (): void {
    $event = new RoutesRegisteringEvent(
        new \Spora\Core\MiddlewareRouteCollector(
            new \FastRoute\RouteParser\Std(),
            new \FastRoute\DataGenerator\GroupCountBased(),
        ),
    );

    expect($event->routes())->toBeInstanceOf(\Spora\Core\MiddlewareRouteCollector::class);
});

test('BootingEvent exposes the container that was passed in', function (): void {
    $container = new ContainerBuilder()->build();
    $event = new BootingEvent($container);

    expect($event->container())->toBe($container);
});

test("the 'event_dispatcher' container entry resolves to a working dispatcher", function (): void {
    $builder = new ContainerBuilder();
    $builder->addDefinitions(ContainerDefinitions::all());

    /** @var Container $container */
    $container = $builder->build();
    $dispatcher = $container->get('event_dispatcher');

    expect($dispatcher)->toBeInstanceOf(EventDispatcher::class);

    $hit = false;
    $dispatcher->addListener(ContainerBuildingEvent::class, function () use (&$hit): void {
        $hit = true;
    });
    $dispatcher->dispatch(new ContainerBuildingEvent(new ContainerBuilder()));

    expect($hit)->toBeTrue();
});

test("the 'event_dispatcher' container entry is a singleton across get() calls", function (): void {
    $builder = new ContainerBuilder();
    $builder->addDefinitions(ContainerDefinitions::all());

    /** @var Container $container */
    $container = $builder->build();

    expect($container->get('event_dispatcher'))->toBe($container->get('event_dispatcher'));
});
