<?php

declare(strict_types=1);

use Spora\Http\Exceptions\EffortValidationException;
use Spora\Http\TaskEffortRequestValidator;

/**
 * The three states of the `effort` field.
 *
 * Absent, empty and a level are three different messages to the framework:
 * the first leaves the choice to the configuration, the second asks for
 * the model default, and the third names one. Collapsing any two of them
 * would silently change what the model is asked to do.
 *
 * The parser checks the shape only. Which levels exist is a property of
 * the agent's LLM configuration and not a fixed vocabulary, so a name
 * nobody in the canonical scale knows still parses here — membership is
 * {@see \Spora\Services\EffortService::assertLevel()}'s job.
 */
it('reads an absent key as no effort at all', function (): void {
    expect(TaskEffortRequestValidator::parse([]))->toBeNull();
});

it('reads a null value as no effort at all', function (): void {
    expect(TaskEffortRequestValidator::parse(['effort' => null]))->toBeNull();
});

it('reads an empty value as the model default', function (): void {
    expect(TaskEffortRequestValidator::parse(['effort' => '']))->toBe('');
});

it('reads a level as that level', function (): void {
    expect(TaskEffortRequestValidator::parse(['effort' => 'xhigh']))->toBe('xhigh');
});

it('reads a name the canonical vocabulary does not know', function (): void {
    expect(TaskEffortRequestValidator::parse(['effort' => 'turbo']))->toBe('turbo')
        ->and(TaskEffortRequestValidator::parse(['effort' => 'default']))->toBe('default');
});

it('refuses a value that is not a string', function (): void {
    TaskEffortRequestValidator::parse(['effort' => 3]);
})->throws(EffortValidationException::class);

it('answers an unusable effort with a 422', function (): void {
    try {
        TaskEffortRequestValidator::parse(['effort' => 3]);
    } catch (EffortValidationException $e) {
        expect($e->errorCode())->toBe('INVALID_EFFORT');

        $response = TaskEffortRequestValidator::errorResponse($e);

        expect($response->getStatusCode())->toBe(422);

        $payload = json_decode($response->getContent(), true);
        expect($payload['error']['code'])->toBe('INVALID_EFFORT');

        return;
    }

    $this->fail('Expected an EffortValidationException.');
});
