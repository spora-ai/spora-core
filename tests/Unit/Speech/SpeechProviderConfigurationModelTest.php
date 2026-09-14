<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\Agent;
use Spora\Models\Principal;
use Spora\Models\SpeechProviderConfiguration;

/**
 * SpeechProviderConfiguration — the speech mirror of LLMDriverConfiguration.
 *
 * These tests pin the bits that the rest of the app relies on:
 *   - the XOR invariant (principal_id set ⇔ is_global = false)
 *   - the principal() BelongsTo relation
 *   - the FK columns that the cascade writes to
 */

beforeEach(function (): void {
    Capsule::table('speech_provider_configurations')->delete();
    Capsule::table('agents')->delete();
});

test('XOR invariant: global with principal_id = null passes', function (): void {
    $config = new SpeechProviderConfiguration();
    $config->principal_id = null;
    $config->is_global = true;
    $config->provider_class = 'Some\\Provider';
    $config->display_name = 'G';

    expect($config->save())->toBeTrue();
});

test('XOR invariant: principal-scoped (null is_global) passes', function (): void {
    $config = new SpeechProviderConfiguration();
    $config->principal_id = createUserPrincipalPublic(1);
    $config->is_global = false;
    $config->provider_class = 'Some\\Provider';
    $config->display_name = 'P';

    expect($config->save())->toBeTrue();
});

test('XOR invariant: both null (no principal_id, no is_global) is rejected at save()', function (): void {
    $config = new SpeechProviderConfiguration();
    $config->principal_id = null;
    $config->is_global = false;
    $config->provider_class = 'Some\\Provider';
    $config->display_name = 'Bad';

    $config->save();
})->throws(LogicException::class);

test('XOR invariant: both set (principal_id and is_global = true) is rejected at save()', function (): void {
    $config = new SpeechProviderConfiguration();
    $config->principal_id = createUserPrincipalPublic(2);
    $config->is_global = true;
    $config->provider_class = 'Some\\Provider';
    $config->display_name = 'Bad';

    $config->save();
})->throws(LogicException::class);

test('principal() relation resolves to the matching Principal row', function (): void {
    $userId = 42;
    $userPrincipalId = createUserPrincipalPublic($userId);

    $config = new SpeechProviderConfiguration();
    $config->principal_id = $userPrincipalId;
    $config->is_global = false;
    $config->provider_class = 'Some\\Provider';
    $config->display_name = 'P';
    $config->save();

    $fresh = SpeechProviderConfiguration::find($config->id);
    expect($fresh)->not->toBeNull();
    expect($fresh->principal)->toBeInstanceOf(Principal::class);
    expect((int) $fresh->principal->getKey())->toBe($userPrincipalId);
});

test('Fillable: explicit $fill covers the cascade-write columns', function (): void {
    $model = new SpeechProviderConfiguration();
    $expected = [
        'principal_id', 'provider_class', 'display_name', 'settings',
        'is_default', 'is_global',
    ];
    expect($model->getFillable())->toEqual($expected);
});

test('Casts: is_default and is_global are bool, principal_id is int', function (): void {
    $model = new SpeechProviderConfiguration();
    $casts = $model->getCasts();
    expect($casts)->toHaveKey('is_default')
        ->and($casts['is_default'])->toBe('boolean')
        ->and($casts['is_global'])->toBe('boolean')
        ->and($casts['principal_id'])->toBe('integer');
});

test('Agent.speech_driver_config_id is the FK column for the cascade tier-1', function (): void {
    $model = new Agent();
    // The Agent model is keyed on a surrogate `id`, not a user-supplied
    // `agent_id` column. Pin the columns the cascade actually writes
    // through (none of these are auto-set by the user; the test just
    // confirms the cascade-tier-1 column is fillable so the persistence
    // layer can save it).
    $expected = [
        'principal_id', 'name', 'description', 'system_prompt',
        'llm_driver_config_id', 'speech_driver_config_id',
        'max_steps', 'is_active', 'allow_followup', 'retry_after_minutes',
        'max_retries', 'voice_message_retention_count', 'is_pinned',
        'is_archived', 'notes',
    ];
    foreach ($expected as $column) {
        expect($model->getFillable())->toContain($column);
    }
});
