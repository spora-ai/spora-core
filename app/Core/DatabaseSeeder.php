<?php

declare(strict_types=1);

namespace Spora\Core;

use Delight\Auth\Role;
use RuntimeException;
use Spora\AgentTemplates\AgentTemplateImporter;
use Spora\Auth\AuthService;
use Spora\Models\MailTemplate;
use Spora\Models\User;
use Spora\Services\EmailTemplateLoader;

/**
 * Seeds the database with a default Admin user and an integrated Agent.
 * Useful for bootstrapping the local development environment for the frontend.
 *
 * The "Spora Core Agent" is no longer hard-coded — it's installed from the
 * built-in `core/core-assistant` template so the seed stays in sync with whatever
 * the upstream template declares. Update the template to evolve the seed.
 * The id is namespaced by source (`core/...`) so it can never collide
 * with a plugin template.
 */
final class DatabaseSeeder
{
    /**
     * Template id installed by {@see run()} when no Spora Core Agent exists
     * yet. The template must be shippable from one of the directories
     * {@see Paths::agentTemplatesPaths()} reports.
     */
    public const CORE_AGENT_TEMPLATE_ID = 'core/core-assistant';

    public function __construct(
        private readonly AuthService $authService,
        private readonly EmailTemplateLoader $templateLoader,
        private readonly AgentTemplateImporter $templateImporter,
    ) {}

    public function run(): void
    {
        $mailTemplates = $this->templateLoader->getAll();

        foreach ($mailTemplates as $template) {
            MailTemplate::firstOrCreate(
                ['name' => $template['name']],
                $template,
            );
        }
        echo "Seeded " . count($mailTemplates) . " Mail Templates.\n";

        // Bootstrap admin. Insert-only: a deleted or renamed admin is left alone
        // so the seeder cannot be used as a backdoor. Repairs go through
        //    `bin/spora db:repair-admin`.
        $user = User::where('email', 'admin@spora.local')->first();
        if ($user === null) {
            $userId = $this->authService->register('admin@spora.local', 'password', 'Admin', true);
            User::where('id', $userId)->update([
                'roles_mask' => Role::ADMIN,
                'status'     => 1,
            ]);
            echo "Created Admin User: admin@spora.local / password\n";
        } else {
            $userId = $user->id;
            echo "Admin user already exists.\n";
        }

        // 3. Install the Spora Core Agent from the built-in template if missing.
        //    Keyed on agent name so a template id rename leaves the agent in place.
        $existing = \Spora\Models\Agent::where('user_id', $userId)
            ->where('name', 'Spora Core Agent')
            ->first();

        if ($existing === null) {
            try {
                $result = $this->templateImporter->applyTemplate($userId, self::CORE_AGENT_TEMPLATE_ID);
                echo "Created Spora Core Agent from '" . self::CORE_AGENT_TEMPLATE_ID . "' template with "
                    . count($result->toolsEnabled) . " tools.\n";
                foreach ($result->warnings as $w) {
                    echo "  - [{$w['code']}] {$w['message']}\n";
                }
            } catch (RuntimeException $e) {
                echo "Could not apply template '" . self::CORE_AGENT_TEMPLATE_ID . "': {$e->getMessage()}\n";
            }
        } else {
            echo "Spora Core Agent already exists.\n";
        }

        echo "Database Seeding Complete!\n";
    }
}
