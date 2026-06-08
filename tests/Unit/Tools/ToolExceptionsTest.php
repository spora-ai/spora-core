<?php

declare(strict_types=1);

use Spora\Services\ToolConfigService;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Exceptions\ToolHttpErrorException;
use Spora\Tools\Exceptions\ToolOperationMissingException;
use Spora\Tools\Exceptions\ToolParameterSchemaException;
use Spora\Tools\Schema\ToolParameterSchemaBuilder;
use Spora\Tools\SerperSearchTool;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

it('ToolHttpErrorException extends RuntimeException', function (): void {
    $ex = new ToolHttpErrorException('boom');
    expect($ex)->toBeInstanceOf(RuntimeException::class)
        ->and($ex->getMessage())->toBe('boom');
});

it('ToolOperationMissingException extends RuntimeException', function (): void {
    $ex = new ToolOperationMissingException('missing');
    expect($ex)->toBeInstanceOf(RuntimeException::class)
        ->and($ex->getMessage())->toBe('missing');
});

it('ToolParameterSchemaException extends RuntimeException', function (): void {
    $ex = new ToolParameterSchemaException('bad schema');
    expect($ex)->toBeInstanceOf(RuntimeException::class)
        ->and($ex->getMessage())->toBe('bad schema');
});

it('SerperSearchTool throws ToolHttpErrorException on non-2xx response', function (): void {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['core.serper.api_key' => 'serp_123']);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->allows('getStatusCode')->andReturn(500);
    $response->allows('getContent')->andReturn('{"message":"upstream error"}');

    $client->allows('request')->andReturn($response);

    $tool = new SerperSearchTool($config, $client);

    $ref = new ReflectionMethod($tool, 'makeSerperRequest');

    expect(fn() => $ref->invoke($tool, 'search', ['q' => 'apple'], ['core.serper.api_key' => 'serp_123']))
        ->toThrow(ToolHttpErrorException::class, 'HTTP 500: {"message":"upstream error"}');
});

it('HasOperations trait throws ToolOperationMissingException when no #[ToolOperation] is declared', function (): void {
    $instance = new HasOperationsFixtureWithoutOps();
    expect(fn() => $instance->getOperationName([]))
        ->toThrow(
            ToolOperationMissingException::class,
            'getOperationName() called on a tool that has no #[ToolOperation] attributes. '
            . 'Either add #[ToolOperation] declarations or do not use the HasOperations trait.',
        );
});

it('ToolParameterSchemaBuilder throws ToolParameterSchemaException on discriminator collision', function (): void {
    $cls = new
        #[ToolOperation(name: 'list_events', description: 'List events')]
        #[ToolOperation(name: 'create_event', description: 'Create event')]
        #[ToolParameter(name: 'action', type: 'string', description: 'collides with discriminator', required: true)] class {};

    expect(fn() => ToolParameterSchemaBuilder::build($cls))
        ->toThrow(ToolParameterSchemaException::class);
});

/** Fixture that uses HasOperations without any #[ToolOperation] attributes. */
final class HasOperationsFixtureWithoutOps
{
    use Spora\Tools\Traits\HasOperations;
}
