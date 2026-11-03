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
 * skill-review agent exercising the tool against a live build:
 *
 *   - the approval table named the operations that ask for approval, and
 *     the platform's answer depended on whether the agent had a stored
 *     override — so the table was right and incomplete at the same time;
 *   - the skill pointed at `list_derivatives` as the way to discover
 *     the supported derivative formats, which is an op that lists the
 *     derivatives an asset *already has*.
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

    it('says that the ids come back in the response text', function (): void {
        // The structured data never reaches the model, so a skill that
        // points the model at `items[].id` points it at nothing.
        $body = mediaSkillBody();

        expect($body)->not->toContain('items[].id')
            ->and($body)->not->toContain('data.parent_id')
            ->and($body)->toContain('does **not** reach you');
    });
});
