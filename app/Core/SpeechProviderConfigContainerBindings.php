<?php

declare(strict_types=1);

namespace Spora\Core;

use Psr\Container\ContainerInterface;
use Spora\Http\SpeechProviderConfigController;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
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
                return new SpeechProviderConfigPersistence(
                    $c->get(SecurityManagerInterface::class),
                    $c->get(SpeechProviderConfigValidator::class),
                );
            },

            SpeechProviderConfigPreferences::class => static function (ContainerInterface $c): SpeechProviderConfigPreferences {
                $principalService = $c->has(PrincipalService::class)
                    ? $c->get(PrincipalService::class)
                    : new PrincipalService(new PrincipalResolver());
                return new SpeechProviderConfigPreferences($principalService);
            },

            SpeechProviderConfigService::class => static function (ContainerInterface $c): SpeechProviderConfigService {
                $principalService = $c->has(PrincipalService::class)
                    ? $c->get(PrincipalService::class)
                    : new PrincipalService(new PrincipalResolver());
                return new SpeechProviderConfigService(
                    $c->get(SpeechProviderConfigValidator::class),
                    $c->get(SpeechProviderConfigPersistence::class),
                    $c->get(SpeechProviderConfigPreferences::class),
                    $principalService,
                );
            },

            SpeechProviderConfigController::class => static function (ContainerInterface $c): SpeechProviderConfigController {
                return new SpeechProviderConfigController(
                    $c->get(\Spora\Auth\AuthService::class),
                    $c->get(SpeechProviderConfigService::class),
                );
            },
        ];
    }
}
