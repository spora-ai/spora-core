<?php

declare(strict_types=1);

use Spora\Services\ToolConfigSchemaInspector;
use Spora\Tools\ToolSettingSchema;
use Tests\Unit\Tools\ToolSettingSchemaTestChild;
use Tests\Unit\Tools\ToolSettingSchemaTestGrandparent;
use Tests\Unit\Tools\ToolSettingSchemaTestParent;

/**
 * The inheritance order of `#[ToolSetting]` declarations decides two
 * security properties: whether a credential is treated as a password
 * (encrypted at rest, masked on the way out) and whether a setting is
 * disclosed to the model.
 *
 * Both are decided by the *nearest* declaration. A grandparent's stale
 * declaration must not replace the parent's — otherwise a `password` key
 * loses its classification and a private setting loses its disclosure
 * restriction.
 *
 * @return array<string, Spora\Tools\Attributes\ToolSetting>
 */
function toolSettingSchemaByKey(string $class): array
{
    $byKey = [];
    foreach (ToolSettingSchema::collect($class) as $setting) {
        $byKey[$setting->key] = $setting;
    }

    return $byKey;
}

test('the nearest declaration wins over a more distant ancestor', function (): void {
    $byKey = toolSettingSchemaByKey(ToolSettingSchemaTestChild::class);

    expect($byKey['api_key']->type)->toBe('password')
        ->and($byKey['private_note']->exposeToLlm)->toBeFalse();
});

test('the parent declaration wins over the grandparent it inherits from', function (): void {
    $byKey = toolSettingSchemaByKey(ToolSettingSchemaTestParent::class);

    expect($byKey['api_key']->type)->toBe('password')
        ->and($byKey['private_note']->exposeToLlm)->toBeFalse();
});

test('the concrete class declaration wins over every ancestor', function (): void {
    $byKey = toolSettingSchemaByKey(ToolSettingSchemaTestChild::class);

    expect($byKey['endpoint']->label)->toBe('Endpoint');
});

test('a credential keeps its password classification when a grandparent declares it as text', function (): void {
    $inspector = new ToolConfigSchemaInspector();

    expect($inspector->getPasswordKeys(ToolSettingSchemaTestChild::class))->toContain('api_key')
        ->and($inspector->getPasswordKeys(ToolSettingSchemaTestGrandparent::class))->not->toContain('api_key');
});

test('a masked credential is not disclosed through the API projection', function (): void {
    $inspector = new ToolConfigSchemaInspector();

    $masked = $inspector->maskForApi(
        ['api_key' => 'super-secret', 'private_note' => 'internal'],
        ToolSettingSchemaTestChild::class,
    );

    expect($masked['api_key'])->toBe('***');
});

test('a setting the parent withheld from the model stays out of the LLM projection', function (): void {
    $inspector = new ToolConfigSchemaInspector();

    $projected = $inspector->getLlmToolSettings(
        ToolSettingSchemaTestChild::class,
        ['private_note' => 'internal', 'endpoint' => 'https://example.test'],
        null,
        1,
        null,
    );

    expect($projected)->not->toHaveKey('private_note');
});
