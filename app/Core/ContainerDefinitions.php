<?php

declare(strict_types=1);

namespace Spora\Core;

use Delight\Auth\Auth as DelightAuth;
use Illuminate\Database\Capsule\Manager as Capsule;
use InvalidArgumentException;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger as MonologLogger;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Spora\Agents\Orchestrator;
use Spora\Agents\OrchestratorInterface;
use Spora\Agents\ValueObjects\WorkerRuntimeMode;
use Spora\AgentTemplates\AgentTemplateExporter;
use Spora\AgentTemplates\AgentTemplateImporter;
use Spora\AgentTemplates\AgentTemplateScanner;
use Spora\AgentTemplates\AgentTemplateValidator;
use Spora\Apps\AppRegistry;
use Spora\Apps\PluginsApp;
use Spora\Auth\AgentApiKeyIssuer;
use Spora\Auth\AuthService;
use Spora\Console\Commands\AssetGcCommand;
use Spora\Console\Commands\AuditOperationOverridesCommand;
use Spora\Console\Commands\MailTemplatesSyncCommand;
use Spora\Console\Commands\MediaArchiveGcCommand;
use Spora\Console\Commands\MediaArchiveListCommand;
use Spora\Console\Commands\PluginInstallCommand;
use Spora\Console\Commands\PluginListCommand;
use Spora\Console\Commands\PluginUninstallCommand;
use Spora\Console\Commands\PluginUpdateCommand;
use Spora\Console\Commands\RepairAdminCommand;
use Spora\Console\Commands\SeedCommand;
use Spora\Console\Commands\SetupCommand;
use Spora\Console\Commands\TaskRunCommand;
use Spora\Console\Commands\WorkerRunCommand;
use Spora\Core\Exceptions\BasePathNotDefinedException;
use Spora\Core\Extension\PluginManager;
use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Events\EventDispatcherFactory;
use Spora\Extensions\AppLoader;
use Spora\Http\AgentApi\AgentApiChatActionController;
use Spora\Http\AgentApi\AgentApiChatController;
use Spora\Http\AgentApi\AgentApiKeyController;
use Spora\Http\AgentApi\AgentApiMediaController;
use Spora\Http\AgentController;
use Spora\Http\AgentOverrideController;
use Spora\Http\AgentPictureController;
use Spora\Http\AgentTemplateController;
use Spora\Http\AgentToolController;
use Spora\Http\AgentTransferController;
use Spora\Http\AnswerQuestionRequestValidator;
use Spora\Http\AppsController;
use Spora\Http\AuthController;
use Spora\Http\ConfigController;
use Spora\Http\ContinueTaskDispatcher;
use Spora\Http\DecisionsRequestValidator;
use Spora\Http\GlobalSettingsController;
use Spora\Http\GroupController;
use Spora\Http\GroupLlmConfigsController;
use Spora\Http\GroupMemberController;
use Spora\Http\GroupPictureController;
use Spora\Http\GroupPreferencesController;
use Spora\Http\GroupToolsController;
use Spora\Http\HealthController;
use Spora\Http\LLMConfigController;
use Spora\Http\MailConfigController;
use Spora\Http\MailTemplateController;
use Spora\Http\MediaAllowedTypesController;
use Spora\Http\MediaArchiveController;
use Spora\Http\MediaDerivativeController;
use Spora\Http\MediaDerivativeOptionsController;
use Spora\Http\MediaResolveController;
use Spora\Http\MediaUploadController;
use Spora\Http\Middleware\AdminMiddleware;
use Spora\Http\Middleware\AgentApiKeyMiddleware;
use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\Middleware\CsrfMiddleware;
use Spora\Http\NotificationController;
use Spora\Http\NotificationSubscriptionController;
use Spora\Http\PluginsController;
use Spora\Http\PrincipalController;
use Spora\Http\PromptTemplateController;
use Spora\Http\PublicMediaController;
use Spora\Http\RetryChainController;
use Spora\Http\ScheduledRunController;
use Spora\Http\SseController;
use Spora\Http\TaskController;
use Spora\Http\TaskTickController;
use Spora\Http\ToolController;
use Spora\Http\UserController;
use Spora\Http\UserPictureAssetController;
use Spora\Http\UserPictureController;
use Spora\Http\UserPreferenceController;
use Spora\Http\UserProfileController;
use Spora\Plugins\PluginLoader;
use Spora\Security\CsrfTokenService;
use Spora\Services\AgentApi\AgentApiCallbackDispatcher;
use Spora\Services\AgentApi\AgentApiCallbackRecorder;
use Spora\Services\AgentApi\AgentApiChatProjection;
use Spora\Services\AgentApi\AgentApiContext;
use Spora\Services\AgentApi\AgentApiFileIngest;
use Spora\Services\AgentApi\AgentApiScope;
use Spora\Services\AgentApi\CallbackSecretResolver;
use Spora\Services\AgentFavoriteService;
use Spora\Services\AgentFavoriteServiceInterface;
use Spora\Services\AgentManifest;
use Spora\Services\AgentPictures\AgentPictureService;
use Spora\Services\AgentPrincipalService;
use Spora\Services\AgentService;
use Spora\Services\AgentServiceInterface;
use Spora\Services\AgentToolSettingsService;
use Spora\Services\AgentToolSettingsServiceInterface;
use Spora\Services\AssetStore;
use Spora\Services\AssetStreamer;
use Spora\Services\AuthValidator;
use Spora\Services\AuthWorkflow;
use Spora\Services\AutoAssetStore;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\DataUrlAssetStore;
use Spora\Services\DbRateLimiter;
use Spora\Services\EmailTemplateLoader;
use Spora\Services\GroupService;
use Spora\Services\HandoverService;
use Spora\Services\HandoverServiceInterface;
use Spora\Services\LLMConfigPreferences;
use Spora\Services\LlmConfigSchemaValidator;
use Spora\Services\LLMConfigService;
use Spora\Services\LLMConfigServiceInterface;
use Spora\Services\LlmConfigValidator;
use Spora\Services\LocalAssetStore;
use Spora\Services\Mail\MailTemplateSyncService;
use Spora\Services\MailTemplateServiceInterface;
use Spora\Services\MediaArchive\DerivativePayloadStore;
use Spora\Services\MediaArchive\MediaAllowedTypesService;
use Spora\Services\MediaArchive\MediaArchiveIngestPipeline;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaArchiveUrlResolver;
use Spora\Services\MediaArchive\MediaAssetReader;
use Spora\Services\MediaArchive\MediaAssetResolver;
use Spora\Services\MediaArchive\MediaAssetSerializer;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;
use Spora\Services\MediaArchive\MediaDerivativeService;
use Spora\Services\MediaArchive\MediaIngestDecoder;
use Spora\Services\MediaArchive\MetadataExtractor;
use Spora\Services\MediaArchive\MimeSniffer;
use Spora\Services\MediaArchive\PdfMarkdownExtractor;
use Spora\Services\MediaArchive\Producers\ImageDerivativeProducer;
use Spora\Services\MediaArchive\Producers\PdfToMarkdownProducer;
use Spora\Services\MediaArchive\RemoteMediaFetcher;
use Spora\Services\MediaArchive\TaskMediaCapabilityService;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\NotificationService;
use Spora\Services\NotificationServiceInterface;
use Spora\Services\PluginCatalogService;
use Spora\Services\PluginMetadataExtractor;
use Spora\Services\PluginsService;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Spora\Services\ProfilePictures\GroupPictureService;
use Spora\Services\PromptTemplateServiceInterface;
use Spora\Services\ScheduledRunServiceInterface;
use Spora\Services\SpeechProviderConfigPersistence;
use Spora\Services\SsrfGuard;
use Spora\Services\SubAgentService;
use Spora\Services\SubAgentServiceInterface;
use Spora\Services\SystemMailer;
use Spora\Services\TaskService;
use Spora\Services\TaskServiceInterface;
use Spora\Services\ToolCallSerializer;
use Spora\Services\ToolConfigNameResolver;
use Spora\Services\ToolConfigOverrides;
use Spora\Services\ToolConfigSchemaInspector;
use Spora\Services\ToolConfigService;
use Spora\Services\ToolIconResolver;
use Spora\Services\ToolsRecommendsSkillsValidator;
use Spora\Services\UserPictures\UserPictureService;
use Spora\Services\UserService;
use Spora\Services\UserServiceInterface;
use Spora\Settings\SettingsRegistry;
use Spora\Settings\SettingsRepository;
use Spora\Skills\SkillProviderRegistry;
use Spora\Speech\OpenAiCompatibleTranscriber;
use Spora\Speech\SpeechToTextProviderInterface;
use Spora\Speech\SpeechToTextRegistry;
use Spora\Todo\TodoStoreRegistry;
use Spora\Tools\AgentTool;
use Spora\Tools\AgentTool\SkillCatalogPresenter;
use Spora\Tools\AskUserQuestionTool;
use Spora\Tools\CalculatorTool;
use Spora\Tools\HttpTimeoutResolver;
use Spora\Tools\MediaCreateHandler;
use Spora\Tools\MediaDerivativeHandler;
use Spora\Tools\MediaSourceReader;
use Spora\Tools\MediaTool;
use Spora\Tools\ReadUrlTool;
use Spora\Tools\ScheduleTool;
use Spora\Tools\SharedSettingsResolver;
use Spora\Tools\SkillTool;
use Spora\Tools\SubAgentTool;
use Spora\Tools\TimeTool;
use Spora\Tools\TodoTool;
use Spora\Tools\UserInfoTool;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ContainerDefinitions
{
    public static function all(): array
    {
        // Self-register the core derivative producers with the static
        // discovery list. Plugins append from a `ContainerBuildingEvent`
        // subscriber. The "Convert to" dropdown in
        // `spora-plugin-media-archive-frontend` surfaces whatever this list
        // contains, and `MediaAllowedTypesService` unions the producers'
        // `supportedSourceFormats()` (minus `image/*`) into the upload
        // allowlist. See
        // {@see \Spora\Services\MediaArchive\MediaDerivativeProducerInterface}.
        MediaDerivativeProducerDiscovery::add(ImageDerivativeProducer::class);
        MediaDerivativeProducerDiscovery::add(PdfToMarkdownProducer::class);

        return array_merge(
            self::configDefinition(),
            self::coreServiceDefinitions(),
            self::llmDefinitions(),
            self::apiAuthControllerDefinitions(),
            self::apiResourceControllerDefinitions(),
            self::apiTaskControllerDefinitions(),
            self::adminControllerDefinitions(),
            self::agentApiDefinitions(),
            self::toolDefinitions(),
            self::extensionDefinitions(),
            self::orchestratorDefinitions(),
            SpeechProviderConfigContainerBindings::all(),
            self::consoleCommandDefinitions(),
        );
    }

    private static function configDefinition(): array
    {
        return [
            SettingsRegistry::class => static fn(ContainerInterface $c): SettingsRegistry
                => new SettingsRegistry($c->get(Paths::class)),

            SettingsRepository::class => static fn(ContainerInterface $c): SettingsRepository
                => new SettingsRepository(
                    $c->get(SecurityManagerInterface::class),
                    $c->get(SettingsRegistry::class),
                ),

            Config::class => static fn(ContainerInterface $c): Config => (new ConfigFactory(
                $c->get(SettingsRegistry::class),
                $c->get(Paths::class),
            ))->create(),
        ];
    }

    /**
     * Resolve BASE_PATH from the constant when defined, or throw a dedicated
     * exception so callers see a clear, actionable message rather than an
     * "undefined constant" fatal. Mirrors Kernel::resolveBasePath().
     */
    private static function resolveBasePath(): string
    {
        if (!defined('BASE_PATH')) {
            throw new BasePathNotDefinedException(
                'BASE_PATH is not defined. Add `define(\'BASE_PATH\', dirname(__FILE__, 2));` '
                . 'to your public/index.php (web entry) and bin/spora (CLI entry) '
                . 'before any Spora framework code runs.',
            );
        }
        return BASE_PATH;
    }

    private static function coreServiceDefinitions(): array
    {
        return [
            Paths::class => static function (): Paths {
                return new Paths(self::resolveBasePath());
            },

            DerivativePayloadStore::class => static function (): DerivativePayloadStore {
                return new DerivativePayloadStore(self::resolveBasePath());
            },

            // Built once and shared. Subscribers are wired by
            // PluginLoader::wireEventSubscribers() / AppLoader::wireEventSubscribers()
            // via Symfony\Component\EventDispatcher\EventSubscriberInterface,
            // once per process (see Kernel::__construct()).
            'event_dispatcher' => static fn(): \Symfony\Component\EventDispatcher\EventDispatcher
                => EventDispatcherFactory::create(),

            SecurityManagerInterface::class => static fn(ContainerInterface $c): SecurityManager
                => SecurityKeyDefinitions::build($c),

            Database::class => static function (ContainerInterface $c): Database {
                $db = new Database(
                    $c->get(Config::class),
                    $c->get(PluginLoader::class),
                    $c->get(Paths::class),
                    $c->has(AppLoader::class) ? $c->get(AppLoader::class) : null,
                );
                $db->bootDatabaseConnectionOnly();
                return $db;
            },

            LoggerInterface::class => static function (ContainerInterface $c): LoggerInterface {
                $config = $c->get(Config::class);
                $levelStr = ucfirst(strtolower((string) $config->getString('SPORA_LOG_LEVEL')));
                $level = constant(Level::class . '::' . $levelStr);

                $logger = new MonologLogger('spora');

                $logPath = $config->getString('SPORA_LOG_PATH') ?? $c->get(Paths::class)->storage('spora.log');
                $stream = ($logPath === 'stdout') ? 'php://stdout' : $logPath;
                $handler = new StreamHandler($stream, $level);

                $logger->pushHandler($handler);

                return $logger;
            },

            DelightAuth::class => static function (ContainerInterface $c): DelightAuth {
                $pdo = Capsule::connection()->getPdo();

                $config      = $c->get(Config::class);
                $throttling  = $config->getString('SPORA_APP_ENV') !== 'testing';

                return new DelightAuth($pdo, null, null, $throttling);
            },

            AuthService::class => static function (ContainerInterface $c): AuthService {
                $config = $c->get(Config::class);
                $authService = new AuthService(
                    $c->get(DelightAuth::class),
                    $config->getString('SPORA_APP_URL') ?? '',
                    $config->getString('SPORA_APP_PREFIX') ?? '/spora',
                );
                $authService->setSystemMailer($c->get(SystemMailer::class));
                return $authService;
            },

            CsrfTokenService::class => static function (ContainerInterface $c): CsrfTokenService {
                return new CsrfTokenService(
                    $c->get(LoggerInterface::class),
                );
            },

            AuthMiddleware::class => static function (ContainerInterface $c): AuthMiddleware {
                return new AuthMiddleware(
                    $c->get(AuthService::class),
                );
            },

            CsrfMiddleware::class => static function (ContainerInterface $c): CsrfMiddleware {
                return new CsrfMiddleware(
                    $c->get(CsrfTokenService::class),
                    $c->get(LoggerInterface::class),
                );
            },

            PrincipalResolver::class => static fn(): PrincipalResolver => new PrincipalResolver(),

            PrincipalService::class => static function (ContainerInterface $c): PrincipalService {
                return new PrincipalService(
                    $c->get(PrincipalResolver::class),
                );
            },

            GroupService::class => static function (ContainerInterface $c): GroupService {
                return new GroupService(
                    $c->get(PrincipalService::class),
                );
            },

            HttpClientInterface::class => static function (): HttpClientInterface {
                return HttpClient::create();
            },

            ...self::assetStoreDefinitions(),
            ...self::mediaArchiveServiceDefinitions(),

            DriverFactory::class => static function (ContainerInterface $c): DriverFactory {
                return new DriverFactory(
                    $c->get(LoggerInterface::class),
                    $c->get(LLMConfigServiceInterface::class),
                    $c->get(Config::class)->getInt('SPORA_LLM_TIMEOUT') ?? 300,
                    $c->get(LLMConfigPreferences::class),
                );
            },

            SharedSettingsResolver::class => static fn(ContainerInterface $c): SharedSettingsResolver
                => new SharedSettingsResolver($c->get(PluginLoader::class)->settingsClasses()),

            // Explicit rather than autowired: every other class-keyed service a
            // factory pulls in is registered, and autowiring hides the fact
            // that this one resolves only because its single argument happens
            // to be autowirable.
            HttpTimeoutResolver::class => static fn(ContainerInterface $c): HttpTimeoutResolver
                => new HttpTimeoutResolver($c->get(Config::class)),

            ToolConfigService::class => static function (ContainerInterface $c): ToolConfigService {
                $config = $c->get(Config::class);
                return new ToolConfigService(
                    $c->get(SecurityManagerInterface::class),
                    $c->get(LoggerInterface::class),
                    InstalledToolClasses::for($c),
                    new ToolConfigOverrides(
                        // Guarded because some build/test contexts resolve this
                        // graph without the orchestrator slice; null yields an
                        // empty skills map, matching a bare `new` call site.
                        schema: $c->has(ToolConfigSchemaInspector::class) ? $c->get(ToolConfigSchemaInspector::class) : null,
                        principalService: $c->get(PrincipalService::class),
                        groupCascadeEnabled: $config->getBool('SPORA_TOOLS_GROUP_CASCADE_ENABLED'),
                        principalResolver: $c->get(PrincipalResolver::class),
                        sharedSettings: $c->get(SharedSettingsResolver::class),
                    ),
                );
            },

            ToolIconResolver::class => static function (ContainerInterface $c): ToolIconResolver {
                return new ToolIconResolver(
                    new ToolConfigNameResolver(
                        $c->get(LoggerInterface::class),
                        InstalledToolClasses::for($c),
                    ),
                    $c->get(PluginLoader::class),
                );
            },

            // Strict-mode validator: every tool's `#[Tool(recommendsSkills: ...)]`
            // slug must resolve to a shipped skill. The registry is guarded
            // because some build/test contexts resolve the controller graph
            // without the orchestrator slice; production always provides it via
            // OrchestratorContainerBindings::orchestratorDefinitions().
            ToolsRecommendsSkillsValidator::class => static function (ContainerInterface $c): ToolsRecommendsSkillsValidator {
                return new ToolsRecommendsSkillsValidator(
                    new ToolConfigNameResolver(
                        $c->get(LoggerInterface::class),
                        InstalledToolClasses::for($c),
                    ),
                    $c->has(SkillProviderRegistry::class) ? $c->get(SkillProviderRegistry::class) : null,
                );
            },
        ];
    }

    /**
     * Definitions for the MediaArchive service stack. Extracted from
     * `coreServiceDefinitions()` so that function stays under the
     * SonarQube S138 line cap. The bindings share config-driven setup
     * (`media_archive.*`) so they live together.
     *
     * @return array<string, callable>
     */
    private static function mediaArchiveServiceDefinitions(): array
    {
        return [
            // MediaArchive service stack — see app/Services/MediaArchive.
            // Keys are the `SPORA_MEDIA_ARCHIVE_*` registry entries.
            MimeSniffer::class => static fn(ContainerInterface $c): MimeSniffer => new MimeSniffer(
                $c->get(LoggerInterface::class),
            ),

            RemoteMediaFetcher::class => static function (ContainerInterface $c): RemoteMediaFetcher {
                $config = $c->get(Config::class);
                return new RemoteMediaFetcher(
                    $c->get(HttpClientInterface::class),
                    $c->get(LoggerInterface::class),
                    $config->getInt('SPORA_MEDIA_ARCHIVE_FETCH_TIMEOUT') ?? 30,
                    $config->getInt('SPORA_MEDIA_ARCHIVE_MAX_PROMOTE_BYTES') ?? (100 * 1024 * 1024),
                );
            },

            MetadataExtractor::class => static function (ContainerInterface $c): MetadataExtractor {
                return new MetadataExtractor(
                    $c->get(LoggerInterface::class),
                    $c->get(Config::class)->getBool('SPORA_MEDIA_ARCHIVE_FFPROBE_ENABLED'),
                );
            },

            MediaArchiveUrlResolver::class => static function (ContainerInterface $c): MediaArchiveUrlResolver {
                $config = $c->get(Config::class);
                return new MediaArchiveUrlResolver(
                    $c->get(RemoteMediaFetcher::class),
                    $c->get(MimeSniffer::class),
                    $c->get(LoggerInterface::class),
                    $config->getBool('SPORA_MEDIA_ARCHIVE_PROMOTE_EXTERNAL'),
                    $config->getInt('SPORA_MEDIA_ARCHIVE_MAX_PROMOTE_BYTES') ?? (100 * 1024 * 1024),
                );
            },

            MediaArchiveService::class => static function (ContainerInterface $c): MediaArchiveService {
                return new MediaArchiveService(
                    $c->get(MediaArchiveIngestPipeline::class),
                    $c->get(MediaDerivativeService::class),
                    $c->has(PrincipalService::class) ? $c->get(PrincipalService::class) : null,
                    $c->has(MediaAssetResolver::class) ? $c->get(MediaAssetResolver::class) : null,
                );
            },

            \Spora\Services\MediaArchive\MediaArchiveRetention::class => static fn(): \Spora\Services\MediaArchive\MediaArchiveRetention => new \Spora\Services\MediaArchive\MediaArchiveRetention(),

            MediaAssetResolver::class => static function (ContainerInterface $c): MediaAssetResolver {
                return new MediaAssetResolver(
                    $c->has(PrincipalResolver::class) ? $c->get(PrincipalResolver::class) : new PrincipalResolver(),
                );
            },

            MediaArchiveIngestPipeline::class => static function (ContainerInterface $c): MediaArchiveIngestPipeline {
                return new MediaArchiveIngestPipeline(
                    $c->get(MediaIngestDecoder::class),
                    $c->get(MediaArchiveUrlResolver::class),
                    $c->get(MimeSniffer::class),
                    $c->get(MetadataExtractor::class),
                    $c->get(AssetStore::class),
                    $c->get(MediaDerivativeService::class),
                    $c->get(PrincipalService::class),
                );
            },

            MediaAssetReader::class => static function (ContainerInterface $c): MediaAssetReader {
                return new MediaAssetReader(
                    $c->get(DatabaseAssetStore::class),
                    $c->get(LocalAssetStore::class),
                );
            },

            MediaIngestDecoder::class => static fn(): MediaIngestDecoder => new MediaIngestDecoder(),

            TaskMediaCapabilityService::class => static function (ContainerInterface $c): TaskMediaCapabilityService {
                $factory = $c->has(DriverFactory::class) ? $c->get(DriverFactory::class) : null;
                $derivatives = $c->has(MediaDerivativeService::class) ? $c->get(MediaDerivativeService::class) : null;
                return new TaskMediaCapabilityService($factory, $derivatives);
            },

            // One core PDF→text dependency behind
            // {@see PdfMarkdownExtractor}, so the archive's `md` producer
            // and `ReadUrlTool::fetch_pdf` cannot drift.
            \Iamgerwin\PdfToMarkdownParser\PdfToMarkdownParser::class => static fn(): \Iamgerwin\PdfToMarkdownParser\PdfToMarkdownParser
                => new \Iamgerwin\PdfToMarkdownParser\PdfToMarkdownParser(),
            PdfMarkdownExtractor::class => static fn(ContainerInterface $c): PdfMarkdownExtractor
                => new PdfMarkdownExtractor(
                    $c->get(\Iamgerwin\PdfToMarkdownParser\PdfToMarkdownParser::class),
                ),
            MediaAllowedTypesService::class => static function (ContainerInterface $c): MediaAllowedTypesService {
                // A TYPE_LIST setting: the registry coerces every layer to
                // `array<string>`, which `Config::get()` — `mixed` by
                // necessity — cannot express.
                /** @var list<string>|null $allowedImageTypes */
                $allowedImageTypes = $c->get(Config::class)->get('SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES');

                return new MediaAllowedTypesService(
                    $c->get(MediaDerivativeService::class),
                    $c->get(DriverFactory::class),
                    $allowedImageTypes,
                );
            },
        ];
    }

    /**
     * Definitions for the selectable binary asset storage strategies.
     *
     * @return array<string, callable>
     */
    private static function assetStoreDefinitions(): array
    {
        return [
            DataUrlAssetStore::class => static function (ContainerInterface $c): DataUrlAssetStore {
                return new DataUrlAssetStore(
                    $c->get(Config::class)->getInt('SPORA_ASSET_STORE_MAX_BYTES') ?? DatabaseAssetStore::MAX_BYTES,
                );
            },

            LocalAssetStore::class => static function (ContainerInterface $c): LocalAssetStore {
                return new LocalAssetStore(
                    $c->get(Paths::class),
                    $c->get(SecurityManagerInterface::class),
                    $c->get(Config::class)->getInt('SPORA_ASSET_STORE_MAX_BYTES') ?? DatabaseAssetStore::MAX_BYTES,
                );
            },

            AssetStore::class => static function (ContainerInterface $c): AssetStore {
                $config = $c->get(Config::class);
                $mode = $config->getString('SPORA_ASSET_STORE_MODE') ?? 'auto';
                return match ($mode) {
                    'local'    => $c->get(LocalAssetStore::class),
                    'data_url' => $c->get(DatabaseAssetStore::class),
                    'auto'     => new AutoAssetStore(
                        $c->get(DatabaseAssetStore::class),
                        $c->get(LocalAssetStore::class),
                        $config->getInt('SPORA_ASSET_STORE_AUTO_THRESHOLD_BYTES') ?? 1_048_576,
                    ),
                    default    => throw new InvalidArgumentException(
                        "Unknown asset_store.mode: {$mode}",
                    ),
                };
            },

            DatabaseAssetStore::class => static function (ContainerInterface $c): DatabaseAssetStore {
                // Default tracks the MEDIUMBLOB ceiling (migration 0064) so
                // data_url-mode assets never exceed the column capacity.
                $max = $c->get(Config::class)->getInt('SPORA_ASSET_STORE_MAX_BYTES') ?? DatabaseAssetStore::MAX_BYTES;
                if ($max > DatabaseAssetStore::MAX_BYTES) {
                    throw new InvalidArgumentException(sprintf(
                        'asset_store.max_bytes=%d exceeds the %d-byte MEDIUMBLOB ceiling '
                            . 'on media_assets.payload. Lower it or switch asset_store.mode to "local".',
                        $max,
                        DatabaseAssetStore::MAX_BYTES,
                    ));
                }
                return new DatabaseAssetStore($max);
            },
        ];
    }

    // Gates the Web UI plugin install endpoints (docs/20_plugin_install_api.md).
    // CLI plugin commands are not gated — leave this off if `composer` isn't on $PATH.
    private static function resolvePluginInstallEnabled(ContainerInterface $c): bool
    {
        return $c->get(Config::class)->getBool('SPORA_PLUGIN_INSTALL_ENABLED');
    }

    private static function llmDefinitions(): array
    {
        return [
            'llm_driver_classes' => [
                OpenAICompatibleDriver::class,
                AnthropicCompatibleDriver::class,
            ],

            // Plugin and App LLM drivers used to be merged into this entry at
            // container-build time. The `drivers()` extension hook was removed
            // in 1.0 (see `spora-workspace/plans/extension-interface-events.md`),
            // so the merged view is now identical to the static list — kept as
            // a separate alias so LLMConfigService can opt into it via constructor
            // injection without rewriting the core list contract.
            'llm_driver_classes_merged' => static fn(ContainerInterface $c): array => array_values(array_unique($c->get('llm_driver_classes'))),

            // Speech-to-text providers — core ships its own
            // `OpenAiCompatibleTranscriber` for the OpenAI-multipart
            // family (Mistral Voxtral, OpenAI Whisper, Groq Whisper,
            // Lemonfox, Fireworks, LocalAI, future). Plugins contribute
            // additional bespoke providers alongside — today `Muse` for
            // Meta's bespoke multipart wire shape and ffmpeg-preprocess.
            // SpeechToTextRegistry is built from the merged list at boot
            // so the transcribe controller can pick the first configured
            // provider per request.
            'speech_to_text_provider_classes' => [
                OpenAiCompatibleTranscriber::class,
            ],
            'speech_to_text_provider_classes_merged' => static fn(ContainerInterface $c): array => array_values(array_unique(array_merge(
                $c->get('speech_to_text_provider_classes'),
                $c->get(PluginLoader::class)->speechToTextProviderClasses(),
            ))),
            SpeechToTextRegistry::class => static function (ContainerInterface $c): SpeechToTextRegistry {
                $providers = [];
                foreach ($c->get('speech_to_text_provider_classes_merged') as $class) {
                    /** @var SpeechToTextProviderInterface $instance */
                    $instance = $c->get($class);
                    $providers[] = $instance;
                }
                // Persistence is injected as a lazy Closure — the eager
                // graph is cyclic. See
                // {@see SpeechToTextRegistry::$persistenceResolver}.
                return new SpeechToTextRegistry(
                    $providers,
                    $c->has(PrincipalService::class) ? $c->get(PrincipalService::class) : new PrincipalService(new PrincipalResolver()),
                    static fn(): ?SpeechProviderConfigPersistence => $c->has(SpeechProviderConfigPersistence::class)
                        ? $c->get(SpeechProviderConfigPersistence::class)
                        : null,
                );
            },

            'app_apps' => [
                PluginsApp::class,
            ],

            AppRegistry::class => static function (ContainerInterface $c): AppRegistry {
                $registry = new AppRegistry();
                $appContributedApps = $c->has(AppLoader::class)
                    ? ($c->get(AppLoader::class)->getApp()?->apps() ?? [])
                    : [];
                $pluginContributedApps = $c->has(PluginLoader::class)
                    ? $c->get(PluginLoader::class)->appClasses()
                    : [];
                foreach (array_merge($c->get('app_apps'), $appContributedApps, $pluginContributedApps) as $appClass) {
                    $registry->register($appClass);
                }
                return $registry;
            },

            'tool_classes' => [
                TimeTool::class,
                CalculatorTool::class,
                ReadUrlTool::class,
                UserInfoTool::class,
                SubAgentTool::class,
                AgentTool::class,
                SkillTool::class,
                MediaTool::class,
                TodoTool::class,
                AskUserQuestionTool::class,
                ScheduleTool::class,
            ],

            LLMConfigService::class => static function (ContainerInterface $c): LLMConfigService {
                return new LLMConfigService(
                    $c->get(SecurityManagerInterface::class),
                    $c->get('llm_driver_classes_merged'),
                    null,
                    null,
                    null,
                    $c->get(PrincipalResolver::class),
                    $c->get(PrincipalService::class),
                );
            },

            LLMConfigServiceInterface::class => static function (ContainerInterface $c): LLMConfigServiceInterface {
                return $c->get(LLMConfigService::class);
            },

            LLMConfigPreferences::class => static function (ContainerInterface $c): LLMConfigPreferences {
                return new LLMConfigPreferences($c->get(PrincipalService::class));
            },

            AgentServiceInterface::class => static function (ContainerInterface $c): AgentServiceInterface {
                return new AgentService(
                    $c->get(ToolIconResolver::class),
                    $c->get(AgentPictureService::class),
                    $c->get(PrincipalResolver::class),
                    $c->get(AgentPrincipalService::class),
                );
            },

            // Per-user favourites (Plan A) split out of AgentService so the
            // umbrella stays under the SonarCloud S1448 20-method ceiling.
            // The favourite service depends on AgentService::getAgent() for
            // the visibility check (no circular DI — AgentService never
            // depends back on AgentFavoriteService).
            AgentFavoriteServiceInterface::class => static function (ContainerInterface $c): AgentFavoriteServiceInterface {
                return new AgentFavoriteService(
                    $c->get(AgentServiceInterface::class),
                );
            },

            AgentPictureService::class => static fn(): AgentPictureService => new AgentPictureService(),

            GroupPictureService::class => static fn(): GroupPictureService => new GroupPictureService(),

            UserPictureService::class => static function (ContainerInterface $c): UserPictureService {
                return new UserPictureService($c->get(Paths::class));
            },

            // Principal materialisation + agent-transfer path. Split out of
            // AgentService so the umbrella stays under the SonarCloud
            // S1448 20-method-per-class ceiling. ToolConfigService is
            // optional — the transfer call prunes per-agent handover
            // allowlists when the new principal doesn't share their
            // previous targets. Tests can leave it null to skip the
            // prune path.
            AgentPrincipalService::class => static function (ContainerInterface $c): AgentPrincipalService {
                return new AgentPrincipalService(
                    $c->get(PrincipalService::class),
                    $c->get(ToolConfigService::class),
                );
            },

            // Tool enablement, settings overrides, and operation overrides
            // moved here from AgentService so the umbrella stays under the
            // SonarCloud S1448 20-method-per-class ceiling.
            AgentToolSettingsServiceInterface::class => static function (ContainerInterface $c): AgentToolSettingsServiceInterface {
                return new AgentToolSettingsService(
                    $c->get(ToolConfigService::class),
                    $c->get(LLMConfigService::class),
                    $c->get(PrincipalResolver::class),
                    schema: $c->has(ToolConfigSchemaInspector::class) ? $c->get(ToolConfigSchemaInspector::class) : null,
                );
            },

            AgentManifest::class => static function (ContainerInterface $c): AgentManifest {
                return new AgentManifest(
                    $c->get(AgentToolSettingsServiceInterface::class),
                    $c->get(ToolIconResolver::class),
                    $c->get(PrincipalResolver::class),
                );
            },

            UserServiceInterface::class => static function (ContainerInterface $c): UserServiceInterface {
                return new UserService($c->get(UserPictureService::class));
            },
        ];
    }

    private static function apiAuthControllerDefinitions(): array
    {
        return [
            AuthController::class => static function (ContainerInterface $c): AuthController {
                return new AuthController(
                    $c->get(AuthService::class),
                    $c->get(CsrfTokenService::class),
                    $c->get(AuthValidator::class),
                    $c->get(AuthWorkflow::class),
                    $c->get(Config::class),
                    $c->get(SystemMailer::class),
                );
            },

            AuthWorkflow::class => static function (ContainerInterface $c): AuthWorkflow {
                return new AuthWorkflow(
                    $c->get(AuthService::class),
                    $c->get(UserServiceInterface::class),
                    $c->get(CsrfTokenService::class),
                    $c->get(AuthValidator::class),
                );
            },

            AuthValidator::class => static fn(): AuthValidator => new AuthValidator(),

            LLMConfigController::class => static function (ContainerInterface $c): LLMConfigController {
                return new LLMConfigController(
                    $c->get(AuthService::class),
                    $c->get(LLMConfigServiceInterface::class),
                    $c->get(LlmConfigValidator::class),
                    $c->get(AgentServiceInterface::class),
                    $c->get(PrincipalResolver::class),
                );
            },

            LlmConfigValidator::class => static function (ContainerInterface $c): LlmConfigValidator {
                return new LlmConfigValidator(
                    $c->get(LLMConfigServiceInterface::class),
                    $c->get(LlmConfigSchemaValidator::class),
                    $c->get(PrincipalService::class),
                    $c->get(PrincipalResolver::class),
                );
            },

            LlmConfigSchemaValidator::class => static fn(ContainerInterface $c): LlmConfigSchemaValidator
                => new LlmConfigSchemaValidator(),

            UserPreferenceController::class => static function (ContainerInterface $c): UserPreferenceController {
                return new UserPreferenceController(
                    $c->get(AuthService::class),
                    $c->get(LLMConfigServiceInterface::class),
                    $c->get(PrincipalResolver::class),
                    $c->get(PrincipalService::class),
                );
            },

            ConfigController::class => static function (ContainerInterface $c): ConfigController {
                $config = $c->get(Config::class);

                return new ConfigController(
                    $config,
                    $c->get(WorkerRuntimeMode::class),
                    $config->getInt('SPORA_TICK_LEASE_SECONDS') ?? 600,
                );
            },

            UserProfileController::class => static function (ContainerInterface $c): UserProfileController {
                return new UserProfileController(
                    $c->get(AuthService::class),
                    $c->get(UserServiceInterface::class),
                );
            },

            UserPictureController::class => static function (ContainerInterface $c): UserPictureController {
                return new UserPictureController(
                    $c->get(AuthService::class),
                    $c->get(UserPictureService::class),
                    $c->get(MimeSniffer::class),
                );
            },

            UserPictureAssetController::class => static function (ContainerInterface $c): UserPictureAssetController {
                return new UserPictureAssetController(
                    $c->get(AuthService::class),
                    $c->get(UserPictureService::class),
                );
            },
        ];
    }

    private static function apiResourceControllerDefinitions(): array
    {
        return array_merge(
            self::pluginGroupControllerDefinitions(),
            self::agentResourceControllerDefinitions(),
            self::mediaResourceControllerDefinitions(),
        );
    }

    private static function pluginGroupControllerDefinitions(): array
    {
        return [
            AppsController::class => static function (ContainerInterface $c): AppsController {
                return new AppsController(
                    $c->get(AppRegistry::class),
                    $c->get(PluginLoader::class),
                );
            },
            PluginMetadataExtractor::class => static fn(): PluginMetadataExtractor => new PluginMetadataExtractor(),

            PluginsService::class => static function (ContainerInterface $c): PluginsService {
                return new PluginsService(
                    $c->get(PluginLoader::class),
                    $c->get(PluginMetadataExtractor::class),
                );
            },

            PluginCatalogService::class => static function (ContainerInterface $c): PluginCatalogService {
                return new PluginCatalogService(
                    $c->get(HttpClientInterface::class),
                    $c->get(Paths::class),
                    $c->get(Config::class)->getInt('SPORA_PLUGIN_CATALOG_TTL') ?? PluginCatalogService::DEFAULT_TTL_SECONDS,
                );
            },

            PluginsController::class => static function (ContainerInterface $c): PluginsController {
                $enabled = $c->get(Config::class)->getBool('SPORA_PLUGIN_CATALOG_ENABLED');

                return new PluginsController(
                    $c->get(PluginsService::class),
                    $c->get(PluginManager::class),
                    self::resolvePluginInstallEnabled($c),
                    $enabled ? $c->get(PluginCatalogService::class) : null,
                    $enabled,
                );
            },

            GroupController::class => static function (ContainerInterface $c): GroupController {
                return new GroupController(
                    $c->get(AuthService::class),
                    $c->get(GroupService::class),
                    $c->get(PrincipalService::class),
                    $c->get(GroupPictureService::class),
                );
            },

            GroupMemberController::class => static function (ContainerInterface $c): GroupMemberController {
                return new GroupMemberController(
                    $c->get(AuthService::class),
                    $c->get(GroupService::class),
                    $c->get(UserServiceInterface::class),
                );
            },

            GroupPreferencesController::class => static function (ContainerInterface $c): GroupPreferencesController {
                return new GroupPreferencesController(
                    $c->get(AuthService::class),
                    $c->get(PrincipalService::class),
                );
            },

            GroupToolsController::class => static function (ContainerInterface $c): GroupToolsController {
                return new GroupToolsController(
                    $c->get(AuthService::class),
                    $c->get(PrincipalService::class),
                    $c->get(ToolConfigService::class),
                );
            },

            GroupLlmConfigsController::class => static function (ContainerInterface $c): GroupLlmConfigsController {
                return new GroupLlmConfigsController(
                    $c->get(AuthService::class),
                    $c->get(LLMConfigServiceInterface::class),
                    $c->get(LlmConfigValidator::class),
                    $c->get(PrincipalService::class),
                );
            },

            GroupPictureController::class => static function (ContainerInterface $c): GroupPictureController {
                return new GroupPictureController(
                    $c->get(AuthService::class),
                    $c->get(PrincipalService::class),
                    $c->get(GroupPictureService::class),
                    $c->get(MediaArchiveService::class),
                    $c->get(MimeSniffer::class),
                    $c->get(Config::class),
                );
            },
        ];
    }

    private static function agentResourceControllerDefinitions(): array
    {
        return [
            AgentController::class => static function (ContainerInterface $c): AgentController {
                return new AgentController(
                    $c->get(AuthService::class),
                    $c->get(AgentServiceInterface::class),
                    $c->get(AgentFavoriteServiceInterface::class),
                    $c->get(DriverFactory::class),
                    $c->get(ToolIconResolver::class),
                    $c->get(AgentPictureService::class),
                    $c->get(PrincipalService::class),
                    $c->get(PrincipalResolver::class),
                );
            },

            // Transfer split out so AgentController stays under the
            // SonarCloud S1448 20-method-per-class ceiling. Talks to
            // AgentPrincipalService (not AgentService) so the principal
            // axis stays on its own.
            AgentTransferController::class => static function (ContainerInterface $c): AgentTransferController {
                return new AgentTransferController(
                    $c->get(AuthService::class),
                    $c->get(AgentPrincipalService::class),
                    $c->get(AgentFavoriteServiceInterface::class),
                    $c->get(DriverFactory::class),
                    $c->get(ToolIconResolver::class),
                    $c->get(AgentPictureService::class),
                );
            },

            PrincipalController::class => static function (ContainerInterface $c): PrincipalController {
                return new PrincipalController(
                    $c->get(AuthService::class),
                    $c->get(PrincipalResolver::class),
                );
            },

            AgentToolController::class => static function (ContainerInterface $c): AgentToolController {
                return new AgentToolController(
                    $c->get(AuthService::class),
                    $c->get(AgentToolSettingsServiceInterface::class),
                    $c->get(ToolConfigService::class),
                );
            },

            AgentOverrideController::class => static function (ContainerInterface $c): AgentOverrideController {
                return new AgentOverrideController(
                    $c->get(AuthService::class),
                    $c->get(AgentToolSettingsServiceInterface::class),
                    $c->get(ToolConfigService::class),
                );
            },

            HealthController::class => static fn(): HealthController => new HealthController(),

            ToolController::class => static function (ContainerInterface $c): ToolController {
                return new ToolController(
                    $c->get(AuthService::class),
                    $c->get(ToolConfigService::class),
                    InstalledToolClasses::for($c),
                    $c->get(ToolIconResolver::class),
                    $c->get(ToolsRecommendsSkillsValidator::class),
                    $c->get(SharedSettingsResolver::class),
                );
            },
        ];
    }

    private static function mediaResourceControllerDefinitions(): array
    {
        return [
            MediaArchiveController::class => static function (ContainerInterface $c): MediaArchiveController {
                return new MediaArchiveController(
                    $c->get(MediaArchiveService::class),
                    $c->get(AuthService::class),
                    new MediaAssetSerializer(false),
                    $c->get(PrincipalResolver::class),
                );
            },

            MediaUploadController::class => static function (ContainerInterface $c): MediaUploadController {
                return new MediaUploadController(
                    $c->get(MediaArchiveService::class),
                    $c->get(\Spora\Services\MediaArchive\MediaArchiveRetention::class),
                    $c->get(MediaAllowedTypesService::class),
                    $c->get(AuthService::class),
                    $c->get(PrincipalResolver::class),
                    $c->get(MimeSniffer::class),
                    $c->get(Config::class),
                    new MediaAssetSerializer(true, $c->get(MediaDerivativeService::class)),
                );
            },

            MediaResolveController::class => static function (ContainerInterface $c): MediaResolveController {
                return new MediaResolveController(
                    $c->get(MediaArchiveService::class),
                    $c->get(AuthService::class),
                    // The chat list doesn't need the derivatives strip;
                    // that's a Media Archive detail-page concern.
                    new MediaAssetSerializer(includeDerivatives: false),
                );
            },

            MediaAllowedTypesController::class => static function (ContainerInterface $c): MediaAllowedTypesController {
                return new MediaAllowedTypesController(
                    $c->get(MediaAllowedTypesService::class),
                );
            },

            MediaDerivativeService::class => static function (ContainerInterface $c): MediaDerivativeService {
                return new MediaDerivativeService(
                    $c->get(AssetStore::class),
                    $c->get(PrincipalService::class),
                    $c,
                    $c->get(DerivativePayloadStore::class),
                    $c->has(LoggerInterface::class) ? $c->get(LoggerInterface::class) : null,
                );
            },

            MediaDerivativeController::class => static function (ContainerInterface $c): MediaDerivativeController {
                return new MediaDerivativeController(
                    $c->get(MediaDerivativeService::class),
                    $c->get(AuthService::class),
                    new MediaAssetSerializer(true, $c->get(MediaDerivativeService::class)),
                );
            },

            MediaDerivativeOptionsController::class => static function (ContainerInterface $c): MediaDerivativeOptionsController {
                return new MediaDerivativeOptionsController(
                    $c->get(MediaDerivativeService::class),
                    $c->get(AuthService::class),
                );
            },

            AgentPictureController::class => static function (ContainerInterface $c): AgentPictureController {
                return new AgentPictureController(
                    $c->get(AuthService::class),
                    $c->get(AgentServiceInterface::class),
                    $c->get(AgentPictureService::class),
                    $c->get(MediaArchiveService::class),
                    $c->get(MimeSniffer::class),
                    $c->get(Config::class),
                );
            },

            PublicMediaController::class => static function (ContainerInterface $c): PublicMediaController {
                return new PublicMediaController(
                    $c->get(DatabaseAssetStore::class),
                    $c->get(LocalAssetStore::class),
                );
            },
        ];
    }

    private static function apiTaskControllerDefinitions(): array
    {
        return [
            TaskController::class => static function (ContainerInterface $c): TaskController {
                return new TaskController(
                    $c->get(AuthService::class),
                    $c->get(TaskServiceInterface::class),
                    $c->get(TaskMediaCapabilityService::class),
                    $c->get(ContinueTaskDispatcher::class),
                    $c->get(DecisionsRequestValidator::class),
                    $c->get(AnswerQuestionRequestValidator::class),
                );
            },

            TaskTickController::class => static function (ContainerInterface $c): TaskTickController {
                return new TaskTickController(
                    $c->get(AuthService::class),
                    $c->get(TaskServiceInterface::class),
                    $c->get(WorkerRuntimeMode::class),
                    $c->get(DbRateLimiter::class),
                    $c->get(MercurePublisherInterface::class),
                    $c->get(OrchestratorInterface::class),
                    $c->get(\Spora\Agents\ErrorClassifier::class),
                    $c->get(\Spora\Agents\RetryScheduler::class),
                    $c->get(NotificationService::class),
                    $c->get(LoggerInterface::class),
                    $c->get(Config::class)->getInt('SPORA_TICK_LEASE_SECONDS') ?? 600,
                    $c->get(PrincipalResolver::class),
                );
            },

            RetryChainController::class => static function (ContainerInterface $c): RetryChainController {
                return new RetryChainController(
                    $c->get(AuthService::class),
                    $c->get(TaskServiceInterface::class),
                );
            },

            DecisionsRequestValidator::class => static function (ContainerInterface $c): DecisionsRequestValidator {
                return new DecisionsRequestValidator(
                    $c->get(TaskServiceInterface::class),
                );
            },

            AnswerQuestionRequestValidator::class => static function (ContainerInterface $c): AnswerQuestionRequestValidator {
                return new AnswerQuestionRequestValidator(
                    $c->get(PrincipalResolver::class),
                );
            },

            ContinueTaskDispatcher::class => static function (ContainerInterface $c): ContinueTaskDispatcher {
                return new ContinueTaskDispatcher(
                    $c->get(TaskServiceInterface::class),
                    $c->get(TaskMediaCapabilityService::class),
                );
            },

            TaskServiceInterface::class => static function (ContainerInterface $c): TaskServiceInterface {
                return new TaskService(
                    $c->get(OrchestratorInterface::class),
                    $c->get(MercurePublisherInterface::class),
                    $c->get(ToolCallSerializer::class),
                    $c->get(PrincipalResolver::class),
                );
            },

            AgentTemplateController::class => static function (ContainerInterface $c): AgentTemplateController {
                return new AgentTemplateController(
                    $c->get(AuthService::class),
                    $c->get(AgentTemplateScanner::class),
                    $c->get(AgentTemplateValidator::class),
                    $c->get(AgentTemplateImporter::class),
                    $c->get(AgentTemplateExporter::class),
                    $c->get(AgentServiceInterface::class),
                    $c->get(PrincipalService::class),
                );
            },

            PromptTemplateController::class => static function (ContainerInterface $c): PromptTemplateController {
                return new PromptTemplateController(
                    $c->get(AuthService::class),
                    $c->get(PromptTemplateServiceInterface::class),
                );
            },

            NotificationController::class => static function (ContainerInterface $c): NotificationController {
                return new NotificationController(
                    $c->get(AuthService::class),
                    $c->get(NotificationServiceInterface::class),
                );
            },

            NotificationSubscriptionController::class => static function (ContainerInterface $c): NotificationSubscriptionController {
                return new NotificationSubscriptionController(
                    $c->get(AuthService::class),
                    $c->get(\Spora\Services\NotificationSubscriptionService::class),
                    $c->get(Config::class),
                );
            },

            SseController::class => static function (ContainerInterface $c): SseController {
                $config = $c->get(Config::class);
                // mercure_publish_url wins when set (split-URL deployments);
                // fall through to mercure_url so single-URL setups report active.
                $hubUrl = $config->getString('SPORA_MERCURE_PUBLISH_URL') ?? $config->getString('SPORA_MERCURE_URL');

                return new SseController(
                    $c->get(AuthService::class),
                    $c->get(PrincipalResolver::class),
                    $hubUrl,
                    $config->getString('SPORA_MERCURE_JWT_KEY'),
                    '/.well-known/mercure',
                    $config->getString('SPORA_APP_URL'),
                );
            },
        ];
    }

    private static function adminControllerDefinitions(): array
    {
        return [
            AdminMiddleware::class => static function (ContainerInterface $c): AdminMiddleware {
                return new AdminMiddleware(
                    $c->get(AuthService::class),
                );
            },

            UserController::class => static function (ContainerInterface $c): UserController {
                return new UserController(
                    $c->get(AuthService::class),
                    $c->get(UserServiceInterface::class),
                );
            },

            GlobalSettingsController::class => \DI\autowire(),

            MailConfigController::class => static function (ContainerInterface $c): MailConfigController {
                return new MailConfigController(
                    $c->get(AuthService::class),
                    $c->get(SystemMailer::class),
                    $c->get(Config::class),
                    $c->get(SettingsRepository::class),
                    $c->get(SettingsRegistry::class),
                );
            },

            MailTemplateController::class => static function (ContainerInterface $c): MailTemplateController {
                return new MailTemplateController(
                    $c->get(MailTemplateServiceInterface::class),
                );
            },

            ScheduledRunController::class => static function (ContainerInterface $c): ScheduledRunController {
                return new ScheduledRunController(
                    $c->get(AuthService::class),
                    $c->get(ScheduledRunServiceInterface::class),
                );
            },
        ];
    }

    private static function agentApiDefinitions(): array
    {
        return [
            AssetStreamer::class => static function (ContainerInterface $c): AssetStreamer {
                return new AssetStreamer(
                    $c->get(DatabaseAssetStore::class),
                    $c->get(LocalAssetStore::class),
                );
            },

            SsrfGuard::class => static function (): SsrfGuard {
                return new SsrfGuard();
            },

            AgentApiKeyIssuer::class => static function (): AgentApiKeyIssuer {
                return new AgentApiKeyIssuer();
            },

            CallbackSecretResolver::class => static function (): CallbackSecretResolver {
                return new CallbackSecretResolver();
            },

            AgentApiChatProjection::class => static function (): AgentApiChatProjection {
                return new AgentApiChatProjection();
            },

            AgentApiContext::class => \DI\autowire(),

            AgentApiScope::class => static function (ContainerInterface $c): AgentApiScope {
                return new AgentApiScope(
                    $c->get(TaskServiceInterface::class),
                    $c->get(MediaArchiveService::class),
                    $c->get(ToolCallSerializer::class),
                );
            },

            AgentApiCallbackRecorder::class => static fn(): AgentApiCallbackRecorder => new AgentApiCallbackRecorder(),

            AgentApiFileIngest::class => static function (ContainerInterface $c): AgentApiFileIngest {
                return new AgentApiFileIngest(
                    $c->get(MediaArchiveService::class),
                    $c->get(MediaAllowedTypesService::class),
                    $c->get(MimeSniffer::class),
                );
            },

            AgentApiCallbackDispatcher::class => static function (ContainerInterface $c): AgentApiCallbackDispatcher {
                return new AgentApiCallbackDispatcher(
                    $c->get(HttpClientInterface::class),
                    $c->get(AgentApiChatProjection::class),
                    $c->get(CallbackSecretResolver::class),
                    $c->get(LoggerInterface::class),
                );
            },

            AgentApiKeyMiddleware::class => static function (ContainerInterface $c): AgentApiKeyMiddleware {
                return new AgentApiKeyMiddleware(
                    $c->get(AgentApiKeyIssuer::class),
                    $c->get(PrincipalResolver::class),
                    $c->get(DbRateLimiter::class),
                    $c->get(Config::class),
                );
            },

            AgentApiChatController::class => static function (ContainerInterface $c): AgentApiChatController {
                return new AgentApiChatController(
                    $c->get(AgentApiScope::class),
                    $c->get(AgentApiChatProjection::class),
                    $c->get(AgentApiFileIngest::class),
                    $c->get(AgentApiCallbackRecorder::class),
                    $c->get(SsrfGuard::class),
                    $c->get(Config::class),
                );
            },

            AgentApiChatActionController::class => static function (ContainerInterface $c): AgentApiChatActionController {
                return new AgentApiChatActionController(
                    $c->get(AgentApiScope::class),
                    $c->get(AgentApiChatProjection::class),
                );
            },

            AgentApiMediaController::class => static function (ContainerInterface $c): AgentApiMediaController {
                return new AgentApiMediaController(
                    $c->get(AgentApiScope::class),
                    $c->get(AgentApiFileIngest::class),
                    $c->get(AssetStreamer::class),
                    $c->get(MediaAssetSerializer::class),
                );
            },

            AgentApiKeyController::class => static function (ContainerInterface $c): AgentApiKeyController {
                return new AgentApiKeyController(
                    $c->get(AuthService::class),
                    $c->get(PrincipalResolver::class),
                    $c->get(AgentApiKeyIssuer::class),
                );
            },
        ];
    }

    private static function toolDefinitions(): array
    {
        return [
            'tool_instances' => static function (ContainerInterface $c): array {
                $classes = InstalledToolClasses::for($c);
                return array_combine($classes, array_map(
                    fn(string $toolClass) => $c->get($toolClass),
                    $classes,
                ));
            },

            ToolCallSerializer::class => static function (ContainerInterface $c): ToolCallSerializer {
                return new ToolCallSerializer(
                    toolInstances: $c->get('tool_instances'),
                    iconResolver: $c->get(ToolIconResolver::class),
                );
            },

            TimeTool::class => static fn(): TimeTool => new TimeTool(),
            CalculatorTool::class => static fn(): CalculatorTool => new CalculatorTool(),

            TodoStoreRegistry::class => static fn(): TodoStoreRegistry => new TodoStoreRegistry(),

            TodoTool::class => static function (ContainerInterface $c): TodoTool {
                return new TodoTool($c->get(TodoStoreRegistry::class));
            },

            AskUserQuestionTool::class => static fn(): AskUserQuestionTool => new AskUserQuestionTool(),

            // AgentTool.create_agent now uses AgentService::createAgent
            // (slim payload) and AgentManifest for output; the
            // LLM-facing flow no longer needs AgentTemplateImporter /
            // AgentTemplateValidator — those stay in
            // AgentTemplateController for the operator-upload path.
            // PluginLoader and ToolIconResolver are only used by
            // get_available_tools to label the source of each tool and
            // resolve icons — both remain optional so unit tests can
            // omit them.
            AgentTool::class => static function (ContainerInterface $c): AgentTool {
                return new AgentTool(
                    $c->get(AgentServiceInterface::class),
                    $c->get(AgentToolSettingsServiceInterface::class),
                    $c->get(AgentManifest::class),
                    new AgentTool\AgentToolCollaborators(
                        pluginLoader: $c->has(PluginLoader::class) ? $c->get(PluginLoader::class) : null,
                        iconResolver: $c->has(ToolIconResolver::class) ? $c->get(ToolIconResolver::class) : null,
                        principalResolver: $c->get(PrincipalResolver::class),
                        // Backs the principal check on a `configure_tools`
                        // `allowed_skills` write. Guarded for the same reason as
                        // the registry in coreServiceDefinitions(): the
                        // controller graph resolves without the orchestrator
                        // slice in some build/test contexts.
                        skills: $c->has(SkillProviderRegistry::class) ? $c->get(SkillProviderRegistry::class) : null,
                        // Backs the `skills` block of `get_available_tools`.
                        skillCatalog: $c->has(SkillCatalogPresenter::class) ? $c->get(SkillCatalogPresenter::class) : null,
                    ),
                    $c->get(PrincipalResolver::class),
                    $c->get(AuthService::class),
                );
            },

            // ScheduleTool bundles 12 LLM-facing operations across two
            // sub-domains: scheduled_runs + agent_prompt_templates. The
            // collaborators bundle is optional (zero-arg constructor
            // exists) — wiring the PrincipalResolver + PrincipalService
            // here so the caller's principal is resolved consistently.
            ScheduleTool::class => static function (ContainerInterface $c): ScheduleTool {
                return new ScheduleTool(
                    $c->get(ScheduledRunServiceInterface::class),
                    $c->get(PromptTemplateServiceInterface::class),
                    new ScheduleTool\ScheduleToolCollaborators(
                        principalResolver: $c->get(PrincipalResolver::class),
                        principalService: $c->get(PrincipalService::class),
                    ),
                    $c->get(PrincipalResolver::class),
                    $c->get(PrincipalService::class),
                );
            },

            ReadUrlTool::class => static function (ContainerInterface $c): ReadUrlTool {
                return new ReadUrlTool(
                    $c->get(HttpClientInterface::class),
                    $c->get(ToolConfigService::class),
                    $c->get(HttpTimeoutResolver::class),
                    $c->get(LoggerInterface::class),
                    $c->get(PdfMarkdownExtractor::class),
                );
            },

            UserInfoTool::class => static fn(): UserInfoTool => new UserInfoTool(),

            SubAgentTool::class => static function (ContainerInterface $c): SubAgentTool {
                return new SubAgentTool(
                    $c->get(HandoverServiceInterface::class),
                    $c->get(SubAgentServiceInterface::class),
                    $c->get(ToolConfigService::class),
                );
            },

            SkillTool::class => static function (ContainerInterface $c): SkillTool {
                return new SkillTool(
                    $c->get(SkillProviderRegistry::class),
                    $c->get(ToolConfigService::class),
                    $c->get(PrincipalResolver::class),
                );
            },

            MediaSourceReader::class => static function (ContainerInterface $c): MediaSourceReader {
                return new MediaSourceReader(
                    $c->get(DatabaseAssetStore::class),
                    $c->get(LocalAssetStore::class),
                );
            },

            MediaDerivativeHandler::class => static function (ContainerInterface $c): MediaDerivativeHandler {
                $derivatives = $c->get(MediaDerivativeService::class);
                return new MediaDerivativeHandler(
                    new MediaAssetSerializer(true, $derivatives),
                    $derivatives,
                    $c->get(MediaSourceReader::class),
                );
            },

            MediaCreateHandler::class => \DI\autowire(),

            MediaTool::class => static function (ContainerInterface $c): MediaTool {
                $derivatives = $c->get(MediaDerivativeService::class);
                return new MediaTool(
                    $c->get(MediaArchiveService::class),
                    $c->get(AuthService::class),
                    new MediaAssetSerializer(true, $derivatives),
                    $derivatives,
                    $c->get(MediaSourceReader::class),
                    $c->get(MediaDerivativeHandler::class),
                    $c->get(MediaCreateHandler::class),
                    $c->get(Config::class),
                    $c->get(ToolConfigService::class),
                );
            },

            HandoverServiceInterface::class => static function (ContainerInterface $c): HandoverServiceInterface {
                // Closure defers OrchestratorInterface resolution until HandoverService::handover()
                // is called. Direct injection would create a cycle: Orchestrator → tool_instances
                // → SubAgentTool → HandoverService → Orchestrator. Same pattern as SeedCommand.
                return new HandoverService(
                    static fn(): OrchestratorInterface => $c->get(OrchestratorInterface::class),
                    $c->has(PrincipalService::class) ? $c->get(PrincipalService::class) : null,
                    $c->get(PrincipalResolver::class),
                );
            },

            SubAgentServiceInterface::class => static function (ContainerInterface $c): SubAgentServiceInterface {
                // Same circular-dependency avoidance as HandoverService: the
                // Orchestrator takes the tool instance list (which includes
                // SubAgentTool), so we resolve it lazily when spawn() is called.
                return new SubAgentService(
                    static fn(): OrchestratorInterface => $c->get(OrchestratorInterface::class),
                    $c->has(MercurePublisherInterface::class) ? $c->get(MercurePublisherInterface::class) : null,
                    $c->has(PrincipalService::class) ? $c->get(PrincipalService::class) : null,
                    $c->get(PrincipalResolver::class),
                );
            },
        ];
    }

    private static function extensionDefinitions(): array
    {
        return [
            PluginManager::class => static function (ContainerInterface $c): PluginManager {
                // The production closure builds a Symfony Process with the argv, cwd,
                // and a 120s timeout. Tests substitute a fake via the same closure
                // seam in PluginManager's constructor.
                $processFactory = static function (array $argv, string $cwd): object {
                    $process = new Process($argv, $cwd);
                    $process->setTimeout(PluginManager::TIMEOUT_SECONDS);
                    return $process;
                };

                $composerBinary = $c->get(Config::class)->getString('SPORA_COMPOSER_BINARY');

                return new PluginManager(
                    $c->get(LoggerInterface::class),
                    $processFactory,
                    $c->get(Paths::class),
                    ($composerBinary === null || $composerBinary === '') ? 'composer' : $composerBinary,
                );
            },

            // `worker_runtime_mode` is read as a raw string by the env-binding
            // above; parse it into the enum here so consumers can inject
            // WorkerRuntimeMode directly without re-validating. Unknown
            // values fall back to Server — operators can fix the env later
            // without bringing the app down.
            WorkerRuntimeMode::class => static function (ContainerInterface $c): WorkerRuntimeMode {
                $raw = $c->get(Config::class)->getString('SPORA_WORKER_RUNTIME_MODE') ?? 'server';
                return WorkerRuntimeMode::tryFrom($raw) ?? WorkerRuntimeMode::Server;
            },
        ];
    }

    private static function orchestratorDefinitions(): array
    {
        // Thin shim. The orchestrator slice needs several helpers to keep each
        // one under the S138 function-length limit, so it lives in its own
        // class rather than costing ContainerDefinitions method-count budget
        // against the S1448 ceiling.
        return OrchestratorContainerBindings::all();
    }

    private static function consoleCommandDefinitions(): array
    {
        return [
            SetupCommand::class => static function (ContainerInterface $c): SetupCommand {
                return new SetupCommand(
                    $c->get(Database::class),
                    $c->get(DatabaseSchemaInstaller::class),
                    $c->get(AuthService::class),
                    $c->get(MailTemplateSyncService::class),
                    $c->get(AgentTemplateImporter::class),
                );
            },

            SeedCommand::class => static function (ContainerInterface $c): SeedCommand {
                return new SeedCommand(
                    $c->get(Database::class),
                    static fn(): AuthService => $c->get(AuthService::class),
                    $c->get(MailTemplateSyncService::class),
                    $c->get(AgentTemplateImporter::class),
                    $c->get(Paths::class),
                    $c,
                );
            },

            RepairAdminCommand::class => static function (ContainerInterface $c): RepairAdminCommand {
                return new RepairAdminCommand($c->get(Database::class));
            },

            AuditOperationOverridesCommand::class => static function (ContainerInterface $c): AuditOperationOverridesCommand {
                return new AuditOperationOverridesCommand($c->get(Database::class));
            },

            MailTemplatesSyncCommand::class => static function (ContainerInterface $c): MailTemplatesSyncCommand {
                return new MailTemplatesSyncCommand(
                    $c->get(MailTemplateSyncService::class),
                    $c->get(EmailTemplateLoader::class),
                );
            },

            WorkerRunCommand::class => static function (ContainerInterface $c): WorkerRunCommand {
                return new WorkerRunCommand(
                    $c->get(Database::class),
                    $c->get(OrchestratorInterface::class),
                    $c->get(LoggerInterface::class),
                    $c,
                    $c->get(MercurePublisherInterface::class),
                    $c->get(NotificationService::class),
                    $c->get(Paths::class),
                    $c->get(WorkerRuntimeMode::class),
                );
            },

            TaskRunCommand::class => static function (ContainerInterface $c): TaskRunCommand {
                return new TaskRunCommand(
                    $c->get(Database::class),
                    $c,
                    $c->get(MercurePublisherInterface::class),
                    $c->get(WorkerRuntimeMode::class),
                );
            },

            PluginInstallCommand::class => static function (ContainerInterface $c): PluginInstallCommand {
                return new PluginInstallCommand(
                    $c->get(PluginManager::class),
                );
            },

            PluginUninstallCommand::class => static function (ContainerInterface $c): PluginUninstallCommand {
                return new PluginUninstallCommand(
                    $c->get(PluginManager::class),
                );
            },

            PluginListCommand::class => static function (ContainerInterface $c): PluginListCommand {
                return new PluginListCommand(
                    $c->get(PluginManager::class),
                );
            },

            PluginUpdateCommand::class => static function (ContainerInterface $c): PluginUpdateCommand {
                return new PluginUpdateCommand(
                    $c->get(PluginManager::class),
                );
            },

            AssetGcCommand::class => static function (ContainerInterface $c): AssetGcCommand {
                return new AssetGcCommand(
                    $c->get(Paths::class),
                );
            },

            MediaArchiveListCommand::class => static function (ContainerInterface $c): MediaArchiveListCommand {
                return new MediaArchiveListCommand(
                    $c->get(MediaArchiveService::class),
                );
            },

            MediaArchiveGcCommand::class => static function (ContainerInterface $c): MediaArchiveGcCommand {
                return new MediaArchiveGcCommand(
                    $c->get(MediaArchiveService::class),
                    $c->get(Paths::class),
                );
            },
        ];
    }
}
