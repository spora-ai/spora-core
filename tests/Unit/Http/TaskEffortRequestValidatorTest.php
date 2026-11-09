<?php

declare(strict_types=1);

use Spora\Http\Exceptions\EffortValidationException;
use Spora\Http\TaskEffortRequestValidator;

/**
 * The three states of the `effort` field.
 *
 * Absent, empty and a level are three different messages to the framework:
 * the first leaves the choice to the configuration, the second asks for the
 * model default, and the third names one. Collapsing any two of them would
 * silently change what the model is asked to do.
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

it('refuses a level nobody knows', function (): void {
    TaskEffortRequestValidator::parse(['effort' => 'turbo']);
})->throws(EffortValidationException::class);

it('refuses a value that is not a string', function (): void {
    TaskEffortRequestValidator::parse(['effort' => 3]);
})->throws(EffortValidationException::class);

it('answers an unusable effort with a 422', function (): void {
    try {
        TaskEffortRequestValidator::parse(['effort' => 'turbo']);
    } catch (EffortValidationException $e) {
        $response = TaskEffortRequestValidator::errorResponse($e);

        expect($response->getStatusCode())->toBe(422);

        $payload = json_decode($response->getContent(), true);
        expect($payload['error']['code'])->toBe('INVALID_EFFORT');

        return;
    }

    $this->fail('Expected an EffortValidationException.');
});
