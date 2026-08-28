<?php

declare(strict_types=1);

namespace Spora\Core;

use League\CommonMark\Environment\Environment as CommonMarkEnvironment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Spora\Agents\Orchestrator;
use Spora\Agents\OrchestratorConfig;
use Spora\Agents\OrchestratorInterface;
use Spora\Agents\ValueObjects\WorkerRuntimeMode;
use Spora\AgentTemplates\AgentTemplateAgentCreator;
use Spora\AgentTemplates\AgentTemplateExporter;
use Spora\AgentTemplates\AgentTemplateImporter;
use Spora\AgentTemplates\AgentTemplateScanner;
use Spora\AgentTemplates\AgentTemplateSettingsApplier;
use Spora\AgentTemplates\AgentTemplateToolsApplier;
use Spora\AgentTemplates\AgentTemplateValidator;
use Spora\Auth\AuthService;
use Spora\Console\Worker\ScheduledRunProcessor;
use Spora\Console\Worker\WorkerReaper;
use Spora\Drivers\DriverFactory;
use Spora\Extensions\AppLoader;
use Spora\Http\WorkerController;
use Spora\Models\MailTemplate;
use Spora\Plugins\PluginLoader;
use Spora\Services\AgentPictures\AgentPictureService;
use Spora\Services\AgentServiceInterface;
use Spora\Services\DbRateLimiter;
use Spora\Services\EmailTemplateLoader;
use Spora\Services\HousekeepingLock;
use Spora\Services\LLMConfigPreferences;
use Spora\Services\LLMConfigService;
use Spora\Services\Mail\MailTemplateRenderer;
use Spora\Services\Mail\MailTemplateSyncService;
use Spora\Services\MailTemplateService;
use Spora\Services\MailTemplateServiceInterface;
use Spora\Services\MercurePublisher;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\NotificationService;
use Spora\Services\NotificationServiceInterface;
use Spora\Services\PrincipalResolver;
use Spora\Services\PromptTemplateService;
use Spora\Services\PromptTemplateServiceInterface;
use Spora\Services\ScheduledRunService;
use Spora\Services\ScheduledRunServiceInterface;
use Spora\Services\SystemMailer;
use Spora\Services\ToolCallSerializer;
use Spora\Services\ToolConfigSchemaInspector;
use Spora\Services\ToolConfigService;
use Spora\Skills\SkillScanner;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * DI definitions for the orchestrator slice: the Orchestrator itself, the
 * client-worker runtime services, the Mercure/notification/mail stack, and the
 * agent-template + skill scanners.
 *
 * Lives outside {@see ContainerDefinitions} so that class stays within the
 * S1448 method-count ceiling — the slice needs several helpers to keep each
 * one under the S138 function-length limit, and hosting them here means those
 * helpers cost ContainerDefinitions nothing.
 */
final class OrchestratorContainerBindings
{
    public static function all(): array
    {
        return [
            ...self::orchestrator(),
            ...self::workerRuntime(),
            ...self::notifications(),
            ...self::templates(),
        ];
    }

    private static function orchestrator(): array
    {
        return [
            OrchestratorInterface::class => static function (ContainerInterface $c): OrchestratorInterface {
                return new Orchestrator(
                    $c->get(DriverFactory::class),
                    new OrchestratorConfig(
                        llmConfigService: $c->get(LLMConfigService::class),
                        toolInstances: $c->get('tool_instances'),
                        logger: $c->get(LoggerInterface::class),
                        notificationService: $c->get(NotificationService::class),
                        pluginLoader: $c->get(PluginLoader::class),
                        mercure: $c->get(MercurePublisherInterface::class),
                        toolConfigService: $c->get(ToolConfigService::class),
                        toolCallSerializer: $c->get(ToolCallSerializer::class),
                        agentService: $c->get(AgentServiceInterface::class),
                        principalPreferences: $c->get(LLMConfigPreferences::class),
                    ),
                    $c->get(PrincipalResolver::class),
                    $c->get(AuthService::class),
                );
            },
        ];
    }

    /**
     * Single-instance services for the client-worker housekeeping path.
     * DbRateLimiter + HousekeepingLock are stateless wrappers around the
     * `ratelimit_hits` and `worker_housekeeping_locks` tables (see migrations
     * 0071/0072). WorkerReaper and ScheduledRunProcessor are also used by the
     * CLI `worker:run --scheduled` flow but were constructed inline in
     * WorkerRunCommand before this slice — they now live in the container so
     * the HTTP controller and the CLI share the same singletons.
     */
    private static function workerRuntime(): array
    {
        return [
            DbRateLimiter::class => static fn(): DbRateLimiter => new DbRateLimiter(),

            HousekeepingLock::class => static fn(): HousekeepingLock => new HousekeepingLock(),

            WorkerReaper::class => static function (ContainerInterface $c): WorkerReaper {
                return new WorkerReaper(
                    $c->get(LoggerInterface::class),
                    $c->get(NotificationService::class),
                );
            },

            ScheduledRunProcessor::class => static function (ContainerInterface $c): ScheduledRunProcessor {
                return new ScheduledRunProcessor(
                    $c->get(OrchestratorInterface::class),
                    $c->get(LoggerInterface::class),
                    $c->get(MercurePublisherInterface::class),
                    $c->get(NotificationService::class),
                );
            },

            WorkerController::class => static function (ContainerInterface $c): WorkerController {
                return new WorkerController(
                    $c->get(AuthService::class),
                    $c->get(WorkerRuntimeMode::class),
                    $c->get(DbRateLimiter::class),
                    $c->get(HousekeepingLock::class),
                    $c->get(WorkerReaper::class),
                    $c->get(ScheduledRunProcessor::class),
                    (int) ($c->get('config')['worker_stale_minutes'] ?? 60),
                    (int) ($c->get('config')['tick_lease_seconds'] ?? 600),
                );
            },
        ];
    }

    private static function notifications(): array
    {
        return [
            MercurePublisherInterface::class => static function (ContainerInterface $c): MercurePublisherInterface {
                $config   = $c->get('config');
                $hubUrl   = $config['mercure_publish_url'] ?? $config['mercure_url'] ?? null;
                $jwtKey   = $config['mercure_jwt_key'] ?? null;
                $client   = $c->get(HttpClientInterface::class);

                return new MercurePublisher($client, $hubUrl, $jwtKey, $c->get(LoggerInterface::class));
            },

            NotificationService::class => static function (ContainerInterface $c): NotificationService {
                return new NotificationService(
                    $c->get(MercurePublisherInterface::class),
                    $c->get(SystemMailer::class),
                    $c->get('config'),
                );
            },

            NotificationServiceInterface::class => static function (ContainerInterface $c): NotificationServiceInterface {
                return $c->get(NotificationService::class);
            },

            SystemMailer::class => static function (ContainerInterface $c): SystemMailer {
                return new SystemMailer(
                    $c->get('config'),
                    $c->get(LoggerInterface::class),
                    $c->get(MailTemplateRenderer::class),
                );
            },

            // Markdown → HTML pipeline. CommonMarkCore + GFM gives tables,
            // strikethrough, autolinks, task lists. Used by SystemMailer and
            // by anything that renders a MailTemplate body.
            CommonMarkEnvironment::class => static function (): CommonMarkEnvironment {
                $env = new CommonMarkEnvironment();
                $env->addExtension(new CommonMarkCoreExtension());
                $env->addExtension(new GithubFlavoredMarkdownExtension());
                return $env;
            },

            MarkdownConverter::class => static function (ContainerInterface $c): MarkdownConverter {
                return new MarkdownConverter($c->get(CommonMarkEnvironment::class));
            },

            MailTemplateRenderer::class => static function (ContainerInterface $c): MailTemplateRenderer {
                return new MailTemplateRenderer($c->get(MarkdownConverter::class));
            },
        ];
    }

    private static function templates(): array
    {
        return [
            AgentTemplateScanner::class => static function (ContainerInterface $c): AgentTemplateScanner {
                $pluginLoader = $c->get(PluginLoader::class);
                $paths = $c->get(Paths::class);

                $appPaths = $c->has(AppLoader::class)
                    ? ($c->get(AppLoader::class)->getApp()?->agentTemplatePaths() ?? [])
                    : [];

                $directories = array_merge(
                    $paths->agentTemplatesPaths(),
                    $pluginLoader->agentTemplatePaths(),
                    $appPaths,
                );

                return new AgentTemplateScanner($directories);
            },

            AgentTemplateValidator::class => static fn(): AgentTemplateValidator => new AgentTemplateValidator(),

            ToolConfigSchemaInspector::class => static function (ContainerInterface $c): ToolConfigSchemaInspector {
                return new ToolConfigSchemaInspector(
                    [],
                    $c->get(PrincipalResolver::class),
                );
            },

            // Skills are scanned in priority order: project, then framework,
            // then each plugin. The `source` label on each root is what
            // SkillScanner uses to bucket same-named skills (see
            // {@see SkillScanner::scan()}) and to tag the resulting Skill objects.
            SkillScanner::class => static function (ContainerInterface $c): SkillScanner {
                $paths = $c->get(Paths::class);

                $roots = [];

                $project = $paths->base('skills');
                if (is_dir($project)) {
                    $roots[] = ['path' => $project, 'source' => 'project'];
                }

                $framework = $paths->framework('skills');
                if (is_dir($framework)) {
                    $roots[] = ['path' => $framework, 'source' => 'core'];
                }

                foreach ($c->get(PluginLoader::class)->skillPaths() as $root) {
                    if (is_dir($root['path'])) {
                        $roots[] = $root;
                    }
                }

                return new SkillScanner($roots);
            },

            AgentTemplateImporter::class => static function (ContainerInterface $c): AgentTemplateImporter {
                return new AgentTemplateImporter(
                    $c->get(ToolConfigService::class),
                    $c->get(PluginLoader::class),
                    $c->get(Paths::class),
                    $c->get(AgentTemplateToolsApplier::class),
                    $c->get(AgentTemplateAgentCreator::class),
                    $c->get(AgentPictureService::class),
                );
            },

            AgentTemplateToolsApplier::class => static function (ContainerInterface $c): AgentTemplateToolsApplier {
                return new AgentTemplateToolsApplier(
                    $c->get(ToolConfigService::class),
                    $c->get(AgentTemplateSettingsApplier::class),
                );
            },

            AgentTemplateAgentCreator::class => static fn(): AgentTemplateAgentCreator => new AgentTemplateAgentCreator(),

            AgentTemplateSettingsApplier::class => static function (ContainerInterface $c): AgentTemplateSettingsApplier {
                return new AgentTemplateSettingsApplier(
                    $c->get(ToolConfigService::class),
                    $c->get(SkillScanner::class),
                );
            },

            AgentTemplateExporter::class => static fn(ContainerInterface $c): AgentTemplateExporter => new AgentTemplateExporter(
                $c->get(PluginLoader::class),
                $c->get(ToolConfigService::class),
                $c->get(ToolConfigSchemaInspector::class),
                $c->get(PrincipalResolver::class),
            ),

            MailTemplateServiceInterface::class => static function (ContainerInterface $c): MailTemplateServiceInterface {
                return new MailTemplateService($c->get(MailTemplateRenderer::class));
            },

            MailTemplateSyncService::class => static function (ContainerInterface $c): MailTemplateSyncService {
                return new MailTemplateSyncService(
                    $c->get(EmailTemplateLoader::class),
                    $c->get(MailTemplateServiceInterface::class),
                );
            },

            PromptTemplateServiceInterface::class => static fn(): PromptTemplateServiceInterface => new PromptTemplateService(),

            EmailTemplateLoader::class => static function (ContainerInterface $c): EmailTemplateLoader {
                return new EmailTemplateLoader($c->get(Paths::class));
            },

            ScheduledRunServiceInterface::class => static function (ContainerInterface $c): ScheduledRunServiceInterface {
                return new ScheduledRunService(
                    $c->get(OrchestratorInterface::class),
                    $c->get(MercurePublisherInterface::class),
                );
            },

            MailTemplate::class => static fn(): MailTemplate => new MailTemplate(),
        ];
    }
}
