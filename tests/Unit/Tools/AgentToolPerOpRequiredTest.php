<?php

declare(strict_types=1);

use Spora\Tools\AgentTool;
use Spora\Tools\Schema\OperationSchemaFilter;
use Spora\Tools\Schema\ToolParameterSchemaBuilder;

/**
 * Per-op `required[]` narrowing for AgentTool's `agent`, `content`, and
 * `payload` parameters. The builder is fully reflective, so we feed it a
 * class-string and never instantiate AgentTool — instantiating would require
 * AgentServiceInterface / AgentToolSettingsServiceInterface / the importer /
 * the validator for what is a pure attribute-shape test.
 *
 * The schema leaves a `__required_when` side channel; OperationSchemaFilter
 * reads it, narrows `required[]` to ops the agent can actually invoke, and
 * strips it before the schema reaches the LLM.
 */
it('declares `agent` as required for update_agent + write_agent_configuration', function (): void {
    // Both the canonical `update_agent` and its deprecated alias
    // `write_agent_configuration` carry the `agent` object — the
    // alias stays in required[] until the soft-redirect is hard-removed.
    $schema = ToolParameterSchemaBuilder::build(AgentTool::class);

    expect($schema['__required_when']['agent'] ?? null)
        ->toBe(['update_agent', 'write_agent_configuration']);

    // update_agent alone → `agent` stays required.
    $updateOnly = OperationSchemaFilter::filter($schema, ['update_agent'], 'action');
    expect($updateOnly['required'])->toContain('agent');

    // write_agent_configuration alone → `agent` stays required too.
    $writeOnly = OperationSchemaFilter::filter($schema, ['write_agent_configuration'], 'action');
    expect($writeOnly['required'])->toContain('agent');

    // Agent allowed only read_agent → `agent` is dropped.
    $readOnly = OperationSchemaFilter::filter($schema, ['read_agent'], 'action');
    expect($readOnly['required'])->not->toContain('agent');

    // Empty allowed-set (tool disabled) → `agent` drops out too.
    $noneAllowed = OperationSchemaFilter::filter($schema, [], 'action');
    expect($noneAllowed['required'])->not->toContain('agent');
});

it('declares `content` as required for write_notes and write_notes_overwrite', function (): void {
    $schema = ToolParameterSchemaBuilder::build(AgentTool::class);

    expect($schema['__required_when']['content'] ?? null)
        ->toBe(['write_notes', 'write_notes_overwrite']);

    // write_notes alone → content stays required.
    $append = OperationSchemaFilter::filter($schema, ['write_notes'], 'action');
    expect($append['required'])->toContain('content');

    // write_notes_overwrite alone → content stays required.
    $overwrite = OperationSchemaFilter::filter($schema, ['write_notes_overwrite'], 'action');
    expect($overwrite['required'])->toContain('content');

    // read_notes / read_agent → content drops out.
    $read = OperationSchemaFilter::filter($schema, ['read_notes'], 'action');
    expect($read['required'])->not->toContain('content');

    $readAgent = OperationSchemaFilter::filter($schema, ['read_agent'], 'action');
    expect($readAgent['required'])->not->toContain('content');
});

it('declares `payload` as required only for create_agent', function (): void {
    $schema = ToolParameterSchemaBuilder::build(AgentTool::class);

    expect($schema['__required_when']['payload'] ?? null)
        ->toBe(['create_agent']);

    $create = OperationSchemaFilter::filter($schema, ['create_agent'], 'action');
    expect($create['required'])->toContain('payload');

    $updateAgent = OperationSchemaFilter::filter($schema, ['update_agent'], 'action');
    expect($updateAgent['required'])->not->toContain('payload');

    $writeConfig = OperationSchemaFilter::filter($schema, ['write_agent_configuration'], 'action');
    expect($writeConfig['required'])->not->toContain('payload');
});

it('strips the __required_when side channel after narrowing', function (): void {
    $schema = ToolParameterSchemaBuilder::build(AgentTool::class);
    expect($schema)->toHaveKey('__required_when');

    $filtered = OperationSchemaFilter::filter($schema, ['write_notes'], 'action');
    expect($filtered)->not->toHaveKey('__required_when');
});

it('keeps `action` and `mode` as globally required or enum-restricted regardless of op subset', function (): void {
    $schema = ToolParameterSchemaBuilder::build(AgentTool::class);

    // Single-op: discriminator is stripped from required[] (back-compat).
    // Enum still covers the live ops; read_agent_configuration was removed
    // when it became a soft-redirect to read_agent(self).
    foreach (['write_notes', 'create_agent', 'read_agent', 'configure_tools'] as $op) {
        $filtered = OperationSchemaFilter::filter($schema, [$op], 'action');
        expect($filtered['required'])->not->toContain('action')
            ->and($filtered['properties']['action']['enum'])->toContain($op);
    }

    // `mode` is not bound to any op (required: false) — it should never be in
    // required[] regardless of the allowed-op subset.
    $filtered = OperationSchemaFilter::filter($schema, ['write_notes'], 'action');
    expect($filtered['required'])->not->toContain('mode');
});
