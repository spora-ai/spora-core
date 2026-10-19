<?php

declare(strict_types=1);

namespace Spora\Services\AgentApi;

use Spora\Services\MediaArchive\MediaAllowedTypesService;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaIngestRequest;
use Spora\Services\MediaArchive\MimeSniffer;

/**
 * Turns an external caller's file — a multipart part or an inline data URL
 * — into a media asset owned by the key's agent.
 *
 * Both paths converge here so they cannot diverge on the checks that
 * matter: the MIME is sniffed from the bytes rather than taken from the
 * client's header or the data URL's declared type, and the agent's own
 * MIME allowlist is consulted before anything is persisted.
 */
final class AgentApiFileIngest
{
    /**
     * Inline files ride in the JSON body, so they are held to the data-URL
     * ceiling rather than whatever `post_max_size` allows. Multipart
     * uploads go through the asset store's own limit instead.
     */
    public const MAX_INLINE_BYTES = 16 * 1024 * 1024;

    private const MAX_INLINE_FILES = 8;

    public function __construct(
        private readonly MediaArchiveService $mediaArchive,
        private readonly MediaAllowedTypesService $allowed,
        private readonly MimeSniffer $sniffer,
    ) {}

    /**
     * Ingest bytes as the context's agent, or null when the sniffed type is
     * not in the agent's allowlist.
     *
     * The declared MIME never wins: bytes are sniffed, matching the upload
     * controller's rule, so a caller cannot label a payload as something
     * the agent does not accept.
     */
    public function ingestBytes(AgentApiContext $context, string $bytes, string $filename): ?string
    {
        if ($bytes === '') {
            return null;
        }

        $mime = $this->sniffer->sniffFromBytes($bytes, $filename);

        if (!$this->allowed->isAllowed($mime, $context->agentId())) {
            return null;
        }

        $asset = $this->mediaArchive->ingest(new MediaIngestRequest(
            bytes: $bytes,
            mime: $mime,
            filename: $filename,
            userId: $context->ownerUserId,
            principalId: (int) $context->agent->principal_id,
            agentId: $context->agentId(),
            uploadSource: 'agent-api',
            isTemporary: false,
        ));

        return (string) $asset->id;
    }

    /**
     * @param list<mixed> $files the raw `files` array from the request body
     * @return list<string> media ids, in the order given
     */
    public function ingestInlineFiles(AgentApiContext $context, array $files): array
    {
        $ids = [];
        $seen = 0;

        foreach ($files as $entry) {
            if ($seen >= self::MAX_INLINE_FILES) {
                break;
            }

            $decoded = $this->decodeInlineEntry($entry);

            if ($decoded === null) {
                continue;
            }

            $seen++;

            $id = $this->ingestBytes(
                $context,
                $decoded['bytes'],
                $decoded['filename'] ?? 'inline-' . $seen,
            );

            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * One entry of the `files` array, or null when it carries nothing that
     * decodes. The filename is left null when the caller did not name it, so
     * the caller can number it from how many entries it has actually taken —
     * a batch that skips its first file names two files `inline-1`.
     *
     * @return array{bytes: string, filename?: string}|null
     */
    private function decodeInlineEntry(mixed $entry): ?array
    {
        if (!is_array($entry)) {
            return null;
        }

        $source = $entry['data_url'] ?? $entry['base64'] ?? null;

        if (!is_string($source) || $source === '') {
            return null;
        }

        $decoded = str_starts_with($source, 'data:')
            ? $this->decodeDataUrl($source)
            : $this->decodeBase64($source);

        return $decoded === null || $decoded === ''
            ? null
            : ['bytes' => $decoded, 'filename' => $this->filenameOf($entry)];
    }

    private function filenameOf(array $entry): ?string
    {
        $filename = $entry['filename'] ?? null;

        return is_string($filename) && $filename !== '' ? $filename : null;
    }

    private function decodeDataUrl(string $dataUrl): ?string
    {
        if (!str_starts_with($dataUrl, 'data:')) {
            return null;
        }

        $comma = strpos($dataUrl, ',');

        if ($comma === false) {
            return null;
        }

        $payload = $this->payloadOfDataUrl($dataUrl, $comma);

        return $payload === null ? null : $this->acceptBytes($payload);
    }

    /**
     * The base64 body of a `data:…;base64,` URL.
     *
     * `false` is what `base64_decode(…, true)` returns for a malformed
     * payload, and {@see self::acceptBytes()} is what rejects it — so the
     * false is passed through rather than folded into the null.
     *
     * @return string|false|null
     */
    private function payloadOfDataUrl(string $dataUrl, int $comma): string|false|null
    {
        if (!str_contains(substr($dataUrl, 5, $comma - 5), ';base64')) {
            return null;
        }

        return base64_decode(substr($dataUrl, $comma + 1), true);
    }

    private function decodeBase64(string $raw): ?string
    {
        return $this->acceptBytes(base64_decode($raw, true));
    }

    private function acceptBytes(string|false $bytes): ?string
    {
        if ($bytes === false || $bytes === '') {
            return null;
        }

        return strlen($bytes) > self::MAX_INLINE_BYTES ? null : $bytes;
    }
}
