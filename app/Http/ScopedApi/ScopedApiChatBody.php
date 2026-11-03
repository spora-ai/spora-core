<?php

declare(strict_types=1);

namespace Spora\Http\ScopedApi;

use Spora\Services\ApiKeys\ApiKeyMediaAccess;

/**
 * The body of a scoped chat request, read field by field.
 *
 * The body arrives as a bag of untyped values, and each field has exactly
 * one reading: absent, blank and non-numeric all mean "not supplied", the
 * message may be spelled `text` or `prompt`, and a media id is forwarded
 * only when the target agent may read it. Deciding that in one place is
 * what keeps the start path and the continue path from drifting apart.
 *
 * Split out of {@see ScopedApiChatController} to keep that class under the
 * S1448 20-method ceiling.
 */
final class ScopedApiChatBody
{
    /** Ceiling on `media_ids`, each of which costs a lookup to authorise. */
    public const MAX_MEDIA_IDS = 32;

    public function __construct(
        private readonly ApiKeyMediaAccess $media,
    ) {}

    /**
     * The message the caller sent, or null when there is none.
     *
     * @param array<string, mixed> $body
     */
    public function text(array $body): ?string
    {
        $text = trim($this->string($body, 'text') ?? $this->string($body, 'prompt') ?? '');

        return $text === '' ? null : $text;
    }

    /**
     * A trimmed string field, or null when it is absent or blank.
     *
     * @param array<string, mixed> $body
     */
    public function string(array $body, string $name): ?string
    {
        $value = $body[$name] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * An integer field, or null when it is absent or not numeric.
     *
     * @param array<string, mixed> $body
     */
    public function int(array $body, string $name): ?int
    {
        $value = $body[$name] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Whether the request carries more media ids than the ceiling allows.
     *
     * Each id is a lookup, so an uncapped list is an uncapped number of
     * queries. Rejected rather than truncated, because a caller that
     * silently lost attachments has no way to tell that from one that sent
     * none.
     *
     * @param array<string, mixed> $body
     */
    public function tooManyMediaIds(array $body): bool
    {
        $raw = $body['media_ids'] ?? null;

        return is_array($raw) && count($raw) > self::MAX_MEDIA_IDS;
    }

    /**
     * Only ids the target agent can read are forwarded, so a caller cannot
     * attach an asset id it observed somewhere else.
     *
     * @param array<string, mixed> $body
     * @return list<string>
     */
    public function readableMediaIds(array $body, int $agentId): array
    {
        $raw = $body['media_ids'] ?? null;

        if (!is_array($raw)) {
            return [];
        }

        $ids = [];

        foreach ($raw as $id) {
            if (is_string($id) && $id !== '' && $this->media->isMediaReadableForAgent($agentId, $id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
