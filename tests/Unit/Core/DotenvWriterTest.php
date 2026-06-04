<?php

declare(strict_types=1);

use Spora\Core\DotenvWriter;

function makeTempEnvPath(): string
{
    return sys_get_temp_dir() . '/spora-dotenv-' . uniqid() . '.env';
}

function readEnv(string $path): string
{
    return file_get_contents($path);
}

afterEach(function (): void {
    // GC: clean up any leftover tmp files matching our prefix
    foreach (glob(sys_get_temp_dir() . '/spora-dotenv-*.env') ?: [] as $file) {
        @unlink($file);
    }
});

test('set() appends a new key to a missing file', function (): void {
    $path = makeTempEnvPath();

    DotenvWriter::set('FOO', 'bar', $path);

    expect(readEnv($path))->toContain('FOO=bar');
});

test('set() appends a new key to an existing file when key is missing', function (): void {
    $path = makeTempEnvPath();
    file_put_contents($path, "EXISTING=value\n");

    DotenvWriter::set('NEW_KEY', 'new_value', $path);

    $content = readEnv($path);
    expect($content)->toContain('EXISTING=value');
    expect($content)->toContain('NEW_KEY=new_value');
});

test('set() updates an existing key in place', function (): void {
    $path = makeTempEnvPath();
    file_put_contents($path, "# comment\nFOO=old\n\nBAR=keep\n");

    DotenvWriter::set('FOO', 'new', $path);

    $content = readEnv($path);
    expect($content)->toContain("# comment\n");
    expect($content)->toContain('FOO=new');
    expect($content)->not->toContain('FOO=old');
    expect($content)->toContain('BAR=keep');
});

test('set() handles export FOO=... lines as matches', function (): void {
    $path = makeTempEnvPath();
    file_put_contents($path, "export FOO=old\n");

    DotenvWriter::set('FOO', 'new', $path);

    $content = readEnv($path);
    expect($content)->toContain('FOO=new');
    expect($content)->not->toContain('FOO=old');
});

test('set() wraps values containing spaces in double quotes', function (): void {
    $path = makeTempEnvPath();

    DotenvWriter::set('FOO', 'has spaces', $path);

    $content = readEnv($path);
    expect($content)->toContain('FOO="has spaces"');
});

test('set() wraps values containing hash character in double quotes', function (): void {
    $path = makeTempEnvPath();

    DotenvWriter::set('FOO', 'value#with-hash', $path);

    $content = readEnv($path);
    expect($content)->toContain('FOO="value#with-hash"');
});

test('set() wraps values containing exclamation mark in double quotes', function (): void {
    $path = makeTempEnvPath();

    DotenvWriter::set('FOO', 'value!bang', $path);

    $content = readEnv($path);
    expect($content)->toContain('FOO="value!bang"');
});

test('set() does not double-quote simple words', function (): void {
    $path = makeTempEnvPath();

    DotenvWriter::set('FOO', 'simple', $path);

    $content = readEnv($path);
    expect($content)->toContain("FOO=simple\n");
    expect($content)->not->toContain('FOO="simple"');
});

test('sets() appends multiple keys to an empty file', function (): void {
    $path = makeTempEnvPath();

    DotenvWriter::sets([
        'KEY_ONE' => 'one',
        'KEY_TWO' => 'two',
    ], $path);

    $content = readEnv($path);
    expect($content)->toContain('KEY_ONE=one');
    expect($content)->toContain('KEY_TWO=two');
});

test('sets() updates multiple existing keys', function (): void {
    $path = makeTempEnvPath();
    file_put_contents($path, "KEY_ONE=old_one\nKEY_TWO=old_two\n");

    DotenvWriter::sets([
        'KEY_ONE' => 'new_one',
        'KEY_TWO' => 'new_two',
    ], $path);

    $content = readEnv($path);
    expect($content)->toContain('KEY_ONE=new_one');
    expect($content)->toContain('KEY_TWO=new_two');
    expect($content)->not->toContain('old_one');
    expect($content)->not->toContain('old_two');
});

test('sets() updates existing and appends new in one call', function (): void {
    $path = makeTempEnvPath();
    file_put_contents($path, "OLD=stale\n");

    DotenvWriter::sets([
        'OLD' => 'updated',
        'NEW' => 'brand_new',
    ], $path);

    $content = readEnv($path);
    expect($content)->toContain('OLD=updated');
    expect($content)->toContain('NEW=brand_new');
});

test('sets() creates a file when path does not exist', function (): void {
    $path = makeTempEnvPath();

    expect(file_exists($path))->toBeFalse();
    DotenvWriter::sets(['FOO' => 'bar'], $path);
    expect(file_exists($path))->toBeTrue();
});
