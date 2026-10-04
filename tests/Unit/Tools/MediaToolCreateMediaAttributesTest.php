<?php

declare(strict_types=1);

use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\MediaTool;
use Spora\Tools\Schema\OperationSchemaFilter;
use Spora\Tools\Schema\ToolParameterSchemaBuilder;

/**
 * Wire-schema coverage for the `create_media` operation.
 *
 * Two things have to be true before the op is usable by the LLM, and
 * neither is observable from a happy-path `execute()` test:
 *
 *   1. It has to be *reachable* — declared, in the synthesized
 *      `action` enum, and in the `Invalid action` message the `default`
 *      arm emits (an op missing from that string tells the model the
 *      op does not exist, even though dispatch works).
 *   2. It has to be *gated correctly* — `enabledByDefault: true` and
 *      auto-approved, because the row it writes is bounded on every side
 *      that would make an unapproved write surprising: the payload is
 *      capped at 1 MiB, the MIME is re-sniffed from the bytes and
 *      allowlist-gated, and the asset is scoped to the calling agent.
 *      What an operator sees afterwards is an ordinary library row, not
 *      a new capability. `get_public_url` is the one op that asks for
 *      approval, because it is the one that reaches outside the session.
 *
 * The `required[]` narrowings matter for a second reason: `content` and
 * `filename` are meaningless to every other op, so leaving them
 * globally required would make the orchestrator reject `get_media` calls
 * that legitimately carry neither.
 */

function createMediaOperation(): ToolOperation
{
    $attributes = array_map(
        static fn(ReflectionAttribute $a) => $a->newInstance(),
        (new ReflectionClass(MediaTool::class))->getAttributes(ToolOperation::class),
    );
    foreach ($attributes as $op) {
        if ($op->name === 'create_media') {
            return $op;
        }
    }
    throw new RuntimeException('MediaTool has no #[ToolOperation] named create_media');
}

function createMediaParameter(string $name): ToolParameter
{
    $attributes = array_map(
        static fn(ReflectionAttribute $a) => $a->newInstance(),
        (new ReflectionClass(MediaTool::class))->getAttributes(ToolParameter::class),
    );
    foreach ($attributes as $param) {
        if ($param->name === $name) {
            return $param;
        }
    }
    throw new RuntimeException("MediaTool has no #[ToolParameter] named {$name}");
}

it('declares create_media as enabled by default and auto-approved', function (): void {
    $op = createMediaOperation();

    expect($op->enabledByDefault)->toBeTrue()
        ->and($op->requiresApprovalByDefault)->toBeFalse()
        ->and($op->operatorDescription)->toBe('Create a text media asset');
});

it('warns the LLM that create_media is not idempotent in the op description', function (): void {
    // `MediaIngestRequest` dedupes only on (tool_call_id, source_url) and
    // the byte path has neither, so a retry silently doubles the archive.
    // The only place the model reads before deciding to retry is this line.
    expect(createMediaOperation()->description)
        ->toContain('Store text as a new media asset')
        ->toContain('Non-idempotent')
        ->toContain('asset_id');
});

it('requires content and filename for create_media only', function (): void {
    expect(createMediaParameter('content')->required)->toBe(['create_media']);
    expect(createMediaParameter('filename')->required)->toBe(['create_media']);
});

it('treats mime_type and prompt as optional', function (): void {
    expect(createMediaParameter('mime_type')->required)->toBeFalse();
    expect(createMediaParameter('prompt')->required)->toBeFalse();
});

it('documents mime_type as a re-sniffed hint defaulting to text/markdown', function (): void {
    $param = createMediaParameter('mime_type');

    // The default is named in the description rather than declared on the
    // attribute. The schema advertises `mime_type` to every op that takes
    // it, and `search` maps the value through MediaType::fromMime — where
    // `text/markdown` resolves to the Document bucket. A declared default
    // would invite the model to narrow its search to documents, and
    // nothing in core applies a JSON-Schema default at runtime anyway.
    expect($param->type)->toBe('string')
        ->and($param->default)->toBeNull()
        ->and($param->description)->toContain('default "text/markdown"')
        ->and($param->description)->toContain('Hint only')
        ->and($param->description)->toContain('re-sniffs')
        ->and($param->description)->toContain('authoritative');
});

it('caps content at 1 MiB in the parameter description', function (): void {
    expect(createMediaParameter('content')->description)->toContain('1 MiB');
});

it('lists create_media in the synthesized action enum', function (): void {
    $schema = ToolParameterSchemaBuilder::build(MediaTool::class);

    expect($schema['properties']['action']['enum'])->toContain('create_media');
});

it('narrows content + filename into required[] only when create_media is allowed', function (): void {
    $schema = ToolParameterSchemaBuilder::build(MediaTool::class);

    $withCreate = OperationSchemaFilter::filter($schema, ['create_media'], 'action');
    expect($withCreate['required'])->toContain('content', 'filename');

    $withoutCreate = OperationSchemaFilter::filter($schema, ['get_media', 'get_embed_code'], 'action');
    expect($withoutCreate['required'])->not->toContain('content', 'filename', 'prompt');
});

it('keeps asset_id out of required[] when only create_media is allowed', function (): void {
    // `create_media` mints a new asset — it takes no `asset_id`, so the
    // filter must not drag the per-asset ops' requirement along with it.
    $schema = ToolParameterSchemaBuilder::build(MediaTool::class);

    $createOnly = OperationSchemaFilter::filter($schema, ['create_media'], 'action');

    expect($createOnly['required'])->not->toContain('asset_id');
});

it('names create_media in the invalid-action error message', function (): void {
    // A stale valid-ops string is invisible in the schema — the op would
    // still dispatch — but it reads to the model as "this tool has no
    // such action", which is the fastest way to make it stop using the op.
    $tool = buildMediaToolForSchema();

    $result = $tool->execute(['action' => 'nope'], agentId: 1);

    expect($result->success)->toBeFalse();
    expect($result->content)
        ->toContain('create_derivative')
        ->toContain('create_media');
});

it('describes the action with the filename and mime hint', function (): void {
    $tool = buildMediaToolForSchema();

    expect($tool->describeAction([
        'action'    => 'create_media',
        'filename'  => 'report.md',
        'mime_type' => 'text/markdown',
    ]))->toBe('Media create_media(report.md, mime=text/markdown)');
});
