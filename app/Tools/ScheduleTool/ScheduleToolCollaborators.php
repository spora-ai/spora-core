<?php

declare(strict_types=1);

namespace Spora\Tools\ScheduleTool;

use Spora\Auth\AuthService;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;

/**
 * Optional helpers + framework collaborators that `ScheduleTool` consumes.
 *
 * Every member defaults to null and is constructed lazily on first
 * access — unit tests can omit the whole bundle (zero-arg constructor
 * is fine) or override one member at a time.
 */
final class ScheduleToolCollaborators
{
    public function __construct(
        private readonly ?ScheduleTargetResolver $targetResolver = null,
        private readonly ?SchedulePayloadValidator $payloadValidator = null,
        private readonly ?ScheduleUpdateValidator $updateValidator = null,
        private readonly ?ScheduleListPresenter $listPresenter = null,
        private readonly ?ScheduleSummaryPresenter $summary = null,
        private readonly ?PrincipalResolver $principalResolver = null,
        private readonly ?PrincipalService $principalService = null,
        private readonly ?AuthService $authService = null,
    ) {}

    public function targetResolver(): ScheduleTargetResolver
    {
        return $this->targetResolver ?? new ScheduleTargetResolver();
    }

    public function payloadValidator(): SchedulePayloadValidator
    {
        return $this->payloadValidator ?? new SchedulePayloadValidator();
    }

    public function updateValidator(): ScheduleUpdateValidator
    {
        return $this->updateValidator ?? new ScheduleUpdateValidator();
    }

    public function listPresenter(): ScheduleListPresenter
    {
        return $this->listPresenter ?? new ScheduleListPresenter();
    }

    public function summary(): ScheduleSummaryPresenter
    {
        return $this->summary ?? new ScheduleSummaryPresenter();
    }

    public function principalResolver(): PrincipalResolver
    {
        return $this->principalResolver ?? new PrincipalResolver();
    }

    public function principalService(): PrincipalService
    {
        return $this->principalService ?? new PrincipalService(new PrincipalResolver());
    }

    public function authService(): ?AuthService
    {
        return $this->authService;
    }
}
