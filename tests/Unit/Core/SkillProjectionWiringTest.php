<?php

declare(strict_types=1);

use Spora\Core\Kernel;
use Spora\Services\ToolConfigSchemaInspector;
use Spora\Services\ToolConfigService;
use Spora\Skills\SkillProviderRegistry;
use Spora\Tools\AgentTool;
use Spora\Tools\AgentTool\SkillCatalogPresenter;
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

/**
 * The same identity argument, one layer up: `AgentTool` reaches skills through
 * two optional collaborators, and both are guarded with `$c->has()` because
 * the orchestrator slice that defines them is not present in every context
 * that builds this container. A guard that is wrong in production produces no
 * error at all — the planner's principal check quietly approves every name and
 * the `skills` block quietly disappears — so it is asserted at the DI level.
 */
it('the container wires the skill registry into every AgentTool skill path', function (): void {
    $c = (new Kernel())->getContainer();

    $tool = $c->get(AgentTool::class);

    // The write path reaches skills through the planner's parser, whose settings
    // half owns the principal check; the planner itself only applies a validated
    // plan.
    $planner = (new ReflectionProperty(AgentTool::class, 'configurePlanner'))->getValue($tool);
    $parser = (new ReflectionProperty($planner, 'parser'))->getValue($planner);
    $settingsParser = (new ReflectionProperty($parser, 'settings'))->getValue($parser);
    $plannerSkills = (new ReflectionProperty($settingsParser, 'skills'))->getValue($settingsParser);

    $catalog = (new ReflectionProperty(AgentTool::class, 'catalogPresenter'))->getValue($tool);
    $skillCatalog = (new ReflectionProperty($catalog, 'skillCatalog'))->getValue($catalog);

    expect($plannerSkills)->toBeInstanceOf(SkillProviderRegistry::class)
        ->and($plannerSkills)->toBe($c->get(SkillProviderRegistry::class))
        // Same registry identity as the read gate's — the point being pinned
        // above applies here too: two registries means a name the write accepts
        // and the read refuses, or the reverse.
        ->and($skillCatalog)->toBeInstanceOf(SkillCatalogPresenter::class);

    $presenterSkills = (new ReflectionProperty($skillCatalog, 'skills'))->getValue($skillCatalog);
    expect($presenterSkills)->toBe($c->get(SkillProviderRegistry::class));
});
