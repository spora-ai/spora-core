<?php

declare(strict_types=1);

use Spora\Models\LLMDriverConfiguration;
use Spora\Models\Principal;
use Spora\Models\PrincipalPreference;

const PRINCIPAL_PREFERENCE_TEST_PASSWORD = 'Password1!';

it('uses the principal_preferences table', function (): void {
    $pref = new PrincipalPreference();

    expect($pref->getTable())->toBe('principal_preferences');
});

it('allows mass assignment of principal_id and preferred_llm_config_id', function (): void {
    $userId = bootAuthLayer()->register('pref@example.com', PRINCIPAL_PREFERENCE_TEST_PASSWORD, 'Pref');
    $principalId = createUserPrincipalPublic($userId);

    $pref = PrincipalPreference::create([
        'principal_id'           => $principalId,
        'preferred_llm_config_id' => null,
    ]);

    expect((int) $pref->principal_id)->toBe($principalId)
        ->and($pref->preferred_llm_config_id)->toBeNull();
});

it('belongs to a principal and a preferred LLM driver configuration', function (): void {
    $userId = bootAuthLayer()->register('pref-rel@example.com', PRINCIPAL_PREFERENCE_TEST_PASSWORD, 'PrefRel');
    $principalId = createUserPrincipalPublic($userId);

    $llm = LLMDriverConfiguration::create([
        'principal_id' => $principalId,
        'name'         => 'Default',
        'driver_class' => 'Spora\Drivers\MockDriver',
        'is_default'   => true,
        'is_global'    => false,
    ]);

    $pref = PrincipalPreference::create([
        'principal_id'            => $principalId,
        'preferred_llm_config_id' => $llm->id,
    ]);

    expect($pref->principal)->toBeInstanceOf(Principal::class)
        ->and((int) $pref->principal->getKey())->toBe($principalId)
        ->and($pref->preferredLlmConfig)->toBeInstanceOf(LLMDriverConfiguration::class)
        ->and((int) $pref->preferredLlmConfig->getKey())->toBe($llm->id);
});
