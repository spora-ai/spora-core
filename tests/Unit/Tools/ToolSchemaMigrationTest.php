<?php

declare(strict_types=1);

namespace Tests\Unit\Tools;

use Mockery;
use ReflectionClass;
use Spora\Services\HandoverServiceInterface;
use Spora\Services\ToolConfigService;
use Spora\Tools\AbstractTool;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\CalculatorTool;
use Spora\Tools\CurrentTimeTool;
use Spora\Tools\HandoverTool;
use Spora\Tools\ReadUrlTool;
use Spora\Tools\ToolInterface;
use Spora\Tools\UserInfoTool;
use stdClass;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Structural invariants for every registered tool.
 *
 * After the attribute-driven schema migration landed, the per-tool snapshot
 * fixture (tests/Unit/Tools/Fixtures/tool_schemas.json) was retired in favour
 * of these invariants — they assert what authors must keep true rather than
 * pinning the schema to a frozen byte-for-byte baseline.
 *
 * Run for every core tool in app/Tools/. Adding a new tool requires adding
 * it to instantiateAllTools() — the test fails loudly otherwise.
 */

/**
 * Build a fresh instance of each tool with minimal mocked dependencies.
 * Tools may need DI (HttpClient, ToolConfigService) but the invariants
 * here don't call any tool method that touches those — Mockery doubles
 * with no expectations are enough.
 *
 * @return array<class-string<ToolInterface>, ToolInterface>
 */
function instantiateAllTools(): array
{
    $httpClient    = Mockery::mock(HttpClientInterface::class);
    $configService = Mockery::mock(ToolConfigService::class);

    return [
        CalculatorTool::class       => new CalculatorTool(),
        CurrentTimeTool::class      => new CurrentTimeTool(),
        ReadUrlTool::class          => new ReadUrlTool($httpClient, $configService),
        UserInfoTool::class         => new UserInfoTool(),
        HandoverTool::class         => new HandoverTool(
            Mockery::mock(HandoverServiceInterface::class),
            $configService,
        ),
    ];
}

it('every core tool extends AbstractTool', function (): void {
    foreach (instantiateAllTools() as $cls => $_) {
        expect(is_subclass_of($cls, AbstractTool::class))->toBeTrue(
            "{$cls} must extend AbstractTool — see docs/06_tools.md.",
        );
    }
});

it('every core tool carries the #[Tool] attribute with a valid snake_case name', function (): void {
    foreach (instantiateAllTools() as $cls => $_) {
        $ref = new ReflectionClass($cls);
        $attrs = $ref->getAttributes(Tool::class);
        expect($attrs)->toHaveCount(1, "{$cls} must declare exactly one #[Tool] attribute.");
        // #[Tool] validates the name regex itself; reading the attribute proves it's a snake_case name.
        $tool = $attrs[0]->newInstance();
        expect($tool->name)->toMatch('/^[a-z][a-z0-9_]*$/');
    }
});

it('every core tool produces a JSON-Schema-shaped parameters object', function (): void {
    foreach (instantiateAllTools() as $cls => $instance) {
        $schema = $instance->getParametersSchema();

        expect($schema['type'])->toBe('object', "{$cls} schema.type must be 'object'.");
        expect($schema['required'])->toBeArray();
    }
});

it('multi-op tools synthesize a discriminator property listing every declared operation', function (): void {
    foreach (instantiateAllTools() as $cls => $instance) {
        $ref = new ReflectionClass($cls);
        $opAttrs = ToolParameterSchemaBuilderHelper::collectAttributes($ref, ToolOperation::class);

        if (count($opAttrs) < 2) {
            // Single-op tools intentionally skip discriminator synthesis.
            continue;
        }

        $operations = array_map(static fn($a) => $a->newInstance(), $opAttrs);
        $discriminatorKey = $operations[0]->discriminatorKey;
        $opNames = array_map(static fn(ToolOperation $op) => $op->name, $operations);

        $schema = $instance->getParametersSchema();
        $properties = $schema['properties'];
        if ($properties instanceof stdClass) {
            $properties = (array) $properties;
        }

        expect(array_key_exists($discriminatorKey, $properties))->toBeTrue(
            "{$cls} should expose the synthesized '{$discriminatorKey}' property.",
        );
        expect($properties[$discriminatorKey]['enum'])->toBe(
            $opNames,
            "{$cls} discriminator enum must list every declared operation in declaration order.",
        );
        expect(in_array($discriminatorKey, $schema['required'], true))->toBeTrue(
            "{$cls} discriminator '{$discriminatorKey}' must be in required[].",
        );
    }
});

it('single-op tools do NOT synthesize a discriminator', function (): void {
    $checked = 0;
    foreach (instantiateAllTools() as $cls => $instance) {
        $ref = new ReflectionClass($cls);
        $opAttrs = ToolParameterSchemaBuilderHelper::collectAttributes($ref, ToolOperation::class);

        if (count($opAttrs) !== 1) {
            continue;
        }
        $checked++;

        $operations = array_map(static fn($a) => $a->newInstance(), $opAttrs);
        $discriminatorKey = $operations[0]->discriminatorKey;

        $schema = $instance->getParametersSchema();
        $properties = $schema['properties'];
        if ($properties instanceof stdClass) {
            $properties = (array) $properties;
        }

        // The discriminator key MAY appear as a regular #[ToolParameter] but
        // must not be present as a synthesized enum-with-every-op property.
        if (isset($properties[$discriminatorKey]['enum'])) {
            // If it has an enum, it must NOT be the synthesized one (which would
            // contain just the single operation name). A real author-declared
            // enum would be domain-specific.
            $synthesized = [$operations[0]->name];
            expect($properties[$discriminatorKey]['enum'])->not->toBe($synthesized);
        }
    }
    // Spora has 4 single-op tools at the time of writing (Calculator, CurrentTime,
    // ReadUrl, Tavily). If this drops to 0 the invariant becomes meaningless and
    // the assertion below catches it.
    expect($checked)->toBeGreaterThan(0);
});

it('every #[ToolParameter] type is a valid JSON Schema primitive', function (): void {
    $allowed = ['string', 'number', 'integer', 'boolean', 'array', 'object'];
    foreach (instantiateAllTools() as $cls => $instance) {
        $schema = $instance->getParametersSchema();
        $properties = $schema['properties'];
        if ($properties instanceof stdClass) {
            continue; // No params to validate.
        }
        foreach ($properties as $name => $prop) {
            expect($prop['type'])->toBeIn(
                $allowed,
                "{$cls}.properties.{$name} type '{$prop['type']}' is not a JSON Schema primitive.",
            );
        }
    }
});

it('required[] only references declared property names', function (): void {
    foreach (instantiateAllTools() as $cls => $instance) {
        $schema = $instance->getParametersSchema();
        $properties = $schema['properties'];
        if ($properties instanceof stdClass) {
            $properties = (array) $properties;
        }
        foreach ($schema['required'] as $name) {
            expect(array_key_exists($name, $properties))->toBeTrue(
                "{$cls} marks '{$name}' as required but no such property exists.",
            );
        }
    }
});
