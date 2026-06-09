<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Log\NullLogger;
use Spora\Core\SecurityManagerInterface;
use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\Exceptions\LLMConfigDecryptFailedException;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Models\LLMDriverConfiguration;
use Spora\Services\LLMConfigPersistence;
use Spora\Services\LLMConfigSchemaInspector;
use Spora\Services\LLMConfigService;

/**
 * Build a fresh LLMConfigService backed by a random sodium key.
 */
function makeFreshSecureLLMConfigService(): LLMConfigService
{
    $key      = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new Spora\Core\SecurityManager($key);

    return new LLMConfigService($security, [
        OpenAICompatibleDriver::class,
        AnthropicCompatibleDriver::class,
    ]);
}

/**
 * Create a real LLMDriverConfiguration row (id returned).
 * Ensures a user row exists (FK constraint on user_id).
 */
function makeBrokenDecryptConfigId(): int
{
    $serviceA = makeFreshSecureLLMConfigService();

    $userExists = Capsule::table('users')->where('id', 1)->exists();
    if (!$userExists) {
        Capsule::table('users')->insert([
            'id'         => 1,
            'email'      => 'broken@test.local',
            'password'   => password_hash('Password1!', PASSWORD_DEFAULT),
            'registered' => time(),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    $config            = new LLMDriverConfiguration();
    $config->user_id   = 1;
    $config->name      = 'Broken Config';
    $config->driver_class = OpenAICompatibleDriver::class;
    $config->settings  = json_encode($serviceA->encodeSettings(
        OpenAICompatibleDriver::class,
        ['api_key' => 'sk-key', 'model' => 'gpt-4o', 'base_url' => 'https://api.openai.com/v1'],
    ));
    $config->is_default = false;
    $config->is_global  = false;
    $config->save();

    return (int) $config->id;
}

describe('LLMConfigDecryptFailedException', function (): void {

    it('extends RuntimeException and preserves the message verbatim', function (): void {
        $e = new LLMConfigDecryptFailedException('decrypt boom');
        expect($e)->toBeInstanceOf(RuntimeException::class)
            ->and($e->getMessage())->toBe('decrypt boom');
    });

    it('preserves the previous throwable as the cause chain', function (): void {
        $cause = new RuntimeException('inner cause');
        $e     = new LLMConfigDecryptFailedException('decrypt boom', 0, $cause);

        expect($e->getPrevious())->toBe($cause);
    });

    it('is thrown by DriverFactory::makeDriverFromConfig when decodeSettings throws', function (): void {
        // LLMConfigService and LLMConfigPersistence are both final and not
        // mockable, but their constructor takes SecurityManagerInterface —
        // which IS mockable. Build a real persistence backed by a mock
        // security whose decrypt() throws. Then call makeDriverFromConfig
        // directly (not via makeFromAgent, which catches the exception).
        $configId = makeBrokenDecryptConfigId();
        $config   = LLMDriverConfiguration::findOrFail($configId);

        $security = Mockery::mock(SecurityManagerInterface::class);
        $security->allows('looksEncrypted')->andReturn(true);
        $security->allows('decrypt')->andThrow(new RuntimeException('cipher failure'));

        $schemaInspector = new LLMConfigSchemaInspector([OpenAICompatibleDriver::class]);
        $persistence     = new LLMConfigPersistence($security, $schemaInspector);
        $service         = new LLMConfigService(
            security: $security,
            driverClasses: [OpenAICompatibleDriver::class],
            schemaInspector: $schemaInspector,
            persistence: $persistence,
        );

        $factory = new DriverFactory(new NullLogger(), $service, 300);
        $ref     = new ReflectionMethod(DriverFactory::class, 'makeDriverFromConfig');

        expect(fn() => $ref->invoke($factory, $config))
            ->toThrow(
                LLMConfigDecryptFailedException::class,
                "Failed to decrypt settings for LLM config 'Broken Config' (id={$configId}): cipher failure",
            );

        LLMDriverConfiguration::where('id', $configId)->delete();
    });
});
