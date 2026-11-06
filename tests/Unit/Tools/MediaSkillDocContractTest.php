<?php

declare(strict_types=1);

use Spora\Services\MediaArchive\MediaAllowedTypesService;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\MediaTool;

/**
 * The `media-library` skill and the media tool have to agree.
 *
 * The skill is the only place the model is told what the tool does, so
 * a drift between the two is a drift in the model's understanding of
 * its own tools. Two of the drifts this file exists for were found by a
 * skill-review agent exercising the tool against a live build; the third
 * by reading the skill against the allowlist:
 *
 *   - the approval table named the operations that ask for approval, and
 *     the platform's answer depended on whether the agent had a stored
 *     override — so the table was right and incomplete at the same time;
 *   - the skill pointed at `list_derivatives` as the way to discover
 *     the supported derivative formats, which is an op that lists the
 *     derivatives an asset *already has*.
 *   - the skill listed `text/html` among the always-allowed text mimes
 *     after the operator toggle took it off the static allowlist, so it
 *     taught the model to store a format the gate refuses.
 *
 * The behavioural half of each is covered elsewhere; what nothing
 * asserted was that the *documentation* matched it. That is the gap
 * this file closes.
 */
function mediaSkillBody(): string
{
    $path = BASE_PATH . '/skills/media-library/SKILL.md';
    expect(is_file($path))->toBeTrue("bundled skill missing at {$path}");

    return (string) file_get_contents($path);
}

/**
 * The skill's operation matrix, read as
 * operation => requires approval by default.
 *
 * @return array<string, bool>
 */
function mediaSkillApprovalColumn(): array
{
    preg_match_all(
        '/^\|\s*`([a-z_]+)`\s*\|\s*(?:true|false)\s*\|\s*\*{0,2}(true|false)\*{0,2}\s*\|/m',
        mediaSkillBody(),
        $matches,
        PREG_SET_ORDER,
    );

    $out = [];
    foreach ($matches as $match) {
        $out[$match[1]] = $match[2] === 'true';
    }

    return $out;
}

/**
 * The tool's own declaration, read the way the orchestrator reads it.
 *
 * @return array<string, bool>
 */
function mediaToolApprovalColumn(): array
{
    $reflection = new ReflectionClass(MediaTool::class);

    $out = [];
    foreach ($reflection->getAttributes(ToolOperation::class) as $attribute) {
        /** @var ToolOperation $operation */
        $operation = $attribute->newInstance();
        $out[$operation->name] = $operation->requiresApprovalByDefault;
    }

    return $out;
}

describe('the media-library skill matches the media tool', function (): void {

    it('declares the same operations, with the same approval default', function (): void {
        // The table is the model's only picture of what is gated. A row
        // that names the wrong default teaches the model to expect a
        // prompt that never comes — or to expect none that does.
        expect(mediaSkillApprovalColumn())->toBe(mediaToolApprovalColumn());
    });

    it('points at the live state rather than claiming the table is the truth', function (): void {
        // The default is the attribute; the configuration is the agent's
        // stored override, and the override wins. A table that reads as
        // a promise about this agent is wrong the moment an operator
        // touches the dashboard.
        $body = mediaSkillBody();

        expect($body)->toContain('read_agent')
            ->and($body)->toContain('get_available_tools');
    });

    it('names the five gated operations in the Approval section', function (): void {
        $body    = mediaSkillBody();
        $section = substr($body, (int) strpos($body, '## Approval'));

        foreach (mediaToolApprovalColumn() as $operation => $requiresApproval) {
            if ($requiresApproval) {
                expect($section)->toContain('`' . $operation . '`');
            }
        }
    });

    it('does not send the agent to list_derivatives to discover the supported formats', function (): void {
        // `list_derivatives` lists the derivatives an asset already has.
        // On a fresh asset that is none at all, so it cannot answer
        // "which formats can I render this into?".
        expect(mediaSkillBody())->not->toContain('to discover the formats');
    });

    it('documents the create_derivative options parameter', function (): void {
        // The schema carries it and the producers consume it; the skill
        // did not mention it at all.
        $body = mediaSkillBody();

        expect($body)->toContain('`options` (optional)')
            ->and($body)->toContain('ppi')
            ->and($body)->toContain('longEdge');
    });

    it('lists the mime types the allowlist actually covers', function (): void {
        // The skill named nine text mimes and stopped there, while the
        // allowlist covers the audio and video containers too.
        $body = mediaSkillBody();

        foreach (MediaAllowedTypesService::TEXT_MIME_TYPES as $mime) {
            expect($body)->toContain($mime);
        }
        foreach (MediaAllowedTypesService::AUDIO_MIME_TYPES as $mime) {
            expect($body)->toContain($mime);
        }

        // The producer-supplied and image types are install-dependent,
        // so the skill names the ones core and the image policy ship.
        expect($body)->toContain('application/pdf')
            ->and($body)->toContain('image/png')
            ->and($body)->toContain('image/jpeg')
            ->and($body)->toContain('image/webp');
    });

    it('names exactly the text mimes the allowlist covers', function (): void {
        // The enumeration is compared with the allowlist's own list rather
        // than with a second copy of it written here.
        $body      = mediaSkillBody();
        $paragraph = substr($body, (int) strpos($body, '**The mime allowlist is the operator'));

        preg_match('/the text mimes \(([^)]*)\)/', $paragraph, $matches);
        $named = array_map(
            static fn(string $token): string => trim($token, " `\n"),
            explode(',', $matches[1] ?? ''),
        );

        expect($named)->toBe(MediaAllowedTypesService::TEXT_MIME_TYPES);
    });

    it('names the operator toggle that governs the script-capable formats', function (): void {
        // Whether `text/html` may exist is the operator's decision, and
        // the model has to know whose decision it is before it offers one.
        expect(mediaSkillBody())->toContain('SPORA_MEDIA_ARCHIVE_ALLOW_ACTIVE_TYPES');
    });

    it('says that the allowlist does not make create_media a binary uploader', function (): void {
        $body = mediaSkillBody();

        expect($body)->toContain('re-sniffed')
            ->and($body)->toContain('binary uploader');
    });

    it('documents the audio and the video embed shapes', function (): void {
        $body = mediaSkillBody();

        expect($body)->toContain('<audio controls preload="metadata" src=')
            ->and($body)->toContain('<video controls playsinline');
    });

    it('does not promise that every binary document gets an md derivative', function (): void {
        // The overclaim: a file that arrived as a derivative — a PDF
        // rendered from a Typst source — never gets one, because the
        // minting happens on upload and not on the derivative path.
        $body = mediaSkillBody();

        expect($body)->not->toContain('Every binary document the archive accepts gets an `md` derivative on upload')
            ->and($body)->toContain('Not every binary document has an `md` derivative');
    });

    it('documents the live embed for a script-capable asset', function (): void {
        // The skill is the only thing the model reads about this. Without a
        // section on it, the model has no way to know that a script-capable
        // asset can become a live widget at all, that the decision is the
        // operator's, or that the widget does not travel through the
        // assistant's message.
        $body = mediaSkillBody();

        expect($body)->toContain('script-capable')
            ->and($body)->toContain('text/html')
            ->and($body)->toContain('image/svg+xml')
            ->and($body)->toContain('live embed')
            ->and($body)->toContain('off by default');
    });

    it('tells the model that the widget does not travel through the assistant message', function (): void {
        // The overclaim that sent a report-writing agent hunting for a
        // fenced ```html block: the assistant's message is sanitised, so raw
        // HTML there is stripped or shown as text and never played.
        $body = mediaSkillBody();

        expect($body)->toContain('sanitised')
            ->and($body)->toContain('never played');
    });

    it('says the live embed sits in the tool bubble, beside the markdown', function (): void {
        // The claim that made an operator look at a download button and
        // conclude the platform had no widget: "instead of the download
        // card". The frame is *additional* — the bubble shows the card and
        // the frame, and the message shows the card alone.
        $body = mediaSkillBody();

        expect($body)->toContain("tool's own bubble")
            ->and($body)->toContain('live embed')
            ->and($body)->not->toContain('instead of the download card');
    });

    it('splits the two script-capable types by what the message shows', function (): void {
        // They are not one class: HTML is a document and its markdown is
        // the download card, SVG is an image and its markdown is an inline
        // picture. An agent told they share a rendering path looks for a
        // card where there is an image.
        $body = mediaSkillBody();

        expect($body)->toContain('| `text/html` | document | the download card |')
            ->and($body)->toContain('| `image/svg+xml` | image | an inline image');
    });

    it('says the SVG in the message is static', function (): void {
        // Measured: the same SVG read "SCRIPT LIVE" on the embed route and
        // "initialising…" through an `<img>`. A browser does not run an
        // SVG's script inside an image, so the message's picture is inert
        // whatever the operator's script switch says.
        expect(mediaSkillBody())->toContain("does not run an SVG's script inside an `<img>`");
    });

    it('says an SVG is write-only to the agent', function (): void {
        // `get_source` returns no bytes for a binary mime and
        // `image/svg+xml` is binary, so an agent that authors an SVG cannot
        // read it back without minting an `md` derivative first — which may
        // not exist.
        $body = mediaSkillBody();

        expect($body)->toContain('write-only')
            ->and($body)->toContain('`image/svg+xml` is binary');
    });

    it('reconciles the filename rule with the re-sniff', function (): void {
        // Two passages, one subject: `create_media` ignores the declared
        // mime and re-sniffs, and the filename wins over a prose-shaped
        // verdict. Read apart they contradict, and an agent that believes
        // the contradiction cannot tell why a report became a widget.
        $body = mediaSkillBody();

        expect($body)->toContain('re-sniffs the bytes and ignores the mime you declare')
            ->and($body)->toContain('prose-shaped');
    });

    it('does not claim a browser parses an SVG as a document in the chat', function (): void {
        // The SVG rides the image path, so the chat's markdown is an
        // `<img>` — a picture, not a document. Only the embed route serves
        // it as a document.
        $body = mediaSkillBody();

        expect($body)->not->toContain('parses both as a *document* rather than a picture');
    });

    it('teaches the model to ask for the live embed with a link', function (): void {
        // The operator was seeing a download button where a widget should
        // have been: the frame only ever rendered in the tool bubble, which
        // is collapsed. The link is the model's way of asking for it in the
        // message — and it is a reference, so the model never sees the
        // asset's bytes.
        $body = mediaSkillBody();

        expect($body)->toContain('/api/v1/media/<asset_id>/embed')
            ->and($body)->toContain('A link is the whole request')
            ->and($body)->toContain('embed route')
            ->and($body)->toContain('/api/v1/assets/<id>.html');
    });

    it('says the filename decides whether an asset is a widget', function (): void {
        // The archive honours the extension, so the model's choice of
        // filename is the choice of whether the asset is a document or a
        // live frame. A Markdown report that merely mentions HTML must not
        // become a widget.
        $body = mediaSkillBody();

        expect($body)->toContain('filename is the intent')
            ->and($body)->toContain('`.html`')
            ->and($body)->toContain('`.md`');
    });

    it('does not promise that every document is played live', function (): void {
        // The two operator switches are off by default and unreadable from
        // a tool call, so a skill that promises a live widget teaches the
        // model to make a promise it cannot keep.
        $body = mediaSkillBody();

        expect($body)->toContain('never promise the operator a live widget');
    });

    it('says that the ids come back in the response text', function (): void {
        // The structured data never reaches the model, so a skill that
        // points the model at `items[].id` points it at nothing.
        $body = mediaSkillBody();

        expect($body)->not->toContain('items[].id')
            ->and($body)->not->toContain('data.parent_id')
            ->and($body)->toContain('does **not** reach you');
    });
});
