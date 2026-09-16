<?php

declare(strict_types=1);

namespace Spora\Core;

use Psr\Container\ContainerInterface;
use Spora\Http\SpeechPreferenceController;
use Spora\Http\SpeechProviderConfigController;
use Spora\Services\PrincipalService;
use Spora\Services\SpeechProviderConfigMutator;
use Spora\Services\SpeechProviderConfigPersistence;
use Spora\Services\SpeechProviderConfigPreferences;
use Spora\Services\SpeechProviderConfigService;
use Spora\Services\SpeechProviderConfigValidator;
use Spora\Speech\SpeechToTextRegistry;

/**
 * Stand-alone bindings for the speech provider configuration surface.
 *
 * Lives in its own class to keep {@see ContainerDefinitions} under the
 * S1448 20-method ceiling — splitting the bindings out drops the
 * umbrella back to its pre-existing count.
 *
 * @return array<string, callable>
 */
final class SpeechProviderConfigContainerBindings
{
    /**
     * @return array<string, callable>
     */
    public static function all(): array
    {
        return [
            SpeechProviderConfigValidator::class => static function (ContainerInterface $c): SpeechProviderConfigValidator {
                return new SpeechProviderConfigValidator($c->get(SpeechToTextRegistry::class));
            },

            SpeechProviderConfigPersistence::class => static function (ContainerInterface $c): SpeechProviderConfigPersistence {
                // Mirror of the Registry factory — see
                // {@see SpeechToTextRegistry::$persistenceResolver}.
                return new SpeechProviderConfigPersistence(
                    $c->get(SecurityManagerInterface::class),
                    $c->get(SpeechProviderConfigValidator::class),
                    static fn(): ?SpeechToTextRegistry => $c->has(SpeechToTextRegistry::class)
                        ? $c->get(SpeechToTextRegistry::class)
                        : null,
                );
            },

            SpeechProviderConfigPreferences::class => static function (ContainerInterface $c): SpeechProviderConfigPreferences {
                return new SpeechProviderConfigPreferences(
                    $c->get(PrincipalService::class),
                );
            },

            SpeechProviderConfigMutator::class => static function (ContainerInterface $c): SpeechProviderConfigMutator {
                return new SpeechProviderConfigMutator(
                    $c->get(SpeechProviderConfigPersistence::class),
                    $c->get(SpeechProviderConfigPreferences::class),
                    $c->get(PrincipalService::class),
                );
            },

            SpeechProviderConfigService::class => static function (ContainerInterface $c): SpeechProviderConfigService {
                return new SpeechProviderConfigService(
                    $c->get(SpeechProviderConfigValidator::class),
                    $c->get(SpeechProviderConfigPersistence::class),
                    $c->get(SpeechProviderConfigPreferences::class),
                    $c->get(PrincipalService::class),
                    null,
                    $c->get(SpeechProviderConfigMutator::class),
                );
            },

            SpeechProviderConfigController::class => static function (ContainerInterface $c): SpeechProviderConfigController {
                return new SpeechProviderConfigController(
                    $c->get(\Spora\Auth\AuthService::class),
                    $c->get(SpeechProviderConfigService::class),
                    $c->get(SpeechToTextRegistry::class),
                );
            },

            SpeechPreferenceController::class => static function (ContainerInterface $c): SpeechPreferenceController {
                return new SpeechPreferenceController(
                    $c->get(\Spora\Auth\AuthService::class),
                    $c->get(SpeechProviderConfigService::class),
                    $c->get(PrincipalService::class),
                );
            },
        ];
    }
}
