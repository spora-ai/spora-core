<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Carbon\Carbon as BaseCarbon;
use Illuminate\Support\Carbon;
use Spora\Models\ToolCall;
use Spora\Services\ToolCallSerializer;

/**
 * Build a ToolCall model without booting Eloquent — we only read attributes,
 * never persist. setRawAttributes() with the same shape Eloquent would have
 * cast is enough for serializer testing. proposed_arguments / approved_arguments
 * are stored as JSON strings on the underlying row (Eloquent decodes on access).
 */
function makeToolCall(array $overrides = []): ToolCall
{
    $tc = new ToolCall();
    $defaults = [
        'id'                    => 42,
        'provider_call_id'      => 'pc_abc',
        'tool_name'             => 'fixture',
        'tool_class'            => ToolCallSerializerFixtureTool::class,
        'tool_type'             => 'output',
        'status'                => 'PENDING_APPROVAL',
        'operation'             => 'run',
        'operation_description' => 'Run',
        'human_description'     => 'Run it',
        'proposed_arguments'    => json_encode(['q' => 'hello']),
        'approved_arguments'    => null,
        'result_content'        => null,
        'executed_at'           => null,
    ];
    $tc->setRawAttributes(array_merge($defaults, $overrides));
    // executed_at cast needs a real Illuminate Carbon when not null. The
    // BaseCarbon import keeps the typecheck happy when callers pass a string.
    if (isset($overrides['executed_at']) && !$overrides['executed_at'] instanceof BaseCarbon) {
        $tc->executed_at = Carbon::parse($overrides['executed_at']);
    }
    return $tc;
}

it('emits parameter_schema derived from the live tool instance', function (): void {
    $tool = new ToolCallSerializerFixtureTool();
    $serializer = new ToolCallSerializer([$tool]);

    $payload = $serializer->toArray(makeToolCall());

    expect($payload['parameter_schema'])->not->toBeNull()
        ->and(array_keys($payload['parameter_schema']['properties']))->toBe(['action', 'q'])
        ->and($payload['parameter_schema']['properties']['action']['enum'])->toBe(['run', 'stop']);
});

it('preserves all existing tool_call fields', function (): void {
    $serializer = new ToolCallSerializer([new ToolCallSerializerFixtureTool()]);

    $payload = $serializer->toArray(makeToolCall());

    expect($payload)->toHaveKeys([
        'id',
        'provider_call_id',
        'tool_name',
        'tool_type',
        'status',
        'proposed_arguments',
        'approved_arguments',
        'human_description',
        'operation',
        'operation_description',
        'result_content',
        'executed_at',
        'parameter_schema',
    ]);
});

it('omits parameter_schema when the tool class cannot be resolved', function (): void {
    // No tool instances registered; the class string also isn't autoloadable.
    $serializer = new ToolCallSerializer([]);

    $payload = $serializer->toArray(makeToolCall(['tool_class' => 'Nonexistent\\ClassFromUninstalledPlugin']));

    expect($payload)->not->toHaveKey('parameter_schema')
        ->and($payload['tool_name'])->toBe('fixture');
});

it('omits parameter_schema when tool_class is null or empty', function (): void {
    $serializer = new ToolCallSerializer([new ToolCallSerializerFixtureTool()]);

    expect($serializer->toArray(makeToolCall(['tool_class' => null])))->not->toHaveKey('parameter_schema')
        ->and($serializer->toArray(makeToolCall(['tool_class' => ''])))->not->toHaveKey('parameter_schema');
});

it('falls back to reflection instantiation for autoloadable no-arg tool classes', function (): void {
    // Empty toolInstances list but the class exists and has a no-arg ctor.
    $serializer = new ToolCallSerializer([]);

    $payload = $serializer->toArray(makeToolCall(['tool_class' => ToolCallSerializerFixtureTool::class]));

    expect($payload)->toHaveKey('parameter_schema')
        ->and(array_keys($payload['parameter_schema']['properties']))->toBe(['action', 'q']);
});

it('parameter_schema property order matches declaration order', function (): void {
    $serializer = new ToolCallSerializer([new ToolCallSerializerFixtureTool()]);
    $payload = $serializer->toArray(makeToolCall());

    // Schema declared: action (synthesized), q. UI must render in that order.
    expect(array_keys($payload['parameter_schema']['properties']))->toBe(['action', 'q']);
});
