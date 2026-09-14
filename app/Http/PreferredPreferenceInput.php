<?php

declare(strict_types=1);

namespace Spora\Http;

/**
 * Triple of validated PUT body fields for
 * {@see SpeechProviderConfigController::setPreferred()} — kept as a
 * tiny read-only DTO so the validation helper returns one value (or
 * one JsonResponse) rather than three separate union-returning
 * helpers, each of which the caller has to type-check before use.
 */
final readonly class PreferredPreferenceInput
{
    public function __construct(
        public string $scope,
        public ?int $groupId,
        public ?int $configId,
    ) {}
}
