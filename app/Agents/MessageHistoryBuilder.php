<?php

declare(strict_types=1);

namespace Spora\Agents;

use Spora\Agents\ValueObjects\ActiveCompactions;
use Spora\Drivers\LLMDriverInterface;
use Spora\Models\MediaAsset;
use Spora\Models\MediaDerivative;
use Spora\Models\TaskHistory;
use Spora\Services\MediaArchive\ImageRequestBudget;
use Spora\Services\MediaArchive\MediaDerivativeService;
use Spora\Services\MediaArchive\MediaImageBlocks;

/**
 * Replays {@see TaskHistory} rows into the OpenAI-compatible message list sent
 * to the LLM each tick. Rows with `role=attachment` are never sent as such
 * (providers reject the role) — they fold into the adjacent `user` row,
 * with the operator's typed prompt preserved as a leading text block.
 *
 * A `role=tool` row that carries image attachments contributes two messages:
 * the tool result, and a sibling `user` row with the images. A provider's
 * tool result takes text only, so the image cannot live inside it.
 *
 * Pairing is order-symmetric: {@see consumeAttachmentPair()} handles the
 * reverse-order case and {@see consumeUserAttachmentPair()} the production
 * case. A pair is one turn, but a summary's range is expressed in raw row
 * sequences, so it can cover one half of a pair and not the other — both
 * halves are checked, see {@see turnMessage()}.
 *
 * A compaction replaces the rows it covers with a summary. The rows
 * themselves stay in `task_history` — the operator can still read them — so
 * what the provider receives is decided here: the rows a summary covers are
 * dropped, and the summary is sent in their place.
 *
 * Assistant rows with stored `content_blocks` (Anthropic thinking, redacted
 * thinking, images) are rendered through the block list so the provider
 * sees the original signed payload on the next outbound turn.
 *
 * The result passes through {@see ToolCallPairingReconciler}, which repairs
 * tool batches left unpaired by an interrupted tick.
 *
 * The internal `content` shape is `['type'=>'text'|'image', 'text'|'mediaType'|'base64', …]`;
 * the per-provider wire shape is built by the matching `LLMDriverInterface`
 * implementation (OpenAI, Anthropic, …).
 */
final class MessageHistoryBuilder
{
    /**
     * The notes appended to a message whose image attachment no longer
     * resolves.
     *
     * A tool result that says "you can see it now" outlives the asset it
     * points at: the operator can delete that asset from the Media Archive
     * at any time, and the row — which is what the provider sees on the
     * next tick — still claims the image is there. The image block is
     * dropped silently in that case, so without this note the model reads
     * a claim the payload no longer supports.
     *
     * Two reasons, and they are not the same fact. The **row** can be
     * gone — the asset was removed from the archive. Or the row can be
     * there and the **bytes** can be missing: the file was swept by the
     * asset GC, or it cannot be read. The first says the operator
     * deleted it; the second does not, and claiming so would be a lie.
     *
     * A third case is not a removal, but the model still has to hear
     * about it: an image the **request had no room for** — the
     * provider's ceiling on the encoded bytes or on the number of
     * images was already spent — was never sent, and the message that
     * named it is still in the request.
     *
     * A fourth case produces no note at all: an image that was **dropped
     * for size** — over the per-asset cap or out of the row's budget —
     * is documented behaviour, and the tool result's text still reaches
     * the model.
     *
     * One sentence per reason: several missing assets are one fact, not
     * several, so the plural variant names the count instead of repeating
     * the sentence.
     */
    private const REMOVED_ASSET_NOTE = 'Note: the image referenced by this message was removed from the Media Archive '
        . 'and is no longer visible in this conversation.';

    private const REMOVED_ASSETS_NOTE = 'Note: the %d images referenced by this message were removed from the Media '
        . 'Archive and are no longer visible in this conversation.';

    private const UNREADABLE_ASSET_NOTE = 'Note: the image referenced by this message can no longer be read, so it is '
        . 'no longer visible in this conversation.';

    private const UNREADABLE_ASSETS_NOTE = 'Note: the %d images referenced by this message can no longer be read, so '
        . 'they are no longer visible in this conversation.';

    private const DROPPED_ASSET_NOTE = 'Note: the image referenced by this message did not fit in the request and was '
        . 'not sent, so it is not visible in this conversation.';

    private const DROPPED_ASSETS_NOTE = 'Note: the %d images referenced by this message did not fit in the request and '
        . 'were not sent, so they are not visible in this conversation.';

    /**
     * The image bytes one request may carry.
     *
     * Shared by the two paths that put an image in front of the model, so
     * the total of a request is the same whichever path an image came
     * from.
     */
    private readonly ImageRequestBudget $imageBudget;

    public function __construct(
        private readonly ?LLMDriverInterface $driver = null,
        private readonly int $maxRowImageBytes = MediaImageBlocks::MAX_ROW_BYTES,
    ) {
        $this->imageBudget = MediaImageBlocks::requestBudgetFor($driver);
    }

    /**
     * @return list<array<string, mixed>>  OpenAI-compatible messages, in `sequence` order.
     */
    public function build(int $taskId): array
    {
        $this->imageBudget->reset();

        $rows = TaskHistory::where('task_id', $taskId)
            ->orderBy('sequence')
            ->get();

        $messages = $this->applySummaryCompaction($rows);

        // Must run after compaction, which orphans tool results itself.
        $messages = (new ToolCallPairingReconciler())->reconcile($messages);

        return $messages;
    }

    /**
     * Walks the rows in `sequence` order and converts each surviving row into
     * an LLM-shaped message.
     *
     * A summary stands in for the rows it covers: those rows are dropped and
     * the summary is sent in their place, at the position the range starts.
     * A summary whose range a later, wider one covers is redundant and is
     * dropped without being sent — `role: summary` is not a wire role any
     * provider accepts.
     *
     * Attachment rows fold into the adjacent `user` row in either order; what
     * the turn contributes is decided by {@see turnMessage()}.
     *
     * @param  \Illuminate\Support\Collection<int, TaskHistory>  $rows
     * @return list<array<string, mixed>>
     */
    private function applySummaryCompaction(\Illuminate\Support\Collection $rows): array
    {
        $compactions = ActiveCompactions::find($rows);

        $messages  = [];
        $pending   = $compactions->summaries();
        $rowsArray = $rows->values()->all();

        // The images a tool result carries ride on a user turn that follows
        // the whole run of tool results. A provider accepts a tool result
        // only directly after the assistant turn that asked for it, so a
        // user turn between two results of the same batch is a 400.
        $toolImages = [];

        $i = 0;
        while ($i < count($rowsArray)) {
            $row = $rowsArray[$i];

            if ($row->role === 'summary') {
                $i++;
                continue;
            }

            $pair     = $this->consumeAdjacentPair($row, $rowsArray, $i);
            $firstSeq = $pair['first'] ?? (int) $row->sequence;

            $this->flushSummariesBefore($messages, $pending, $firstSeq);

            $turn = $this->turnFor($row, $pair, $compactions);

            if ($row->role === 'tool') {
                // The tool result goes out now; the image turn waits for the
                // run of tool results to end.
                $messages   = array_merge($messages, $turn['messages']);
                $toolImages = array_merge($toolImages, $turn['images']);
            } else {
                $messages   = array_merge($messages, $toolImages, $turn['messages']);
                $toolImages = [];
            }

            $i = $pair['nextIndex'] ?? ($i + 1);
        }

        // A summary whose range starts after every row still belongs in the
        // conversation — dropping it would lose what it replaced.
        foreach ($pending as $summary) {
            $messages[] = $this->summaryMessage($summary['row']);
        }

        foreach ($toolImages as $imageTurn) {
            $messages[] = $imageTurn;
        }

        return $messages;
    }

    /**
     * The summaries that start before `$firstSeq` stand in for the rows they
     * cover, so they are sent at the position their range starts — not at
     * their own row's position.
     *
     * @param  list<array<string, mixed>>              $messages
     * @param  list<array{row: TaskHistory, first: int, last: int}>  $pending
     */
    private function flushSummariesBefore(array &$messages, array &$pending, int $firstSeq): void
    {
        while ($pending !== [] && $pending[0]['first'] <= $firstSeq) {
            $messages[] = $this->summaryMessage($pending[0]['row']);
            array_shift($pending);
        }
    }

    /**
     * One row's turn: the messages it contributes, and the image turns that
     * have to wait for the run of tool results to end.
     *
     * @param  array{row: TaskHistory, nextIndex: int, first: int, last: int, halves: list<TaskHistory>}|null  $pair
     * @return array{messages: list<array<string, mixed>>, images: list<array<string, mixed>>}
     */
    private function turnFor(TaskHistory $row, ?array $pair, ActiveCompactions $compactions): array
    {
        $turnMessages = $this->turnMessage($row, $pair, $compactions);

        if ($row->role !== 'tool') {
            return ['messages' => $turnMessages, 'images' => []];
        }

        // The tool result goes out now; the image turn waits for the run of
        // tool results to end.
        $toolResult = array_shift($turnMessages);

        return [
            'messages' => $toolResult === null ? [] : [$toolResult],
            'images'   => $turnMessages,
        ];
    }

    /**
     * What one turn contributes to the message list — an empty list when a
     * summary already stands in for it.
     *
     * A summary's range is expressed in raw row sequences, so it can cover
     * one half of a pair and not the other — the halves are checked
     * separately and the uncovered one is sent on its own.
     *
     * @param  array{row: TaskHistory, nextIndex: int, first: int, last: int, halves: list<TaskHistory>}|null  $pair
     * @return list<array<string, mixed>>
     */
    private function turnMessage(TaskHistory $row, ?array $pair, ActiveCompactions $compactions): array
    {
        $coversFirst = $compactions->covers($pair['first'] ?? (int) $row->sequence);
        $coversLast  = $compactions->covers($pair['last'] ?? (int) $row->sequence);

        if ($pair === null) {
            return $coversFirst ? [] : $this->messageFromHistoryRow($row);
        }

        // Which half the summary covers decides what is left to send: an
        // uncovered half goes on its own, and a fully covered pair is
        // dropped — the summary stands in for it.
        if (!$coversFirst && !$coversLast) {
            $message = $this->messageFromHistoryRow($pair['row']);
        } elseif (!$coversFirst) {
            // Only the second half is covered: send the first half alone —
            // dropping the merged row would lose it.
            $message = $this->messageFromHistoryRow($pair['halves'][0]);
        } elseif (!$coversLast) {
            // Only the first half is covered: send the second half alone,
            // not the merged row — that would repeat what the summary has.
            $message = $this->messageFromHistoryRow($pair['halves'][1]);
        } else {
            $message = [];
        }

        return $message;
    }

    /**
     * @param list<TaskHistory> $rowsArray
     * @return array{row: TaskHistory, nextIndex: int, first: int, last: int, halves: list<TaskHistory>}|null
     */
    private function consumeAdjacentPair(TaskHistory $row, array $rowsArray, int $i): ?array
    {
        if ($row->role === 'attachment') {
            return $this->consumeAttachmentPair($rowsArray, $i);
        }
        if ($row->role === 'user') {
            return $this->consumeUserAttachmentPair($rowsArray, $i);
        }
        return null;
    }

    /**
     * Reverse-order merge: row at `$i` is `attachment`, next is `user`.
     * The companion production-order helper is {@see consumeUserAttachmentPair()}.
     *
     * @param list<TaskHistory> $rowsArray
     * @return array{row: TaskHistory, nextIndex: int, first: int, last: int, halves: list<TaskHistory>}|null
     */
    private function consumeAttachmentPair(array $rowsArray, int $i): ?array
    {
        $next = $rowsArray[$i + 1] ?? null;
        if ($next === null || $next->role !== 'user') {
            return null;
        }
        $merged = clone $rowsArray[$i];
        $merged->content   = $next->content;
        $merged->sequence  = $next->sequence;
        return [
            'row'       => $merged,
            'nextIndex' => $i + 2,
            'first'     => (int) $rowsArray[$i]->sequence,
            'last'      => (int) $next->sequence,
            'halves'    => [$rowsArray[$i], $next],
        ];
    }

    /**
     * Production-order merge: row at `$i` is `user`, next is `attachment`.
     * The synthetic row reuses {@see attachmentMessage()} expansion; its
     * `sequence` is the later of the pair, so the pair reads as one row
     * wherever a single sequence is enough; the range check needs both.
     * Companion: {@see consumeAttachmentPair()}.
     *
     * @param list<TaskHistory> $rowsArray
     * @return array{row: TaskHistory, nextIndex: int, first: int, last: int, halves: list<TaskHistory>}|null
     */
    private function consumeUserAttachmentPair(array $rowsArray, int $i): ?array
    {
        $next = $rowsArray[$i + 1] ?? null;
        if ($next === null || $next->role !== 'attachment') {
            return null;
        }
        $merged = clone $next;
        $merged->content  = $rowsArray[$i]->content;
        $merged->sequence = $next->sequence;
        return [
            'row'       => $merged,
            'nextIndex' => $i + 2,
            'first'     => (int) $rowsArray[$i]->sequence,
            'last'      => (int) $next->sequence,
            'halves'    => [$rowsArray[$i], $next],
        ];
    }
    /**
     * `summary` is internal bookkeeping that every provider rejects on the
     * wire, so it is sent as a plain user message.
     *
     * @return array{role: string, content: string|null}
     */
    private function summaryMessage(TaskHistory $row): array
    {
        return [
            'role'    => 'user',
            'content' => $row->content,
        ];
    }

    /**
     * The messages one row contributes.
     *
     * `attachment` has no valid wire role, so it must never fall through to a
     * literal `{role: 'attachment'}` — every provider rejects that with a 400.
     *
     * A tool row that carries an image attachment contributes two: the tool
     * result, and the user turn that carries the image. The image turn is
     * always the second entry, so {@see applySummaryCompaction()} can hold
     * it back until the run of tool results ends.
     *
     * @return list<array<string, mixed>>
     */
    private function messageFromHistoryRow(TaskHistory $row): array
    {
        if ($row->role === 'attachment') {
            return [$this->attachmentMessage($row)];
        }

        $content = $row->content;
        if ($row->role === 'assistant' && is_array($row->content_blocks) && $row->content_blocks !== []) {
            $content = $row->content_blocks;
        }

        $message = [
            'role' => $row->role,
            'content' => $content,
        ];

        if ($row->role === 'tool') {
            $resolved = $this->toolImageAssets($row);
            $turn     = $this->toolImageTurn($resolved['assets']);

            return [
                [
                    'role' => 'tool',
                    'tool_call_id' => $row->tool_call_id,
                    'name' => $row->tool_name,
                    'content' => $this->withMissingAssetNote(
                        $row->content,
                        $resolved['removed'],
                        $turn['unreadable'],
                        $turn['dropped'],
                    ),
                ],
                ...$turn['messages'],
            ];
        }

        if ($row->role === 'assistant' && $row->tool_call_payload !== null) {
            $message = [
                'role'    => 'assistant',
                'content' => $content,
            ];

            // Omitted rather than empty: no provider accepts `tool_calls: []`.
            $calls = $this->decodeToolCallPayload($row->tool_call_payload);
            if ($calls !== []) {
                $message['tool_calls'] = $calls;
            }
        }

        return [$message];
    }

    /**
     * The user turn that carries the images a tool result points at.
     *
     * A provider's tool result carries text only, so the image rides on a
     * sibling user turn. The identity text block leads it: the model gets
     * the asset_id back, so the turn is never image-only and the asset can
     * be chained onward.
     *
     * A driver that reports it cannot take images drops the block, the same
     * way the attachment path does — a provider that cannot read an image
     * block rejects the request. A missing driver is not a refusal: the tool
     * that produced the attachment already checked the agent's LLM.
     *
     * The row's image bytes are capped as a whole
     * ({@see MediaImageBlocks::MAX_ROW_BYTES}), the same budget the
     * attachment path spends: the per-asset cap bounds one image, not the
     * row. The per-asset cap is the endpoint's own ceiling on the image it
     * receives ({@see MediaImageBlocks::maxImageBytesFor()}), so an image
     * the provider would answer with a 400 is dropped here rather than
     * sent on every rebuilt history. Every image in the request shares one
     * budget ({@see ImageRequestBudget}), so the request cannot add up to
     * more than the provider accepts. An image that does not fit is
     * dropped — the tool result text still reaches the model, and the note
     * says the image was left out, because the tool result says it was
     * attached.
     *
     * An image whose **bytes are not on hand** is a different thing: the
     * row is there, the file is not, and the message that claimed the
     * image has to be corrected. The count comes back with the turn so
     * the caller can write that note.
     *
     * @param  list<MediaAsset>  $assets  The declared images that still resolve.
     * @return array{messages: list<array<string, mixed>>, unreadable: int, dropped: int}
     */
    private function toolImageTurn(array $assets): array
    {
        // A driver that cannot read images gets none: the images are
        // dropped and nothing is said about them. The capability is only
        // asked for when there is an image to send.
        if ($assets === [] || ($this->driver !== null && !$this->driver->supportsImageInput())) {
            return ['messages' => [], 'unreadable' => 0, 'dropped' => 0];
        }

        $identities    = [];
        $images        = [];
        $imageBytes    = 0;
        $unreadable    = 0;
        $dropped       = 0;
        $maxImageBytes = $this->maxImageBytes();

        foreach ($assets as $asset) {
            $rendered = MediaImageBlocks::renderForRow(
                $asset,
                $this->basePath(),
                $imageBytes,
                $this->maxRowImageBytes,
                $maxImageBytes,
            );
            if ($rendered === null) {
                // Dropped for size, or the bytes are gone. Only the second
                // one is a removal the model has to hear about.
                if (MediaImageBlocks::bytesWentMissing($asset, $this->basePath())) {
                    $unreadable++;
                }
                continue;
            }

            // The request's share of the provider's ceiling. The image is
            // rendered and then left out, so the model has to hear about it:
            // the tool result says the image is attached.
            if (!$this->imageBudget->accepts($rendered['encodedBytes'])) {
                $dropped++;
                continue;
            }

            $identities[] = AttachmentRowRenderer::attachmentMetadataBlock($asset);
            $images[]     = $rendered['block'];
            $imageBytes  += $rendered['bytes'];
        }

        if ($images === []) {
            return ['messages' => [], 'unreadable' => $unreadable, 'dropped' => $dropped];
        }

        return [
            'messages' => [
                [
                    'role'    => 'user',
                    'content' => array_merge($identities, $images),
                ],
            ],
            'unreadable' => $unreadable,
            'dropped'    => $dropped,
        ];
    }

    /**
     * The assets a tool row's image attachments name, and how many of the
     * declared ones no longer exist.
     *
     * Resolved once per row: the builder runs on every tick, and the tool
     * result and the image turn need the same answer.
     *
     * @return array{assets: list<MediaAsset>, removed: int}
     */
    private function toolImageAssets(TaskHistory $row): array
    {
        if (!is_array($row->attachments)) {
            return ['assets' => [], 'removed' => 0];
        }

        $assets  = [];
        $removed = 0;

        foreach ($row->attachments as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }
            if (($attachment['kind'] ?? 'text') !== 'image') {
                continue;
            }
            $mediaId = $attachment['media_id'] ?? null;
            if (!is_string($mediaId) || $mediaId === '') {
                continue;
            }
            $asset = MediaAsset::query()->find($mediaId);
            if ($asset instanceof MediaAsset) {
                $assets[] = $asset;
                continue;
            }
            $removed++;
        }

        return ['assets' => $assets, 'removed' => $removed];
    }

    /**
     * Expand an `attachment` row into a `user` message whose `content` is
     * either a list of ContentBlock dicts (metadata, text, and image blocks)
     * or a plain string when no blocks can be produced. Metadata blocks expose
     * the asset identity; image blocks require the driver to support image
     * input. The controller is expected to have rejected vision-incompatible
     * requests with `400 MEDIA_CAPABILITY_MISMATCH` before we get here.
     */
    private function attachmentMessage(TaskHistory $row): array
    {
        $renderer = new AttachmentRowRenderer(
            $this->driver,
            $this->basePath(),
            $this->maxRowImageBytes,
            $this->imageBudget,
        );

        $rendered = $renderer->renderRow($row);
        $content  = $rendered['content'] ?? $renderer->fallbackText($row->content);

        return [
            'role'    => 'user',
            'content' => $this->withMissingAssetNote(
                $content,
                $rendered['missingImages'],
                $rendered['unreadableImages'],
                $rendered['droppedImages'],
            ),
        ];
    }

    /**
     * Tell the model that an image it was shown is gone.
     *
     * The note rides on the message that made the claim, not as a message of
     * its own: the message count and the order are the provider's contract,
     * and an extra turn would reorder a tool batch. The operator's own text
     * is left exactly as it was — the note is additional.
     *
     * The reasons get their own sentence, because they are different
     * facts: an asset that was removed from the archive is not an asset
     * whose bytes cannot be read, and an image that did not fit the
     * request was never sent. A row can carry more than one of them, so
     * the notes are appended in that case rather than merged into one
     * claim.
     *
     * @param  list<array<string, mixed>>|string|null  $content
     * @return list<array<string, mixed>>|string|null
     */
    private function withMissingAssetNote(
        array|string|null $content,
        int $removed,
        int $unreadable,
        int $dropped = 0,
    ): array|string|null {
        $notes = [];

        if ($removed > 0) {
            $notes[] = $removed === 1
                ? self::REMOVED_ASSET_NOTE
                : sprintf(self::REMOVED_ASSETS_NOTE, $removed);
        }

        if ($unreadable > 0) {
            $notes[] = $unreadable === 1
                ? self::UNREADABLE_ASSET_NOTE
                : sprintf(self::UNREADABLE_ASSETS_NOTE, $unreadable);
        }

        if ($dropped > 0) {
            $notes[] = $dropped === 1
                ? self::DROPPED_ASSET_NOTE
                : sprintf(self::DROPPED_ASSETS_NOTE, $dropped);
        }

        if ($notes === []) {
            return $content;
        }

        if (is_array($content)) {
            $blocks = $content;
            foreach ($notes as $note) {
                $blocks[] = ['type' => 'text', 'text' => $note];
            }

            return $blocks;
        }

        $base = (string) $content;

        return $base === '' ? implode("\n\n", $notes) : $base . "\n\n" . implode("\n\n", $notes);
    }

    /**
     * Where the archive's asset files live.
     */
    private function basePath(): string
    {
        return defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
    }

    /**
     * The largest image the endpoint takes, in raw bytes.
     *
     * The provider caps the image it receives **encoded**, and base64
     * spends a third more than the file does, so the file that fits is
     * smaller than the archive's own per-asset cap on every provider that
     * states a limit.
     */
    private function maxImageBytes(): int
    {
        return MediaImageBlocks::maxImageBytesFor($this->driver);
    }

    /**
     * Empty `arguments` become the literal `'{}'`: strict providers reject `[]`.
     * Non-array entries are dropped rather than forwarded as `tool_calls`.
     *
     * @return list<array<string, mixed>>
     */
    private function decodeToolCallPayload(string $payload): array
    {
        $decoded = json_decode($payload, true);
        if (! is_array($decoded)) {
            return [];
        }

        $calls = [];
        foreach ($decoded as $tc) {
            if (! is_array($tc)) {
                continue;
            }

            if (isset($tc['function']['arguments'])) {
                $args        = $tc['function']['arguments'];
                $decodedArgs = is_string($args) ? (json_decode($args, true) ?? []) : (array) $args;
                if ($decodedArgs === []) {
                    $tc['function']['arguments'] = '{}';
                }
            }

            $calls[] = $tc;
        }

        return $calls;
    }
}

/**
 * Renders a {@see TaskHistory} `attachment` row into the internal
 * `content` array for the merged `user` message. Internal to
 * {@see MessageHistoryBuilder} — instantiated by {@see MessageHistoryBuilder::attachmentMessage()}.
 *
 * The identity block it builds is shared with the tool-result image path:
 * both put the asset_id in front of the model.
 */
final class AttachmentRowRenderer
{
    /**
     * Hard cap on inline text bytes, applied to BOTH inline branches:
     * the `md` derivative and the raw source text.
     *
     * 512 KB ≈ 131k tokens — the largest value that still fits a 200k
     * context window with room for the system prompt and history. Text
     * sources therefore get *looser* than the old 256 KB cap, which
     * spares the common `get_source` round-trip.
     *
     * **What this bounds is one attachment; what bounds the row is
     * {@see MAX_INLINE_ROW_TEXT_BYTES}.** The two are the same number
     * today, and the arithmetic above is a statement about a *row*, so it
     * only held while one attachment could consume the whole of it. It did
     * not: this cap was applied per asset inside a loop over every
     * attachment with no count limit and no running total, so four 400 KB
     * sources inlined 1.6 MB and the context-window claim stopped being
     * true at the second attachment.
     *
     * The derivative branch had no cap at all before this change, which
     * meant a 200-page PDF's full markdown was inlined uncapped — the
     * latent context bug this constant now bounds. Past the cap the
     * caller falls through to the pointer branch, which names
     * `get_source` so the LLM knows the content exists and where to
     * read it. See {@see self::buildTextBlock()}.
     */
    private const MAX_INLINE_TEXT_BYTES = 512 * 1024;

    /**
     * The same 512 KB, spent across every attachment in a single row rather
     * than re-spent per attachment.
     *
     * Deliberately equal to {@see MAX_INLINE_TEXT_BYTES} so the pair cannot
     * drift into a second, larger reading: one attachment may fill the whole
     * row budget, and the next one finds nothing left. Kept as its own
     * constant rather than a reuse of the other because they are different
     * claims — "no single asset is this big" versus "no row inlines more
     * than this" — and only the second is the one the context-window
     * arithmetic above describes.
     *
     * Image blocks are not counted: they are separately capped per asset
     * ({@see MediaImageBlocks::maxImageBytesFor()}) and cost far fewer
     * tokens per byte.
     */
    private const MAX_INLINE_ROW_TEXT_BYTES = 512 * 1024;

    /**
     * Leading bytes we sample to confirm the asset really is text
     * before inlining it. A NUL byte anywhere in the sample flags the
     * file as binary so a mislabeled mime cannot poison the LLM context.
     */
    private const TEXT_INSPECTION_BYTES = 4096;

    /**
     * Mime prefixes/types we treat as text-safe for the no-converter
     * inline fallback. `text/*` covers everything in the text family;
     * the application/* set lists common text-based formats that do not
     * start with `text/` (JSON, XML, YAML, Typst source, code, etc.).
     * Binaries (PDF, image/*, audio/*, video/*, zip) are intentionally
     * absent — base64-encoding them is not useful to the LLM.
     */
    private const TEXT_MIME_PREFIX = 'text/';

    private const KNOWN_TEXT_APPLICATION_MIMES = [
        'application/json',
        'application/ld+json',
        'application/xml',
        'application/javascript',
        'application/x-javascript',
        'application/x-yaml',
        'application/yaml',
        'application/x-typst',
        'application/typst',
        'application/x-shellscript',
        'application/x-sh',
        'application/x-perl',
        'application/x-python',
        'application/x-ruby',
        'application/x-httpd-php',
        'application/sql',
        'application/graphql',
    ];

    public function __construct(
        private readonly ?LLMDriverInterface $driver,
        private readonly string $basePath,
        private readonly int $maxRowImageBytes = MediaImageBlocks::MAX_ROW_BYTES,
        private readonly ?ImageRequestBudget $imageBudget = null,
    ) {}

    /**
     * The rendered blocks, and how many of the row's declared image
     * attachments no longer resolve.
     *
     * The content is null when no resolvable blocks exist (legacy rows,
     * all-refs-missing, or attachments whose assets cannot be resolved);
     * callers fall back to {@see fallbackText()} in that case.
     *
     * The count is what lets the caller tell the model that an image it was
     * shown is gone: the block simply does not appear, and without the count
     * that omission is indistinguishable from an image that was never
     * attached.
     *
     * The two counts are different facts: `missingImages` counts images
     * whose asset is gone from the archive, `unreadableImages` counts
     * images whose asset is still there but whose bytes are not on hand.
     * `droppedImages` counts images the request had no room for: the
     * operator attached them, and they are not in the request.
     *
     * @return array{content: list<array<string, mixed>>|null, missingImages: int, unreadableImages: int, droppedImages: int}
     */
    public function renderRow(TaskHistory $row): array
    {
        $blocks = $this->collectAttachmentBlocks($row);
        $prompt = is_string($row->content) ? trim($row->content) : '';

        $content = $blocks['text'] === [] && $blocks['image'] === [] && $blocks['metadata'] === []
            ? null
            : $this->buildAttachmentContent($blocks, $prompt);

        return [
            'content'           => $content,
            'missingImages'     => $blocks['missingImages'],
            'unreadableImages'  => $blocks['unreadableImages'],
            'droppedImages'     => $blocks['droppedImages'],
        ];
    }

    /**
     * Preserves the operator's typed prompt when no resolvable blocks
     * exist (legacy rows, all-refs-missing, or image-only attachments
     * on a non-vision driver). Returns `'[attachment]'` when `$rowContent`
     * is empty.
     */
    public function fallbackText(?string $rowContent): string
    {
        $content = is_string($rowContent) ? trim($rowContent) : '';
        return $content === '' ? '[attachment]' : $content;
    }

    /**
     * Image blocks for non-vision drivers are dropped here — defense in
     * depth alongside the controller's `MEDIA_CAPABILITY_MISMATCH`
     * pre-flight. Metadata blocks (asset_id + filename + local URL) are
     * collected separately so they stay siblings of the content block
     * they refer to and never get composed into the prompt body.
     *
     * The `md` derivatives are resolved in one batch before the loop
     * ({@see resolveTextDerivatives()}) rather than per attachment.
     *
     * `$remaining` is the row's shared inline-text budget, spent in
     * attachment order: each text block is charged what it actually inlined
     * and the next attachment inherits the remainder. Without it the
     * per-asset cap in {@see buildTextBlock()} would be re-spent once per
     * attachment and a row's inlined total would be cap × attachment count
     * — the multiplication the docblock on
     * {@see AttachmentRowRenderer::MAX_INLINE_TEXT_BYTES} was read as
     * forbidding.
     *
     * The row's image bytes are capped as a whole
     * ({@see MediaImageBlocks::MAX_ROW_BYTES}), because the per-asset cap bounds
     * one image and not the row. Each image is capped at what the endpoint
     * accepts ({@see MediaImageBlocks::maxImageBytesFor()}), and every image
     * of the request shares one budget ({@see ImageRequestBudget}).
     *
     * @return array{text: list<array<string, mixed>>, image: list<array<string, mixed>>, metadata: list<array<string, mixed>>, missingImages: int, unreadableImages: int, droppedImages: int}
     */
    private function collectAttachmentBlocks(TaskHistory $row): array
    {
        if (!is_array($row->attachments)) {
            return ['text' => [], 'image' => [], 'metadata' => [], 'missingImages' => 0, 'unreadableImages' => 0, 'droppedImages' => 0];
        }

        $supportsImages = $this->driver !== null && $this->driver->supportsImageInput();
        $textBlocks     = [];
        $imageBlocks    = [];
        $metadataBlocks = [];
        $missingImages  = 0;
        $unreadable     = 0;
        $dropped        = 0;
        $derivatives    = $this->resolveTextDerivatives($row->attachments);
        $remaining      = self::MAX_INLINE_ROW_TEXT_BYTES;
        $imageBytes     = 0;
        $maxImageBytes  = MediaImageBlocks::maxImageBytesFor($this->driver);

        foreach ($this->uniqueAttachments($row->attachments) as $attachment) {
            $asset = $this->resolveAttachmentAsset($attachment['media_id']);
            if ($asset === null) {
                // Only an image is a loss the operator sees; a text
                // attachment that resolves to nothing is not counted.
                $missingImages += ($attachment['kind'] === 'image') ? 1 : 0;
                continue;
            }

            $metadataBlocks[] = self::attachmentMetadataBlock($asset);

            if ($attachment['kind'] !== 'image') {
                [$block, $inlined] = $this->buildTextBlock($asset, $derivatives[$asset->id] ?? null, $remaining);
                $textBlocks[] = $block;
                $remaining    = max(0, $remaining - $inlined);
                continue;
            }

            $image = $this->imageAttachment($asset, $supportsImages, $imageBytes, $maxImageBytes);

            if ($image['block'] !== null) {
                $imageBlocks[] = $image['block'];
                $imageBytes    += $image['bytes'];
            } elseif ($image['unreadable']) {
                $unreadable++;
            } elseif ($image['dropped']) {
                $dropped++;
            }
        }

        return [
            'text'             => $textBlocks,
            'image'            => $imageBlocks,
            'metadata'         => $metadataBlocks,
            'missingImages'    => $missingImages,
            'unreadableImages' => $unreadable,
            'droppedImages'    => $dropped,
        ];
    }

    /**
     * An image attachment's blocks, and whether the model can still read it.
     *
     * Four reasons leave the image out, one of them a removal the model has
     * to hear about: the driver cannot take images, the image did not fit,
     * the request had no room for it, or the bytes are not on hand.
     *
     * @return array{block: array<string, mixed>|null, bytes: int, unreadable: bool, dropped: bool}
     */
    private function imageAttachment(
        MediaAsset $asset,
        bool $supportsImages,
        int $imageBytes,
        int $maxImageBytes,
    ): array {
        $image = $this->buildImageBlock($asset, $supportsImages, $imageBytes, $maxImageBytes);

        if ($image !== null) {
            // The request's share of the provider's ceiling. The metadata
            // block still names the asset, so the model has to hear that
            // the image itself is not in the request.
            if ($this->imageBudget !== null && !$this->imageBudget->accepts($image['encodedBytes'])) {
                return ['block' => null, 'bytes' => 0, 'unreadable' => false, 'dropped' => true];
            }

            return ['block' => $image['block'], 'bytes' => $image['bytes'], 'unreadable' => false, 'dropped' => false];
        }

        return [
            'block'      => null,
            'bytes'      => 0,
            'unreadable' => $supportsImages && MediaImageBlocks::bytesWentMissing($asset, $this->basePath),
            'dropped'    => false,
        ];
    }

    /**
     * The attachments of a row, each asset once.
     *
     * One asset is one attachment: the blocks are built from the asset,
     * so a repeated id would duplicate them — and an image would be
     * loaded and base64-encoded again for every occurrence. The first
     * reference to an asset decides its kind.
     *
     * @param  array<array-key, mixed> $refs
     * @return list<array{media_id: string, kind: string}>
     */
    private function uniqueAttachments(array $refs): array
    {
        $seen   = [];
        $unique = [];

        foreach ($refs as $ref) {
            if (!is_array($ref)) {
                continue;
            }
            $mediaId = $this->mediaIdOf($ref);
            if ($mediaId === null || isset($seen[$mediaId])) {
                continue;
            }
            $seen[$mediaId] = true;
            $unique[]       = [
                'media_id' => $mediaId,
                'kind'     => (string) ($ref['kind'] ?? 'text'),
            ];
        }

        return $unique;
    }

    /**
     * @param array<string, mixed> $ref
     */
    private function mediaIdOf(array $ref): ?string
    {
        $mediaId = $ref['media_id'] ?? null;

        return is_string($mediaId) && $mediaId !== '' ? $mediaId : null;
    }

    /**
     * The asset an attachment names, or null when the row is gone.
     */
    private function resolveAttachmentAsset(string $mediaId): ?MediaAsset
    {
        return MediaAsset::query()->find($mediaId);
    }

    /**
     * Batch-resolve each attachment's `md` derivative, keyed by parent
     * asset id. Two queries for the whole row rather than a
     * `media_derivatives` lookup plus a `MediaAsset::find()` per
     * attachment — the builder runs on every turn, and this is the only
     * part of the branch-1 path that can be done without touching bytes.
     *
     * A parent with several `md` rows (two producers emitting the same
     * format) resolves to the oldest, so every reader of a given parent
     * sees the same text.
     *
     * @param  array<array-key, mixed> $refs
     * @return array<string, MediaAsset>
     */
    private function resolveTextDerivatives(array $refs): array
    {
        $parentIds = [];
        foreach ($refs as $ref) {
            if (!is_array($ref)) {
                continue;
            }
            $mediaId = $ref['media_id'] ?? null;
            if (is_string($mediaId) && $mediaId !== '') {
                $parentIds[$mediaId] = true;
            }
        }
        if ($parentIds === []) {
            return [];
        }

        $pairs = MediaDerivative::query()
            ->whereIn('parent_id', array_keys($parentIds))
            ->where('format', MediaDerivativeService::MARKDOWN_FORMAT)
            ->orderBy('created_at', 'asc')
            ->get(['parent_id', 'derivative_id']);

        if ($pairs->isEmpty()) {
            return [];
        }

        $rows = MediaAsset::query()
            ->whereIn('id', $pairs->pluck('derivative_id')->unique()->all())
            ->get()
            ->keyBy('id');

        $out = [];
        foreach ($pairs as $pair) {
            $parentId = (string) $pair->parent_id;
            $derivative = $rows->get((string) $pair->derivative_id);
            if ($derivative instanceof MediaAsset && !isset($out[$parentId])) {
                $out[$parentId] = $derivative;
            }
        }
        return $out;
    }

    /**
     * The image block and the bytes it spent on the row's image budget.
     *
     * Returns null when the image isn't forwarded — a non-vision driver,
     * no room in the row's image budget, or a payload over the per-asset
     * cap — so the caller can drop it without branching.
     *
     * @return array{block: array<string, mixed>, bytes: int, encodedBytes: int}|null
     */
    private function buildImageBlock(
        MediaAsset $asset,
        bool $supportsImages,
        int $imageBytes,
        int $maxImageBytes,
    ): ?array {
        if (!$supportsImages) {
            return null;
        }

        return MediaImageBlocks::renderForRow(
            $asset,
            $this->basePath,
            $imageBytes,
            $this->maxRowImageBytes,
            $maxImageBytes,
        );
    }

    /**
     * Produces the user-facing text block for a non-image attachment, plus
     * the inline bytes it consumed against the row's remaining `$budget`.
     *
     * The invariant: a text-ish source within both the per-asset cap and the
     * row's remaining budget is its own text; anything else gets an `md`
     * derivative; and if neither fits, the LLM is told where to read it.
     *
     * Resolution order:
     *   1. The asset has an `md` derivative (PDF, docx, …) and the
     *      recorded `byte_size` is within both {@see MAX_INLINE_TEXT_BYTES}
     *      and `$budget` — inline the derivative's bytes. The size gate
     *      reads the column first, so a 200-page PDF is never read off disk
     *      only to be discarded — the derivative path costs a
     *      `media_derivatives` lookup, a second `MediaAsset::find()` and a
     *      real `file_get_contents()` per attachment per turn. The loaded
     *      bytes are then re-measured, so a stale `byte_size` cannot smuggle
     *      an oversized body into the context window.
     *   2. The asset's mime type looks text-safe AND the raw bytes fit the
     *      same two bounds AND the leading bytes contain no NUL — inline the
     *      raw bytes. This is what keeps `create_media` and `note.txt` from
     *      spawning a pointless `foo.md` derivative of `foo.md`.
     *   3. Out of bounds, budget spent by an earlier attachment, NUL-
     *      containing, or binary with no derivative — the LLM gets the
     *      metadata prefix block (a sibling) for the asset_id plus a body
     *      naming `get_source`. The pointer is a tail fallback, not the
     *      common path: `[no extractable text]` would be actively wrong
     *      here, because an in-bounds-mime asset whose bytes merely exceed
     *      the budget absolutely does have text — the LLM would report
     *      "no text" and stop instead of reading it. It also keeps the LLM
     *      from trying `read_url file:///api/v1/assets/...`.
     *
     * A zero `$budget` therefore reaches branch 3 for an otherwise-inlinable
     * asset, which is the intended outcome: the budget is spent, and the
     * pointer tells the LLM the content exists and how to reach it.
     *
     * @return array{0: array<string, mixed>, 1: int} The block, and the bytes it inlined (0 on the pointer branch).
     */
    private function buildTextBlock(MediaAsset $asset, ?MediaAsset $derivative, int $budget): array
    {
        $displayName = $asset->filename ?? $asset->id;
        $cap         = min(self::MAX_INLINE_TEXT_BYTES, $budget);

        // 1. The `md` derivative of a binary document.
        $extracted = $this->loadInlinableDerivativeText($derivative, $cap);
        if ($extracted !== null) {
            return [
                [
                    'type' => 'text',
                    'text' => "# {$displayName} (extracted text)\n\n" . $extracted,
                ],
                strlen($extracted),
            ];
        }

        // 2. Text-ish source, in bounds: the bytes are their own text.
        if ($this->isLikelyTextMime($asset)) {
            $bytes = $this->loadAssetBytes($asset);
            if ($bytes !== null
                && strlen($bytes) <= $cap
                && $this->bytesLookLikeText($bytes)
            ) {
                return [
                    [
                        'type' => 'text',
                        'text' => "# {$displayName} (raw text — no converter registered)\n\n" . $bytes,
                    ],
                    strlen($bytes),
                ];
            }
        }

        // 3. Nothing inlineable — point at the tool that can read it.
        return [
            [
                'type' => 'text',
                'text' => "# {$displayName} (no inline text)\n\n"
                    . '[too large to inline — call `get_source` with the asset_id above to read the file]',
            ],
            0,
        ];
    }

    /**
     * The derivative's bytes when it exists AND is within both the per-asset
     * cap and the row's remaining budget, otherwise null. The `byte_size`
     * column is the gate: it is populated at creation time, so an oversized
     * derivative is rejected without touching the disk at all.
     */
    private function loadInlinableDerivativeText(?MediaAsset $derivative, int $cap): ?string
    {
        if ($derivative === null || ! $this->fitsInlineBudget((int) ($derivative->byte_size ?? 0), $cap)) {
            return null;
        }

        $bytes = $this->loadAssetBytes($derivative);
        // The column is only trustworthy for a derivative whose `createNew()`
        // set it. Re-check the real length for anything that predates it, so
        // a stale `byte_size` cannot smuggle an oversized body into the
        // context window.
        return ($bytes === null || $bytes === '' || ! $this->fitsInlineBudget(strlen($bytes), $cap))
            ? null
            : $bytes;
    }

    private function fitsInlineBudget(int $length, int $cap = self::MAX_INLINE_TEXT_BYTES): bool
    {
        return $length <= $cap;
    }

    /**
     * Whether the asset's mime type is safe to inline as raw text
     * without a registered converter. Matches the whole `text/*` family
     * plus a curated list of common text-based application types
     * (JSON, XML, YAML, code, Typst source, etc.). Binary formats
     * (PDF, image/*, audio/*, video/*, zip) are intentionally absent.
     */
    private function isLikelyTextMime(MediaAsset $asset): bool
    {
        $mime = strtolower((string) ($asset->mime_type ?? ''));
        if ($mime === '') {
            return false;
        }
        if (str_starts_with($mime, self::TEXT_MIME_PREFIX)) {
            return true;
        }
        return in_array($mime, self::KNOWN_TEXT_APPLICATION_MIMES, true);
    }

    /**
     * Cheap text-shape check on the first {@see TEXT_INSPECTION_BYTES}
     * of the asset. Rejects binary files mislabeled with a text mime
     * (any NUL byte in the sample flags the file as binary) and accepts
     * everything else — empty content counts as text. The 4 KB window
     * catches common binary magic (PNG, PDF, ZIP) while staying cheap.
     */
    private function bytesLookLikeText(string $bytes): bool
    {
        $sample = substr($bytes, 0, self::TEXT_INSPECTION_BYTES);
        if ($sample === '') {
            return true;
        }
        return strpos($sample, "\0") === false;
    }

    /**
     * Assembles the `content` array. Metadata blocks (one per attachment)
     * lead. The composed prompt + extracted text occupies a single
     * leading text block when a prompt is present (or when the
     * attachment layout is wider than a single text body); image
     * blocks follow.
     *
     * @param array{text: list<array<string, mixed>>, image: list<array<string, mixed>>, metadata: list<array<string, mixed>>, missingImages: int, unreadableImages: int, droppedImages: int} $blocks
     * @return list<array<string, mixed>>
     */
    private function buildAttachmentContent(array $blocks, string $prompt): array
    {
        // No prompt, no body text — metadata blocks possibly followed by images.
        // Covers both "metadata-only" (oversized image on non-vision driver)
        // and "image-only with no prompt" in one branch.
        if ($prompt === '' && $blocks['text'] === []) {
            return array_merge($blocks['metadata'], $blocks['image']);
        }

        // Trivial: single text body + single metadata + no prompt — pass
        // them through unchanged (no `---` rewrite).
        if ($prompt === '' && count($blocks['text']) === 1 && count($blocks['metadata']) === 1) {
            return [$blocks['metadata'][0], $blocks['text'][0]];
        }

        // General case: metadata + composed text + images.
        return array_merge(
            $blocks['metadata'],
            [['type' => 'text', 'text' => $this->composeTextContent($prompt, $blocks['text'])]],
            $blocks['image'],
        );
    }

    /**
     * @param list<array<string, mixed>> $textBlocks
     */
    private function composeTextContent(string $prompt, array $textBlocks): string
    {
        $attachmentSections = array_map(
            static fn(array $block): string => (string) ($block['text'] ?? ''),
            $textBlocks,
        );
        $attachmentsText = implode("\n\n", $attachmentSections);

        if ($prompt === '') {
            return $attachmentsText;
        }
        if ($attachmentsText === '') {
            return $prompt;
        }
        return $prompt . "\n\n---\n\n" . $attachmentsText;
    }

    /**
     * Identity prefix emitted alongside every attachment block (image and
     * text). Carries the asset_id + filename + mime + size + local URL
     * so the LLM can pass the asset_id or local URL to any tool that
     * accepts Media Archive references (e.g. `minimax:video` with the
     * plugin's resolver hook, or `media:get_media`). Kept compact —
     * ~60 tokens per attachment.
     *
     * @return array<string, mixed>
     */
    public static function attachmentMetadataBlock(MediaAsset $asset): array
    {
        $sizeLabel = $asset->byte_size !== null
            ? ' (' . self::humanByteSize((int) $asset->byte_size) . ')'
            : '';

        return [
            'type' => 'text',
            'text' => sprintf(
                '[Attached asset_id=%s (filename: %s, type: %s%s) — local URL: %s]',
                $asset->id,
                $asset->filename ?? '(unnamed)',
                $asset->mime_type ?? 'application/octet-stream',
                $sizeLabel,
                $asset->publicUrl(),
            ),
        ];
    }

    private static function humanByteSize(int $bytes): string
    {
        return match (true) {
            $bytes >= 1_048_576 => number_format($bytes / 1_048_576, 1) . ' MB',
            $bytes >= 1_024     => number_format($bytes / 1_024, 1) . ' KB',
            default             => $bytes . ' B',
        };
    }

    private function loadAssetBytes(MediaAsset $asset): ?string
    {
        return MediaImageBlocks::loadBytes($asset, $this->basePath);
    }
}
