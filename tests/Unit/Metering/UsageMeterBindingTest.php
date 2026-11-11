<?php

declare(strict_types=1);

namespace Tests\Unit\Metering;

use DI\ContainerBuilder;
use Spora\Metering\NullUsageMeter;
use Spora\Metering\UsageMeterInterface;

/**
 * The whole plugin contract rests on one container fact: the **last**
 * `addDefinitions` wins, so a plugin's `ContainerBuildingEvent` binding
 * replaces the core's null object.
 *
 * `ContainerBuilder::build()` reverses the definition sources and
 * `SourceChain::getDefinition()` returns the first match — so the last
 * source added is the first one searched.
 *
 * If this ever stops holding, a metering plugin would be installed and
 * silently ignored: the core's null object would keep answering "allow"
 * and nothing would ever be metered or blocked.
 */
it('lets a later binding replace an earlier one for the same id', function (): void {
    $builder = new ContainerBuilder();
    $builder->addDefinitions([
        UsageMeterInterface::class => static fn(): UsageMeterInterface => new NullUsageMeter(),
    ]);
    $builder->addDefinitions([
        UsageMeterInterface::class => static fn(): UsageMeterInterface => new StubUsageMeter(),
    ]);

    $container = $builder->build();

    expect($container->get(UsageMeterInterface::class))->toBeInstanceOf(StubUsageMeter::class);
});

it('keeps the core binding when no plugin adds one', function (): void {
    $builder = new ContainerBuilder();
    $builder->addDefinitions([
        UsageMeterInterface::class => static fn(): UsageMeterInterface => new NullUsageMeter(),
    ]);

    $container = $builder->build();

    expect($container->get(UsageMeterInterface::class))->toBeInstanceOf(NullUsageMeter::class);
});
