<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Delight\Auth\EmailNotVerifiedException;
use Delight\Auth\InvalidEmailException;
use Delight\Auth\NotLoggedInException;
use Delight\Auth\TooManyRequestsException;
use Delight\Auth\UserAlreadyExistsException;
use Spora\Services\AuthValidator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    $this->validator = new AuthValidator();
});

test('mapEmailChangeRequestError maps TooManyRequestsException to 429', function (): void {
    $response = $this->validator->mapEmailChangeRequestError(new TooManyRequestsException());

    expect($response)->toBeInstanceOf(JsonResponse::class);
    expect($response->getStatusCode())->toBe(Response::HTTP_TOO_MANY_REQUESTS);

    $body = json_decode((string) $response->getContent(), true);
    expect($body['error']['code'])->toBe('TOO_MANY_REQUESTS');
});

test('mapEmailChangeRequestError preserves existing mapping arms', function (): void {
    expect($this->validator->mapEmailChangeRequestError(new InvalidEmailException())->getStatusCode())
        ->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    expect($this->validator->mapEmailChangeRequestError(new UserAlreadyExistsException())->getStatusCode())
        ->toBe(Response::HTTP_CONFLICT);
    expect($this->validator->mapEmailChangeRequestError(new EmailNotVerifiedException())->getStatusCode())
        ->toBe(Response::HTTP_FORBIDDEN);
    expect($this->validator->mapEmailChangeRequestError(new NotLoggedInException())->getStatusCode())
        ->toBe(Response::HTTP_UNAUTHORIZED);
});
