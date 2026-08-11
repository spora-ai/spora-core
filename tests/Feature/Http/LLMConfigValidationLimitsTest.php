<?php

declare(strict_types=1);

use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Services\LLMConfigService;
use Spora\Services\LlmConfigValidator;
use Symfony\Component\HttpFoundation\Response;

/**
 * B5 — `validateLimits` rejects unbounded `context_window` /
 * `max_tokens_output`. Absent keys pass (the "leave unchanged" semantics);
 * present-but-bad values get a 422 with the generic bound message.
 *
 * Pairs with the new `validateUpdateBody` gap-fix on PUT /llm-configs/{id}.
 */
function makeLimitsValidator(): LlmConfigValidator
{
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new Spora\Core\SecurityManager($key);
    $service = new LLMConfigService($security, [
        OpenAICompatibleDriver::class,
        AnthropicCompatibleDriver::class,
    ]);
    return new LlmConfigValidator($service);
}

// validateLimits — happy paths

test('validateLimits passes when both keys are absent', function (): void {
    $validator = makeLimitsValidator();
    expect($validator->validateLimits([]))->toBeNull();
});

test('validateLimits accepts max boundary 1_000_000', function (): void {
    $validator = makeLimitsValidator();
    expect($validator->validateLimits(['context_window' => 1_000_000]))->toBeNull();
    expect($validator->validateLimits(['max_tokens_output' => 1_000_000]))->toBeNull();
});

test('validateLimits accepts realistic provider ceilings', function (): void {
    $validator = makeLimitsValidator();
    foreach ([1, 4096, 16384, 64_000, 100_000, 128_000, 200_000] as $value) {
        expect($validator->validateLimits(['context_window' => $value]))->toBeNull();
        expect($validator->validateLimits(['max_tokens_output' => $value]))->toBeNull();
    }
});

test('validateLimits accepts one field without disturbing the other', function (): void {
    $validator = makeLimitsValidator();
    expect($validator->validateLimits(['max_tokens_output' => 16_384]))->toBeNull();
});

// validateLimits — rejections

test('validateLimits rejects zero and negative values', function (): void {
    $validator = makeLimitsValidator();
    foreach ([0, -1, -100_000] as $value) {
        expect($validator->validateLimits(['context_window' => $value]))->toBeInstanceOf(Symfony\Component\HttpFoundation\JsonResponse::class);
        expect($validator->validateLimits(['max_tokens_output' => $value]))->toBeInstanceOf(Symfony\Component\HttpFoundation\JsonResponse::class);
    }
});

test('validateLimits rejects values above the 1_000_000 cap', function (): void {
    $validator = makeLimitsValidator();
    foreach ([1_000_001, 9_999_999, \PHP_INT_MAX] as $value) {
        $response = $validator->validateLimits(['max_tokens_output' => $value]);
        expect($response)->toBeInstanceOf(Symfony\Component\HttpFoundation\JsonResponse::class);
        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    }
});

test('validateLimits rejects non-integer numerics like 1.5 and "200000abc"', function (): void {
    $validator = makeLimitsValidator();
    foreach ([1.5, '1.5', '200000abc', 'abc', '12 34'] as $value) {
        expect($validator->validateLimits(['max_tokens_output' => $value]))->toBeInstanceOf(Symfony\Component\HttpFoundation\JsonResponse::class);
    }
});

test('validateLimits rejects null and empty string', function (): void {
    $validator = makeLimitsValidator();
    // "null" is not numeric — must fail. The validator distinguishes absent
    // (`!array_key_exists`) from explicitly null.
    expect($validator->validateLimits(['max_tokens_output' => null]))->toBeInstanceOf(Symfony\Component\HttpFoundation\JsonResponse::class);
    expect($validator->validateLimits(['max_tokens_output' => '']))->toBeInstanceOf(Symfony\Component\HttpFoundation\JsonResponse::class);
});

test('validateLimits rejects boolean true (defensive against JSON quirks)', function (): void {
    $validator = makeLimitsValidator();
    expect($validator->validateLimits(['max_tokens_output' => true]))->toBeInstanceOf(Symfony\Component\HttpFoundation\JsonResponse::class);
});

test('validateLimits returns a 422 with the generic bound message', function (): void {
    $validator = makeLimitsValidator();
    $response = $validator->validateLimits(['max_tokens_output' => 9_999_999]);
    expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_ERROR');
    // Message intentionally doesn't name the exact cap to keep the UI generic.
    expect($body['error']['message'])->toContain('positive integer');
});

// validateUpdateBody — PUT parity fix

test('validateUpdateBody rejects bad max_tokens_output on PUT', function (): void {
    $validator = makeLimitsValidator();
    $response = $validator->validateUpdateBody(['max_tokens_output' => 9_999_999]);
    expect($response)->toBeInstanceOf(Symfony\Component\HttpFoundation\JsonResponse::class);
    expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
});

test('validateUpdateBody rejects empty name on PUT', function (): void {
    $validator = makeLimitsValidator();
    $response = $validator->validateUpdateBody(['name' => '   ']);
    expect($response)->toBeInstanceOf(Symfony\Component\HttpFoundation\JsonResponse::class);
    expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
});

test('validateUpdateBody accepts an empty body (all keys absent = no-op)', function (): void {
    $validator = makeLimitsValidator();
    expect($validator->validateUpdateBody([]))->toBeNull();
});

test('validateUpdateBody accepts a partial update with valid limits', function (): void {
    $validator = makeLimitsValidator();
    expect($validator->validateUpdateBody(['max_tokens_output' => 32_000]))->toBeNull();
    expect($validator->validateUpdateBody(['context_window' => 200_000, 'name' => 'Renamed']))->toBeNull();
});

// validateStoreBody — limit wiring

test('validateStoreBody rejects bad limits even when name and driver are valid', function (): void {
    $validator = makeLimitsValidator();
    $response = $validator->validateStoreBody([
        'name'         => 'Test',
        'driver_class' => OpenAICompatibleDriver::class,
        'max_tokens_output' => 9_999_999,
    ]);
    expect($response)->toBeInstanceOf(Symfony\Component\HttpFoundation\JsonResponse::class);
    expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
});

test('validateStoreBody accepts valid limits alongside name and driver', function (): void {
    $validator = makeLimitsValidator();
    expect($validator->validateStoreBody([
        'name'         => 'Test',
        'driver_class' => OpenAICompatibleDriver::class,
        'context_window'     => 200_000,
        'max_tokens_output'  => 32_000,
    ]))->toBeNull();
});
