<?php

declare(strict_types=1);

use Spora\Models\AgentMemory;
use Spora\Tools\ScratchpadTool;

it('creates and reads memory', function () {
    $tool = new ScratchpadTool();
    [$agentId] = seedAgent();

    // Write
    $writeResult = $tool->execute(['action' => 'write', 'key' => 'fav_color', 'value' => 'blue'], $agentId);
    expect($writeResult->success)->toBeTrue()
        ->and($writeResult->content)->toContain('Successfully saved memory');

    // Verify DB
    $memory = AgentMemory::where('agent_id', $agentId)->where('key', 'fav_color')->first();
    expect($memory)->not->toBeNull()
        ->and($memory->value)->toBe('blue');

    // Read
    $readResult = $tool->execute(['action' => 'read', 'key' => 'fav_color'], $agentId);
    expect($readResult->success)->toBeTrue()
        ->and($readResult->content)->toContain('blue');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('deletes memory', function () {
    $tool = new ScratchpadTool();
    [$agentId] = seedAgent();

    $tool->execute(['action' => 'write', 'key' => 'temp', 'value' => '123'], $agentId);
    
    $deleteResult = $tool->execute(['action' => 'delete', 'key' => 'temp'], $agentId);
    expect($deleteResult->success)->toBeTrue();

    $memory = AgentMemory::where('agent_id', $agentId)->where('key', 'temp')->first();
    expect($memory)->toBeNull();
})->afterEach(fn() => Spora\Core\Database::resetBootState());

it('returns error on missing key', function () {
    $tool = new ScratchpadTool();
    $result = $tool->execute(['action' => 'read'], 99);
    
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Key is required');
});
