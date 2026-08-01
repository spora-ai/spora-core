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
        // 1. Seed default mail templates first (registration triggers verification emails that need them).
        $mailTemplates = $this->templateLoader->getAll();

        foreach ($mailTemplates as $template) {
            MailTemplate::firstOrCreate(
                ['name' => $template['name']],
                $template,
            );
        }
        echo "Seeded " . count($mailTemplates) . " Mail Templates.\n";

        // 2. Create or ensure Admin user exists. The seeder bypasses the email
        //    verification flow — the admin must be immediately usable, so
        //    AuthService::register() is asked to persist `verified = 1` in the
        //    same write that creates the row.
        $user = User::where('email', 'admin@spora.local')->first();
        if ($user === null) {
            $userId = $this->authService->register('admin@spora.local', 'password', 'Admin', true);
            echo "Created Admin User: admin@spora.local / password\n";
        } else {
            echo "Admin user already exists.\n";
            $userId = $user->id;
        }

        // 2b. Re-assert the bootstrap admin's role + verification + status on
        //    every run. The seeder is the authority on the seeded admin's state:
        //    AuthService::register() persists verified=1 when the row is first
        //    created, but on container restarts against a persistent volume the
        //    admin may have been persisted with stale values by an older
        //    spora-core release. Re-asserting here is the self-healing path.
        User::where('id', $userId)->update([
            'roles_mask' => Role::ADMIN,
            'verified'   => 1,
            'status'     => 1,
        ]);

        // 3. Install the Spora Core Agent from the built-in template, if missing.
        //    Recipes are files, not database entities, so we key on the
        //    agent name to keep this seeder resilient to template renames.
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
