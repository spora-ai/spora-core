<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use Spora\Drivers\DriverFactory;
use Spora\Models\Agent;
use Spora\Models\MediaAsset;
use Throwable;

/**
 * Pre-flight guard for media attachments submitted with a task.
 *
 * Extracted from {@see \Spora\Http\TaskController} so the controller stays
 * under the 20-method Sonar threshold and the capability logic is
 * independently unit-testable.
 *
 * Plan §8.3 — reject an image attachment when the agent's LLM cannot
 * consume image blocks, returning a clear HTTP 400 at the request boundary
 * rather than a silent image-strip during the first tick.
 */
final class TaskMediaCapabilityService
{
    public function __construct(
        private readonly ?DriverFactory $driverFactory = null,
    ) {}

    /**
     * Coerce the raw `media_ids` body field into a validated list of
     * non-empty strings.
     *
     * @return list<string>
     */
    public function parseMediaIds(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $id) {
            if (is_string($id) && $id !== '') {
                $out[] = $id;
            }
        }
        return $out;
    }

    /**
     * Reject an image attachment when the agent's LLM cannot consume image
     * blocks. Plan §8.3 / §12 require a 400 at the request boundary rather
     * than a silent image-strip during the first tick. {@see MessageHistoryBuilder}
     * still strips defensively — this pre-flight gives the caller a useful error.
     *
     * @param list<string> $mediaIds
     * @throws MediaCapabilityMismatchException
     */
    public function ensureMediaCapabilityCompatible(int $agentId, array $mediaIds): void
    {
        if ($mediaIds === [] || $this->driverFactory === null) {
            return;
        }
        if (!$this->mediaIdsIncludeImage($mediaIds)) {
            return;
        }
        if (!$this->agentSupportsImages($agentId)) {
            throw new MediaCapabilityMismatchException(
                'One or more attachments are images but the agent\'s LLM does not support image input.',
            );
        }
    }

    /**
     * @param list<string> $mediaIds
     */
    private function mediaIdsIncludeImage(array $mediaIds): bool
    {
        foreach ($mediaIds as $mid) {
            if ($mid === '') {
                continue;
            }
            $asset = MediaAsset::query()->find($mid);
            if ($asset === null) {
                continue;
            }
            if (is_string($asset->mime_type) && str_starts_with(strtolower($asset->mime_type), 'image/')) {
                return true;
            }
        }

        return false;
    }

    private function agentSupportsImages(int $agentId): bool
    {
        $agent = Agent::query()->find($agentId);
        if ($agent === null) {
            return false;
        }
        try {
            $driver = $this->driverFactory->makeFromAgent($agent);
        } catch (Throwable) {
            return false;
        }

        return $driver->supportsImageInput();
    }
}
