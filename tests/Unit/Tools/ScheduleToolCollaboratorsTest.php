<?php

declare(strict_types=1);

use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Spora\Tools\ScheduleTool\ScheduleListPresenter;
use Spora\Tools\ScheduleTool\SchedulePayloadValidator;
use Spora\Tools\ScheduleTool\ScheduleSummaryPresenter;
use Spora\Tools\ScheduleTool\ScheduleTargetResolver;
use Spora\Tools\ScheduleTool\ScheduleToolCollaborators;
use Spora\Tools\ScheduleTool\ScheduleUpdateValidator;

describe('ScheduleToolCollaborators — lazy builders', function (): void {
    test('every accessor returns a real instance when the bundle is zero-arg', function (): void {
        $bundle = new ScheduleToolCollaborators();

        expect($bundle->targetResolver())->toBeInstanceOf(ScheduleTargetResolver::class)
            ->and($bundle->payloadValidator())->toBeInstanceOf(SchedulePayloadValidator::class)
            ->and($bundle->updateValidator())->toBeInstanceOf(ScheduleUpdateValidator::class)
            ->and($bundle->listPresenter())->toBeInstanceOf(ScheduleListPresenter::class)
            ->and($bundle->summary())->toBeInstanceOf(ScheduleSummaryPresenter::class)
            ->and($bundle->principalResolver())->toBeInstanceOf(PrincipalResolver::class)
            ->and($bundle->principalService())->toBeInstanceOf(PrincipalService::class)
            ->and($bundle->authService())->toBeNull();
    });

    test('injected principal collaborators + summary win over the lazy defaults', function (): void {
        $resolver = new PrincipalResolver();
        $service  = new PrincipalService($resolver);
        $summary  = new ScheduleSummaryPresenter();

        $bundle = new ScheduleToolCollaborators(
            principalResolver: $resolver,
            principalService: $service,
            summary: $summary,
        );

        expect($bundle->principalResolver())->toBe($resolver)
            ->and($bundle->principalService())->toBe($service)
            ->and($bundle->summary())->toBe($summary);
    });
});
