<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

/**
 * Read/write surface over a task's media-attachments and the agent's LLM
 * capability matrix. Kept narrow so {@see TaskMediaCapabilityService} can be
 * swapped behind an interface in production and mocked in unit tests.
 */
interface TaskMediaCapabilityInterface
{
    /**
     * @return list<string>
     */
    public function parseMediaIds(mixed $raw): array;

    /**
     * Throw {@see MediaCapabilityMismatchException} when the supplied
     * `$mediaIds` contain an image but `$agentId`'s LLM does not support
     * image input. A no-op when no driver factory is wired in.
     *
     * @param list<string> $mediaIds
     */
    public function ensureMediaCapabilityCompatible(int $agentId, array $mediaIds): void;
}
