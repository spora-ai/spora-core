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

    /**
     * Mint the `md` derivative of every attached binary document that
     * lacks one, so the turn's prompt is built from text that is already
     * there.
     *
     * The attach-time seam, not a lazy create inside
     * {@see \Spora\Agents\AttachmentRowBuilder}: a conversion failure then
     * surfaces as an ordinary attach-time warning rather than vanishing
     * mid-turn, and the message builder stays a pure read.
     *
     * Best-effort by contract — never throws, for the same reason the
     * ingest-time mint is best-effort.
     *
     * @param list<string> $mediaIds
     */
    public function ensureTextDerivatives(array $mediaIds): void;
}
