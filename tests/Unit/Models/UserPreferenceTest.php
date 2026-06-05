<?php

declare(strict_types=1);

use Spora\Models\LLMDriverConfiguration;

const USER_PREFERENCE_TEST_PASSWORD = 'Password1!';
use Spora\Models\User;
use Spora\Models\UserPreference;

it('uses the user_preferences table', function (): void {
    $pref = new UserPreference();

    expect($pref->getTable())->toBe('user_preferences');
});

it('allows mass assignment of user_id and preferred_llm_config_id', function (): void {
    $userId = bootAuthLayer()->register('pref@example.com', USER_PREFERENCE_TEST_PASSWORD, 'Pref');

    $pref = UserPreference::create([
        'user_id'                 => $userId,
        'preferred_llm_config_id' => null,
    ]);

    expect($pref->user_id)->toBe($userId)
        ->and($pref->preferred_llm_config_id)->toBeNull();
});

it('belongs to a user and a preferred LLM driver configuration', function (): void {
    $userId = bootAuthLayer()->register('pref-rel@example.com', USER_PREFERENCE_TEST_PASSWORD, 'PrefRel');
    $llm = LLMDriverConfiguration::create([
        'user_id'      => $userId,
        'name'         => 'Default',
        'driver_class' => 'Spora\Drivers\MockDriver',
        'is_default'   => true,
    ]);

    $pref = UserPreference::create([
        'user_id'                 => $userId,
        'preferred_llm_config_id' => $llm->id,
    ]);

    expect($pref->user)->toBeInstanceOf(User::class)
        ->and((int) $pref->user->getKey())->toBe($userId)
        ->and($pref->preferredLlmConfig)->toBeInstanceOf(LLMDriverConfiguration::class)
        ->and((int) $pref->preferredLlmConfig->getKey())->toBe($llm->id);
});
