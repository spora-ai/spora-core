<?php

declare(strict_types=1);

use Spora\AgentTemplates\AgentTemplateAgentCreator;
use Spora\AgentTemplates\AgentTemplateImporter;
use Spora\AgentTemplates\AgentTemplateScanner;
use Spora\AgentTemplates\AgentTemplateSettingsApplier;
use Spora\AgentTemplates\AgentTemplateToolsApplier;
use Spora\Core\SecurityManager;
use Spora\Plugins\PluginLoader;
use Spora\Services\ToolConfigService;
use Spora\Skills\SkillProviderRegistry;
use Spora\Tools\SkillTool;
use Tests\Fixtures\Skills\StubSkillProvider;

/**
 * `allowed_skills` is intersected against the **registry** on import, not
 * against the shipped directories. That distinction is the point of the
 * provider seam: `spora-plugin-custom-skills` serves database-backed skills that
 * `SkillScanner` never sees, so asking the scanner dropped them from every
 * template with a `SKILL_MISSING` warning naming a skill that *was* installed —
 * and the `GET /agents/{id}/export?include_settings=1` →
 * `POST /agent-templates/import` round-trip silently shed the whole list.
 *
 * The other half matters just as much: a name no provider serves must still be
 * refused, or the registry would wave through a grant nothing can read.
 */
beforeEach(function (): void {
    $this->userId = bootAuth(bootAuthLayer(), 'applier-skills@example.com');
});

/**
 * An importer whose settings applier sees exactly `$skills`, plus the config
 * service so the landed override row can be read back.
 *
 * @return array{0: AgentTemplateImporter, 1: ToolConfigService}
 */
function importerWithSkillRegistry(?SkillProviderRegistry $skills): array
{
    $toolConfig = new ToolConfigService(
        new SecurityManager(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
        new Monolog\Logger('test'),
        [SkillTool::class],
    );

    return [
        new AgentTemplateImporter(
            $toolConfig,
            new PluginLoader([]),
            new AgentTemplateScanner(),
            new AgentTemplateToolsApplier(
                $toolConfig,
                new AgentTemplateSettingsApplier($toolConfig, $skills),
            ),
            new AgentTemplateAgentCreator(),
        ),
        $toolConfig,
    ];
}

/**
 * A template granting `$skills` on the Skill tool.
 *
 * @param list<string> $skills
 * @return array<string, mixed>
 */
function templateGrantingSkills(array $skills): array
{
    return [
        'id' => 'grants-skills', 'name' => 'Grants Skills', 'version' => '1.0.0',
        'agent' => ['system_prompt' => 'x'],
        'tools' => [[
            'tool_class' => SkillTool::class,
            'enabled' => true,
            'operations' => [],
            'settings' => ['allowed_skills' => $skills],
        ]],
    ];
}

test('a template granting a provider-supplied skill keeps it', function (): void {
    // No directory anywhere near this skill: the stub provider serves it from
    // memory, exactly as spora-plugin-custom-skills serves it from rows.
    $registry = new SkillProviderRegistry([
        (new StubSkillProvider('custom-skills'))->add('quarterly-report', ['notes.md']),
    ]);
    [$importer, $toolConfig] = importerWithSkillRegistry($registry);

    $result = $importer->importPayload($this->userId, templateGrantingSkills(['quarterly-report']));
    $settings = $toolConfig->getRawAgentOverride(SkillTool::class, (int) $result->agent->id);

    expect($settings['allowed_skills'])->toBe('["quarterly-report"]')
        ->and(array_column($result->warnings, 'code'))->not->toContain('SKILL_MISSING');
});

test('a template granting a name no provider serves warns and drops only that name', function (): void {
    $registry = new SkillProviderRegistry([
        (new StubSkillProvider('custom-skills'))->add('quarterly-report'),
    ]);
    [$importer, $toolConfig] = importerWithSkillRegistry($registry);

    $result = $importer->importPayload($this->userId, templateGrantingSkills(['quarterly-report', 'no-such-skill']));
    $settings = $toolConfig->getRawAgentOverride(SkillTool::class, (int) $result->agent->id);
    $warning = collect($result->warnings)->firstWhere('code', 'SKILL_MISSING');

    expect($settings['allowed_skills'])->toBe('["quarterly-report"]')
        ->and($warning['path'])->toBe('tools[0].settings.allowed_skills');
});

test('a principal-scoped provider contributes nothing to an import', function (): void {
    // The import runs as nobody in particular. Granting a skill whose visibility
    // depends on who asked would make the grant depend on the importer — the same
    // rule ToolsRecommendsSkillsValidator holds to with its own null listing.
    $provider = (new StubSkillProvider('custom-skills'))
        ->add('quarterly-report', ['notes.md'], 'body', null, 7);
    $provider->onlyVisibleTo = 7;

    [$importer, $toolConfig] = importerWithSkillRegistry(new SkillProviderRegistry([$provider]));

    $result = $importer->importPayload($this->userId, templateGrantingSkills(['quarterly-report']));
    $settings = $toolConfig->getRawAgentOverride(SkillTool::class, (int) $result->agent->id);

    expect($settings['allowed_skills'])->toBe('[]')
        ->and(array_column($result->warnings, 'code'))->toContain('SKILL_MISSING');
});

test('an unwired registry performs no availability check at all', function (): void {
    // The property is optional because tests and build-time contexts construct
    // the applier bare. Writing the list through is the deliberate shape there:
    // emptying every imported agent's allowlist because a wiring is absent would
    // be the more damaging failure, and an unknown slug still surfaces as
    // "(unavailable: <slug>)" in the tool definition.
    [$importer, $toolConfig] = importerWithSkillRegistry(null);

    $result = $importer->importPayload($this->userId, templateGrantingSkills(['quarterly-report']));
    $settings = $toolConfig->getRawAgentOverride(SkillTool::class, (int) $result->agent->id);

    expect($settings['allowed_skills'])->toBe('["quarterly-report"]')
        ->and(array_column($result->warnings, 'code'))->not->toContain('SKILL_MISSING');
});

test('the container wires the registry into the applier, not the scanner', function (): void {
    // The seam miss was a DI-level one and no unit test could have caught it: the
    // applier was handed a `SkillScanner` that resolved every shipped skill and
    // no provider-supplied one. Asserted through the real container because that
    // is where the wiring actually lives.
    $c = (new Spora\Core\Kernel())->getContainer();

    $applier = $c->get(AgentTemplateSettingsApplier::class);
    $skills = (new ReflectionProperty($applier, 'skills'))->getValue($applier);

    expect($skills)->toBeInstanceOf(SkillProviderRegistry::class)
        ->and($skills)->toBe($c->get(SkillProviderRegistry::class));
});
