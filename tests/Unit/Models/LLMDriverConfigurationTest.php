<?php

declare(strict_types=1);

use Spora\Models\LLMDriverConfiguration;

const LLM_DRIVER_CONFIGURATION_TEST_PASSWORD = 'Password1!';
use Spora\Models\User;
use Spora\Models\UserPreference;

it('uses the llm_driver_configurations table', function (): void {
    $config = new LLMDriverConfiguration();

    expect($config->getTable())->toBe('llm_driver_configurations');
});

it('casts boolean and integer fields', function (): void {
    $userId = bootAuthLayer()->register('llm@example.com', LLM_DRIVER_CONFIGURATION_TEST_PASSWORD, 'LLM');

    $config = LLMDriverConfiguration::create([
        'user_id'           => $userId,
        'name'              => 'My Driver',
        'driver_class'      => 'Spora\Drivers\MockDriver',
        'is_default'        => true,
        'is_global'         => false,
        'context_window'    => 8000,
        'max_tokens_output' => 1024,
    ]);

    expect($config->is_default)->toBeTrue()
        ->and($config->is_global)->toBeFalse()
        ->and($config->context_window)->toBeInt()
        ->and($config->max_tokens_output)->toBeInt();
});

it('decodes settings JSON via getSettings()', function (): void {
    $config = LLMDriverConfiguration::create([
        'name'         => 'Json',
        'driver_class' => 'Spora\Drivers\MockDriver',
        'settings'     => json_encode(['api_key' => 'sk-test', 'model' => 'gpt-4']),
    ]);

    expect($config->getSettings())->toBe(['api_key' => 'sk-test', 'model' => 'gpt-4']);
});

it('returns [] from getSettings() when settings is null', function (): void {
    $config = LLMDriverConfiguration::create([
        'name'         => 'Empty',
        'driver_class' => 'Spora\Drivers\MockDriver',
    ]);

    expect($config->getSettings())->toBe([]);
});

it('returns [] from getSettings() when settings contains invalid JSON', function (): void {
    $config = LLMDriverConfiguration::create([
        'name'         => 'Bad',
        'driver_class' => 'Spora\Drivers\MockDriver',
        'settings'     => '{not valid json',
    ]);

    expect($config->getSettings())->toBe([]);
});

it('belongs to a user and has one user preference', function (): void {
    $userId = bootAuthLayer()->register('llm-rel@example.com', LLM_DRIVER_CONFIGURATION_TEST_PASSWORD, 'LLMRel');
    $config = LLMDriverConfiguration::create([
        'user_id'      => $userId,
        'name'         => 'Rel',
        'driver_class' => 'Spora\Drivers\MockDriver',
    ]);
    UserPreference::create([
        'user_id'                 => $userId,
        'preferred_llm_config_id' => $config->id,
    ]);

    expect($config->user)->toBeInstanceOf(User::class)
        ->and((int) $config->user->getKey())->toBe($userId)
        ->and($config->userPreference)->toBeInstanceOf(UserPreference::class)
        ->and($config->userPreference->getAttribute('preferred_llm_config_id'))->toBe($config->id);
});
