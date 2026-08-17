<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Core\SecurityManagerInterface;
use Spora\Models\LLMDriverConfiguration;

/**
 * Service for managing LLM driver configurations.
 *
 * Thin facade that keeps the public API stable while delegating the
 * heavy lifting to three focused collaborators:
 *  - {@see LLMConfigSchemaInspector} for driver discovery and schema introspection
 *  - {@see LLMConfigPersistence} for CRUD with per-field encryption
 *  - {@see LLMConfigPreferences} for the three-tier default-resolution path
 *
 * Migration 0067 cut the wire format over from `user_id` to
 * `principal_id`; the public method names that say "user" remain only
 * where they describe the controller-facing HTTP path (whose auth
 * layer still passes the caller user-id) so changing them here would
 * be a footgun. Internal `*ForPrincipal` helpers model the principal
 * axis directly.
 */
final class LLMConfigService implements LLMConfigServiceInterface
{
    private readonly LLMConfigSchemaInspector $schemaInspector;
    private readonly LLMConfigPersistence $persistence;
    private readonly LLMConfigPreferences $preferences;
    private readonly PrincipalResolver $principalResolver;
    private readonly PrincipalService $principalService;

    /**
     * @param list<class-string<\Spora\Drivers\LLMDriverConfigInterface>> $driverClasses
     *        Driver classes to register with the schema inspector when the
     *        collaborator is not provided explicitly.
     */
    public function __construct(
        SecurityManagerInterface $security,
        array $driverClasses = [],
        ?LLMConfigSchemaInspector $schemaInspector = null,
        ?LLMConfigPersistence $persistence = null,
        ?LLMConfigPreferences $preferences = null,
        ?PrincipalResolver $principalResolver = null,
        ?PrincipalService $principalService = null,
    ) {
        $this->schemaInspector    = $schemaInspector ?? new LLMConfigSchemaInspector($driverClasses);
        $this->principalResolver  = $principalResolver ?? new PrincipalResolver();
        $this->principalService   = $principalService ?? new PrincipalService($this->principalResolver);
        $this->persistence        = $persistence ?? new LLMConfigPersistence($security, $this->schemaInspector, $this->principalResolver);
        $this->preferences        = $preferences ?? new LLMConfigPreferences($this->principalService);
    }

    // ---------------------------------------------------------------------
    // Schema / driver discovery (delegated to SchemaInspector)
    // ---------------------------------------------------------------------

    /**
     * @return list<array{name: string, display_name: string, driver_class: string, settings_schema: list<array>}>
     */
    public function getDrivers(): array
    {
        return $this->schemaInspector->getDrivers();
    }

    // ---------------------------------------------------------------------
    // Settings encode / decode / mask (delegated to Persistence)
    // ---------------------------------------------------------------------

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function encodeSettings(string $driverClass, array $settings): array
    {
        return $this->persistence->encodeSettings($driverClass, $settings);
    }

    /**
     * @return array<string, mixed>
     */
    public function decodeSettings(string $driverClass, ?string $raw): array
    {
        return $this->persistence->decodeSettings($driverClass, $raw);
    }

    /**
     * @return array<string, mixed>
     */
    public function decryptSettings(string $driverClass, ?string $raw): array
    {
        return $this->decodeSettings($driverClass, $raw);
    }

    /**
     * @param array<string, mixed> $settings
     * @param list<array> $schema
     * @return array<string, mixed>
     */
    public function maskForApi(array $settings, array $schema): array
    {
        $passwordKeys = [];
        foreach ($schema as $field) {
            if (($field['type'] ?? '') === 'password') {
                $passwordKeys[] = $field['key'];
            }
        }

        $masked = [];
        foreach ($settings as $key => $value) {
            if (in_array($key, $passwordKeys, true) && $value !== '') {
                $masked[$key] = '***';
            } else {
                $masked[$key] = $value;
            }
        }

        return $masked;
    }

    // ---------------------------------------------------------------------
    // Configuration listing / lookup (read paths; no collaborator needed)
    // ---------------------------------------------------------------------

    /**
     * @return list<array>
     */
    public function getConfigurationsForUser(int $userId): array
    {
        return $this->getConfigurationsVisibleTo($userId);
    }

    /**
     * Union of every principal-scoped config the user can see plus every
     * global config. Controllers use this in place of the old
     * `where('user_id', $userId)->orWhere('is_global', true)` query so a
     * user with multiple principals sees all their configs at once.
     *
     * @return list<array>
     */
    public function getConfigurationsVisibleTo(int $userId): array
    {
        $principalIds = $this->principalResolver->visiblePrincipalIds($userId);

        $query = LLMDriverConfiguration::where(static function ($q) use ($principalIds): void {
            if ($principalIds !== []) {
                $q->whereIn('principal_id', $principalIds);
            } else {
                $q->whereRaw('1 = 0');
            }
            $q->orWhere('is_global', true);
        });

        return $query->get()
            ->map(fn(LLMDriverConfiguration $config): array => $this->configResource($config))
            ->all();
    }

    /**
     * @return list<array>
     */
    public function getGlobalConfigurations(): array
    {
        return LLMDriverConfiguration::where('is_global', true)
            ->orderBy('name')
            ->get()
            ->map(fn(LLMDriverConfiguration $config): array => $this->configResource($config))
            ->all();
    }

    public function getConfiguration(int $configId, int $userId, bool $isAdmin = false): ?LLMDriverConfiguration
    {
        $query = LLMDriverConfiguration::where('id', $configId);
        if (!$isAdmin) {
            $principalIds = $this->principalResolver->visiblePrincipalIds($userId);
            $query->where(static function ($q) use ($principalIds): void {
                if ($principalIds === []) {
                    $q->whereRaw('1 = 0');
                    return;
                }
                $q->whereIn('principal_id', $principalIds)->orWhere('is_global', true);
            });
        }
        return $query->first();
    }

    public function findConfiguration(int $configId): ?LLMDriverConfiguration
    {
        return LLMDriverConfiguration::find($configId);
    }

    // ---------------------------------------------------------------------
    // Configuration mutations (delegated to Persistence)
    // ---------------------------------------------------------------------

    public function createConfiguration(int $userId, array $data, bool $isAdmin): ?LLMDriverConfiguration
    {
        // Body may carry an explicit principal_id (admins can write to
        // any group); otherwise the caller's user-principal is the
        // default so the principal created by Spora's guard layer is
        // reused.
        $principalId = isset($data['principal_id']) && is_int($data['principal_id'])
            ? $data['principal_id']
            : $this->principalService->ensureUserPrincipal($userId)->id;

        unset($data['principal_id']);

        return $this->persistence->createConfiguration($principalId, $userId, $data, $isAdmin);
    }

    public function updateConfiguration(int $configId, int $userId, array $data, bool $isAdmin): ?LLMDriverConfiguration
    {
        unset($userId);

        return $this->persistence->updateConfiguration($configId, $data, $isAdmin);
    }

    public function deleteConfiguration(int $configId, int $userId, bool $isAdmin): bool
    {
        return $this->persistence->deleteConfiguration($configId, $userId, $isAdmin);
    }

    // ---------------------------------------------------------------------
    // Default + preference resolution (delegated to Preferences)
    // ---------------------------------------------------------------------

    public function setDefaultConfiguration(int $configId, int $userId, bool $isAdmin): ?LLMDriverConfiguration
    {
        unset($userId);

        return $this->preferences->setDefaultConfiguration($configId, $isAdmin);
    }

    public function getDefaultConfiguration(int $userId): ?LLMDriverConfiguration
    {
        return $this->preferences->getDefaultConfiguration($userId);
    }

    public function getEffectiveConfigForAgent(\Spora\Models\Agent $agent): ?LLMDriverConfiguration
    {
        return $this->preferences->getEffectiveConfigForAgent($agent);
    }

    /**
     * Backwards-compatible alias for code paths that still key on
     * user_id. Resolves the caller's user-principal and looks up the
     * preference on its principal_id.
     */
    public function getUserPreferredConfig(int $userId): ?LLMDriverConfiguration
    {
        $principalId = $this->principalService->ensureUserPrincipal($userId)->id;

        return $this->preferences->getPrincipalPreferredConfig($principalId);
    }

    /**
     * Backwards-compatible alias. Resolves the user's principal, then
     * delegates to the principal-aware setter.
     */
    public function setUserPreferredConfig(int $userId, int $configId): bool
    {
        $principalId = $this->principalService->ensureUserPrincipal($userId)->id;

        return $this->preferences->setPrincipalPreferredConfig($principalId, $configId, $userId);
    }

    public function unsetUserPreferredConfig(int $userId): void
    {
        $principalId = $this->principalService->ensureUserPrincipal($userId)->id;

        $this->preferences->unsetPrincipalPreferredConfig($principalId);
    }

    public function getPrincipalPreferredConfig(int $principalId): ?LLMDriverConfiguration
    {
        return $this->preferences->getPrincipalPreferredConfig($principalId);
    }

    public function setPrincipalPreferredConfig(int $principalId, int $configId, int $callerUserId): bool
    {
        return $this->preferences->setPrincipalPreferredConfig($principalId, $configId, $callerUserId);
    }

    public function unsetPrincipalPreferredConfig(int $principalId): void
    {
        $this->preferences->unsetPrincipalPreferredConfig($principalId);
    }

    // ---------------------------------------------------------------------
    // Resource DTO (composes schema + decode + mask; stays on the facade)
    // ---------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function configResource(LLMDriverConfiguration $config): array
    {
        $settings = $this->decodeSettings($config->driver_class, $config->getRawOriginal('settings'));
        $schema = $this->schemaInspector->getSchemaForDriver($config->driver_class);
        $masked = $this->maskForApi($settings, $schema);

        return [
            'id' => $config->id,
            'name' => $config->name,
            'driver_class' => $config->driver_class,
            'driver_name' => $this->schemaInspector->getDriverName($config->driver_class),
            'driver_display_name' => $this->schemaInspector->getDriverDisplayName($config->driver_class),
            'settings' => $masked,
            'context_window' => $config->context_window,
            'max_tokens_output' => $config->max_tokens_output,
            'is_default' => $config->is_default,
            'principal_id' => $config->principal_id,
            'is_global' => $config->is_global,
            'created_at' => $config->created_at->toIso8601String(),
            'updated_at' => $config->updated_at->toIso8601String(),
        ];
    }
}
