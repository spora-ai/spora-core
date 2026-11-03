<?php

declare(strict_types=1);

namespace Spora\Tools;

/**
 * What the media tool answers about the library, written out for the
 * model.
 *
 * `ToolResult::$data` never reaches the model: it is stored in
 * `tool_calls.result_data` for the operator's UI and the audit trail.
 * A read operation that leaves its rows there answers with a count and
 * nothing else — and a model that cannot see the ids has to guess
 * them, which is how an agent ends up filing an asset into a folder it
 * never found.
 *
 * The rows therefore go into the content, one line each, and the
 * structured rows stay in the data for the UI.
 *
 * The lines are what the model acts on, so they carry the handles:
 * the asset id, the folder id, the derivative id. A line without a
 * handle is a count with extra words.
 */
final class MediaToolPresenter
{
    /**
     * The library's assets, one line each.
     *
     * The count is the number of matches, not the number of rows, and
     * the paging hint names the offset that continues it.
     *
     * @param list<array<string, mixed>> $items
     */
    public static function assets(array $items, int $total, int $offset = 0): string
    {
        $lines = [sprintf('Found %d media asset(s).', $total)];

        foreach ($items as $item) {
            $lines[] = self::assetLine($item);
        }

        $hint = self::moreHint(count($items), $total, $offset);
        if ($hint !== '') {
            $lines[] = $hint;
        }

        return implode("\n", $lines);
    }

    /**
     * The principal's folders, one line each.
     *
     * The id is what the folder ops take; the path is what the operator
     * reads. Both are on the line, because the agent files assets by id
     * and talks about them by path.
     *
     * @param list<array<string, mixed>> $folders
     */
    public static function folders(array $folders): string
    {
        $lines = [sprintf('Found %d media folder(s).', count($folders))];

        foreach ($folders as $folder) {
            $lines[] = sprintf(
                '- %s %s — %s, %d asset(s)',
                (string) ($folder['id'] ?? ''),
                (string) ($folder['name'] ?? '(unnamed)'),
                (string) ($folder['path'] ?? ''),
                (int) ($folder['asset_count'] ?? 0),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * The assets filed in one folder, one line each.
     *
     * @param list<array<string, mixed>> $items
     */
    public static function folderAssets(array $items, int $total, string $folderId): string
    {
        $lines = [sprintf('Found %d asset(s) in folder %s.', $total, $folderId)];

        foreach ($items as $item) {
            $lines[] = self::assetLine($item);
        }

        return implode("\n", $lines);
    }

    /**
     * A parent's derivative rows, one line each.
     *
     * `format` is the producer's slug, not a mime: `thumbnail-256` is a
     * preset that emits WebP. The mime is on the line beside it so the
     * model reports what came back rather than what it asked for.
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function derivatives(array $rows, string $parentId, string $formatFilter = ''): string
    {
        $suffix = $formatFilter !== '' ? sprintf(' matching format "%s"', $formatFilter) : '';
        $lines = [sprintf('Found %d derivative(s) of %s%s.', count($rows), $parentId, $suffix)];

        foreach ($rows as $row) {
            $lines[] = self::derivativeLine($row);
        }

        return implode("\n", $lines);
    }

    /**
     * The derivative rows of the asset `get_media` just described.
     *
     * Empty when the asset has none: an empty section would only tell
     * the model that there is nothing to say.
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function assetDerivatives(array $rows): string
    {
        if ($rows === []) {
            return '';
        }

        $lines = ['Derivatives of this asset:'];

        foreach ($rows as $row) {
            $lines[] = self::derivativeLine($row);
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $item
     */
    private static function assetLine(array $item): string
    {
        return sprintf(
            '- %s %s — %s, %s bytes',
            (string) ($item['id'] ?? ''),
            (string) ($item['filename'] ?? '(unnamed)'),
            (string) ($item['mime_type'] ?? 'unknown type'),
            (string) ($item['byte_size'] ?? 0),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function derivativeLine(array $row): string
    {
        return sprintf(
            '- %s %s — %s, %s',
            (string) ($row['media_id'] ?? ''),
            (string) ($row['format'] ?? ''),
            (string) ($row['mime_type'] ?? 'unknown type'),
            (string) ($row['asset_url'] ?? ''),
        );
    }

    /**
     * Where the next page starts, when there is one.
     *
     * The count line says how many rows match, the rows say how many are
     * here — without the difference the model reads "Found 102" as "102
     * rows follow" and pages from the end of the list it was given.
     */
    private static function moreHint(int $shown, int $total, int $offset): string
    {
        if ($shown === 0 || $shown + $offset >= $total) {
            return '';
        }

        return sprintf(
            'Showing %d of %d — call `search` again with offset: %d for the next %d.',
            $shown,
            $total,
            $offset + $shown,
            $shown,
        );
    }
}
