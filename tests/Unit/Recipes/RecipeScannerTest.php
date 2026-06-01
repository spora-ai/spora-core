<?php

declare(strict_types=1);

use Spora\Recipes\RecipeScanner;

const FIXTURE_RECIPES = BASE_PATH . '/tests/Fixtures/recipes';

function makeScanner(array $dirs = []): RecipeScanner
{
    return new RecipeScanner($dirs ?: [FIXTURE_RECIPES]);
}

test('scan() returns recipes from a JSON file', function (): void {
    $recipes = makeScanner()->scan();
    $ids     = array_column($recipes, 'id');

    expect($ids)->toContain('general_assistant');
});

test('scan() returns recipes from a YAML file', function (): void {
    $recipes = makeScanner()->scan();
    $ids     = array_column($recipes, 'id');

    expect($ids)->toContain('research_agent');
});

test('scan() skips files with invalid JSON', function (): void {
    $recipes = makeScanner()->scan();
    $files   = array_column($recipes, 'filename');

    expect($files)->not()->toContain('broken.json');
});

test('scan() skips files missing required fields', function (): void {
    $recipes = makeScanner()->scan();
    $files   = array_column($recipes, 'filename');

    expect($files)->not()->toContain('missing_fields.yaml');
});

test('scan() result contains all required keys', function (): void {
    $recipes = makeScanner()->scan();
    $first   = collect($recipes)->firstWhere('id', 'general_assistant');

    expect($first)->toHaveKeys(['id', 'name', 'description', 'filename']);
    expect($first['filename'])->toBe('general_assistant.json');
});

test('scan() returns only valid recipes — broken and incomplete files excluded', function (): void {
    $recipes = makeScanner()->scan();

    // fixture dir has 4 files: 2 valid, 1 broken JSON, 1 missing fields
    expect(count($recipes))->toBe(2);
});

test('scan() with a non-existent directory returns empty array', function (): void {
    $recipes = makeScanner(['/tmp/spora_does_not_exist_' . uniqid()])->scan();

    expect($recipes)->toBe([]);
});

test('scan() with no directories returns empty array', function (): void {
    $recipes = (new RecipeScanner([]))->scan();

    expect($recipes)->toBe([]);
});

test('scan() merges results from multiple directories', function (): void {
    // Create a second temp dir with one recipe
    $tmpDir = sys_get_temp_dir() . '/spora_scan_test_' . uniqid();
    mkdir($tmpDir);
    file_put_contents($tmpDir . '/extra.json', json_encode([
        'id'          => 'extra_recipe',
        'name'        => 'Extra',
        'description' => 'Extra recipe from second dir.',
    ]));

    try {
        $recipes = makeScanner([FIXTURE_RECIPES, $tmpDir])->scan();
        $ids     = array_column($recipes, 'id');

        expect($ids)->toContain('general_assistant');
        expect($ids)->toContain('research_agent');
        expect($ids)->toContain('extra_recipe');
    } finally {
        unlink($tmpDir . '/extra.json');
        rmdir($tmpDir);
    }
});
