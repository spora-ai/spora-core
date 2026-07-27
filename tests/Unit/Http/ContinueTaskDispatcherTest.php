<?php

declare(strict_types=1);

use Spora\Http\ContinueTaskDispatcher;
use Spora\Services\MediaArchive\MediaCapabilityMismatchException;
use Spora\Services\MediaArchive\TaskMediaCapabilityInterface;
use Spora\Services\TaskServiceInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Unit\Http\StubTaskService;

afterEach(function (): void {
    Mockery::close();
});

/**
 * @return ContinueTaskDispatcher
 */
function makeDispatcher(?TaskServiceInterface $tasks = null, ?TaskMediaCapabilityInterface $mediaCapability = null): ContinueTaskDispatcher
{
    return new ContinueTaskDispatcher(
        $tasks ?? new StubTaskService(),
        $mediaCapability ?? new Spora\Services\MediaArchive\TaskMediaCapabilityService(),
    );
}

describe('ContinueTaskDispatcher::handleContinue — success path', function (): void {
    test('returns 200 with the task payload when prompt is valid and task exists', function (): void {
        $dispatcher = makeDispatcher();
        $body = ['prompt' => 'continue me', 'additional_steps' => 5];

        $response = $dispatcher->handleContinue(1, 1, $body);

        expect($response)->toBeInstanceOf(JsonResponse::class);
        expect($response->getStatusCode())->toBe(Response::HTTP_OK);

        $payload = json_decode($response->getContent(), true);
        expect($payload)->toHaveKey('data.task');
        expect($payload['data']['task']['id'])->toBe(1);
        expect($payload['data']['task']['user_prompt'])->toBe('continue me');
    });

    test('passes parsed mediaIds through to the service', function (): void {
        $tasks = Mockery::mock(TaskServiceInterface::class);
        $tasks->shouldReceive('getTask')->once()->with(1, 1)->andReturn(['id' => 1, 'agent_id' => 7]);
        $tasks->shouldReceive('continueTask')
            ->once()
            ->with(1, 1, 'go', null, ['mid-image-1', 'mid-image-2'])
            ->andReturn(['id' => 1, 'agent_id' => 7, 'status' => 'PENDING']);

        $mediaCapability = Mockery::mock(TaskMediaCapabilityInterface::class);
        $mediaCapability->shouldReceive('parseMediaIds')->once()->with(['mid-image-1', 'mid-image-2'])->andReturn(['mid-image-1', 'mid-image-2']);
        $mediaCapability->shouldReceive('ensureMediaCapabilityCompatible')->once()->with(7, ['mid-image-1', 'mid-image-2']);

        $dispatcher = new ContinueTaskDispatcher($tasks, $mediaCapability);
        $response = $dispatcher->handleContinue(1, 1, [
            'prompt' => 'go',
            'media_ids' => ['mid-image-1', 'mid-image-2'],
        ]);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    });

    test('lets additional_steps=null pass through to the service', function (): void {
        $tasks = Mockery::mock(TaskServiceInterface::class);
        $tasks->shouldReceive('getTask')->once()->andReturn(['id' => 1, 'agent_id' => 7]);
        $tasks->shouldReceive('continueTask')
            ->once()
            ->with(1, 1, 'go', null, [])
            ->andReturn(['id' => 1, 'agent_id' => 7]);

        $mediaCapability = Mockery::mock(TaskMediaCapabilityInterface::class);
        $mediaCapability->shouldReceive('parseMediaIds')->andReturn([]);
        $mediaCapability->shouldReceive('ensureMediaCapabilityCompatible');

        $dispatcher = new ContinueTaskDispatcher($tasks, $mediaCapability);
        $response = $dispatcher->handleContinue(1, 1, ['prompt' => 'go']);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    });
});

describe('ContinueTaskDispatcher::handleContinue — body validation', function (): void {
    test('returns 422 VALIDATION_ERROR when prompt key is missing', function (): void {
        $dispatcher = makeDispatcher();

        $response = $dispatcher->handleContinue(1, 1, []);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
        $payload = json_decode($response->getContent(), true);
        expect($payload['error']['code'])->toBe('VALIDATION_ERROR');
        expect($payload['error']['message'])->toContain('prompt');
    });

    test('returns 422 when prompt is null', function (): void {
        $dispatcher = makeDispatcher();

        $response = $dispatcher->handleContinue(1, 1, ['prompt' => null]);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    });

    test('returns 422 when prompt is empty string', function (): void {
        $dispatcher = makeDispatcher();

        $response = $dispatcher->handleContinue(1, 1, ['prompt' => '']);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    });

    test('returns 422 when prompt is whitespace only', function (): void {
        $dispatcher = makeDispatcher();

        $response = $dispatcher->handleContinue(1, 1, ['prompt' => "   \t\n"]);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    });

    test('returns 422 when additional_steps is below 1', function (): void {
        $dispatcher = makeDispatcher();

        $response = $dispatcher->handleContinue(1, 1, [
            'prompt' => 'go',
            'additional_steps' => 0,
        ]);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
        $payload = json_decode($response->getContent(), true);
        expect($payload['error']['message'])->toContain('additional_steps');
    });

    test('returns 422 when additional_steps is above 100', function (): void {
        $dispatcher = makeDispatcher();

        $response = $dispatcher->handleContinue(1, 1, [
            'prompt' => 'go',
            'additional_steps' => 101,
        ]);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    });

    test('returns 422 when additional_steps is not an integer', function (): void {
        $dispatcher = makeDispatcher();

        $response = $dispatcher->handleContinue(1, 1, [
            'prompt' => 'go',
            'additional_steps' => 'five',
        ]);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    });

    test('does not call the service when validation fails', function (): void {
        $tasks = Mockery::mock(TaskServiceInterface::class);
        $tasks->shouldNotReceive('getTask');
        $tasks->shouldNotReceive('continueTask');

        $mediaCapability = Mockery::mock(TaskMediaCapabilityInterface::class);
        $mediaCapability->shouldNotReceive('parseMediaIds');
        $mediaCapability->shouldNotReceive('ensureMediaCapabilityCompatible');

        $dispatcher = new ContinueTaskDispatcher($tasks, $mediaCapability);
        $response = $dispatcher->handleContinue(1, 1, []);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    });
});

describe('ContinueTaskDispatcher::handleContinue — task lookup', function (): void {
    test('returns 404 NOT_FOUND when getTask returns null', function (): void {
        $tasks = Mockery::mock(TaskServiceInterface::class);
        $tasks->shouldReceive('getTask')->once()->with(999999, 1)->andReturn(null);
        $tasks->shouldNotReceive('continueTask');

        $mediaCapability = Mockery::mock(TaskMediaCapabilityInterface::class);
        $mediaCapability->shouldReceive('parseMediaIds')->andReturn([]);
        $mediaCapability->shouldNotReceive('ensureMediaCapabilityCompatible');

        $dispatcher = new ContinueTaskDispatcher($tasks, $mediaCapability);
        $response = $dispatcher->handleContinue(999999, 1, ['prompt' => 'go']);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
        $payload = json_decode($response->getContent(), true);
        expect($payload['error']['code'])->toBe('NOT_FOUND');
    });

    test('passes agent_id from getTask to ensureMediaCapabilityCompatible', function (): void {
        $tasks = Mockery::mock(TaskServiceInterface::class);
        $tasks->shouldReceive('getTask')->once()->andReturn(['id' => 1, 'agent_id' => 42]);
        $tasks->shouldReceive('continueTask')->once()->andReturn(['id' => 1, 'agent_id' => 42]);

        $mediaCapability = Mockery::mock(TaskMediaCapabilityInterface::class);
        $mediaCapability->shouldReceive('parseMediaIds')->andReturn([]);
        $mediaCapability->shouldReceive('ensureMediaCapabilityCompatible')->once()->with(42, []);

        $dispatcher = new ContinueTaskDispatcher($tasks, $mediaCapability);
        $response = $dispatcher->handleContinue(1, 1, ['prompt' => 'go']);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    });
});

describe('ContinueTaskDispatcher::handleContinue — domain failures', function (): void {
    test('returns 400 MEDIA_CAPABILITY_MISMATCH when the agent does not support images', function (): void {
        $mediaCapability = Mockery::mock(TaskMediaCapabilityInterface::class);
        $mediaCapability->shouldReceive('parseMediaIds')->andReturn(['img-1']);
        $mediaCapability->shouldReceive('ensureMediaCapabilityCompatible')
            ->once()
            ->andThrow(new MediaCapabilityMismatchException(
                "One or more attachments are images but the agent's LLM does not support image input.",
            ));

        $tasks = Mockery::mock(TaskServiceInterface::class);
        $tasks->shouldReceive('getTask')->once()->andReturn(['id' => 1, 'agent_id' => 5]);
        $tasks->shouldNotReceive('continueTask');

        $dispatcher = new ContinueTaskDispatcher($tasks, $mediaCapability);
        $response = $dispatcher->handleContinue(1, 1, [
            'prompt' => 'go',
            'media_ids' => ['img-1'],
        ]);

        expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
        $payload = json_decode($response->getContent(), true);
        expect($payload['error']['code'])->toBe('MEDIA_CAPABILITY_MISMATCH');
        expect($payload['error']['message'])->toContain('image');
    });

    test('returns 404 when continueTask throws InvalidArgumentException for "task not found"', function (): void {
        $tasks = Mockery::mock(TaskServiceInterface::class);
        $tasks->shouldReceive('getTask')->once()->andReturn(['id' => 1, 'agent_id' => 5]);
        $tasks->shouldReceive('continueTask')->once()->andThrow(new InvalidArgumentException('Task not found.'));

        $mediaCapability = Mockery::mock(TaskMediaCapabilityInterface::class);
        $mediaCapability->shouldReceive('parseMediaIds')->andReturn([]);
        $mediaCapability->shouldReceive('ensureMediaCapabilityCompatible');

        $dispatcher = new ContinueTaskDispatcher($tasks, $mediaCapability);
        $response = $dispatcher->handleContinue(1, 1, ['prompt' => 'go']);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
        $payload = json_decode($response->getContent(), true);
        expect($payload['error']['code'])->toBe('NOT_FOUND');
    });

    test('returns 409 INVALID_STATE when continueTask throws a non-404 InvalidArgumentException', function (): void {
        $tasks = Mockery::mock(TaskServiceInterface::class);
        $tasks->shouldReceive('getTask')->once()->andReturn(['id' => 1, 'agent_id' => 5]);
        $tasks->shouldReceive('continueTask')
            ->once()
            ->andThrow(new InvalidArgumentException('Task is already running.'));

        $mediaCapability = Mockery::mock(TaskMediaCapabilityInterface::class);
        $mediaCapability->shouldReceive('parseMediaIds')->andReturn([]);
        $mediaCapability->shouldReceive('ensureMediaCapabilityCompatible');

        $dispatcher = new ContinueTaskDispatcher($tasks, $mediaCapability);
        $response = $dispatcher->handleContinue(1, 1, ['prompt' => 'go']);

        expect($response->getStatusCode())->toBe(Response::HTTP_CONFLICT);
        $payload = json_decode($response->getContent(), true);
        expect($payload['error']['code'])->toBe('INVALID_STATE');
        expect($payload['error']['message'])->toBe('Task is already running.');
    });

    test('propagates unexpected RuntimeException unchanged (not mapped to JSON)', function (): void {
        $tasks = Mockery::mock(TaskServiceInterface::class);
        $tasks->shouldReceive('getTask')->once()->andReturn(['id' => 1, 'agent_id' => 5]);
        $tasks->shouldReceive('continueTask')->once()->andThrow(new RuntimeException('database went away'));

        $mediaCapability = Mockery::mock(TaskMediaCapabilityInterface::class);
        $mediaCapability->shouldReceive('parseMediaIds')->andReturn([]);
        $mediaCapability->shouldReceive('ensureMediaCapabilityCompatible');

        $dispatcher = new ContinueTaskDispatcher($tasks, $mediaCapability);

        expect(static fn() => $dispatcher->handleContinue(1, 1, ['prompt' => 'go']))
            ->toThrow(RuntimeException::class, 'database went away');
    });
});

describe('ContinueTaskDispatcher — input shape', function (): void {
    test('accepts a body with only the prompt key', function (): void {
        $dispatcher = makeDispatcher();

        $response = $dispatcher->handleContinue(1, 1, ['prompt' => 'hello']);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    });

    test('accepts a body with arbitrary extra keys (forward-compatibility)', function (): void {
        $dispatcher = makeDispatcher();

        $response = $dispatcher->handleContinue(1, 1, [
            'prompt' => 'hello',
            'additional_steps' => 3,
            'media_ids' => [],
            'future_feature_flag' => true,
        ]);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    });

    test('treats additional_steps absent as null (not zero)', function (): void {
        $tasks = Mockery::mock(TaskServiceInterface::class);
        $tasks->shouldReceive('getTask')->once()->andReturn(['id' => 1, 'agent_id' => 5]);
        $tasks->shouldReceive('continueTask')
            ->once()
            ->with(1, 1, 'go', null, [])
            ->andReturn(['id' => 1, 'agent_id' => 5]);

        $mediaCapability = Mockery::mock(TaskMediaCapabilityInterface::class);
        $mediaCapability->shouldReceive('parseMediaIds')->andReturn([]);
        $mediaCapability->shouldReceive('ensureMediaCapabilityCompatible');

        $dispatcher = new ContinueTaskDispatcher($tasks, $mediaCapability);
        $response = $dispatcher->handleContinue(1, 1, ['prompt' => 'go']);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    });
});
