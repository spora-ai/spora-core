<?php

declare(strict_types=1);

namespace Spora\Agents;

/**
 * The trailing options of a task start.
 *
 * Bundled because {@see OrchestratorInterface::start()} was already at the
 * seven-parameter cap the project keeps to itself, and one more parameter
 * would have been a new static-analysis finding on a signature that had no
 * room left. The effort rides inside rather than beside them.
 */
final readonly class TaskStartOptions
{
    /**
     * @param list<string> $mediaIds Media Archive ids to attach to the turn.
     */
    public function __construct(
        public ?int $runId = null,
        public array $mediaIds = [],
        public ?int $userId = null,
        public ?string $effort = null,
    ) {}
}
