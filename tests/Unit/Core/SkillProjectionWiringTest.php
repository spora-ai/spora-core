<?php

declare(strict_types=1);

use Spora\Core\Kernel;
use Spora\Services\ToolConfigSchemaInspector;
use Spora\Services\ToolConfigService;
use Spora\Tools\SkillTool;

/**
 * The `allowed_skills` map inside {@see ToolConfigSchemaInspector} is an
 * eager constructor snapshot and the only data `formatSkillList()` reads.
 * Two separate container definitions used to build that inspector, one with a
 * populated map (ToolConfigService) and one empty (AgentTemplateExporter), so
 * "which one is injected" was a real hazard: swapping in the empty instance
 * renders every agent's `allowed_skills` as "(not configured)" on every tick
 * while `SkillTool`'s own authorisation keeps working.
 *
 * These tests go through the real container because the failure mode is
 * invisible to unit tests — they hand-build an inspector and bypass DI
 * entirely, so only a DI-level assertion catches it.
 */
it('the container wires one inspector instance into the tool config service', function (): void {
    $c = (new Kernel())->getContainer();

    $inspector = $c->get(ToolConfigSchemaInspector::class);
    $config    = $c->get(ToolConfigService::class);

    $schema = (new ReflectionProperty(ToolConfigService::class, 'schema'))->getValue($config);

    expect($schema)->toBe($inspector);
});

it('the container-built inspector resolves the shipped skills', function (): void {
    $c = (new Kernel())->getContainer();

    $inspector = $c->get(ToolConfigSchemaInspector::class);

    $projection = $inspector->getLlmToolSettings(
        SkillTool::class,
        ['allowed_skills' => [(string) $c->get(Spora\Skills\SkillScanner::class)->scan()[0]->name()]],
    );

    // A non-empty value here is the guard. An empty map still returns the key,
    // so the assertion is on the resolved value, never on the key's presence.
    expect($projection['allowed_skills']['value'])->not->toBe([]);
});
