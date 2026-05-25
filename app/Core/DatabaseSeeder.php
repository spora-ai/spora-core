<?php

declare(strict_types=1);

namespace Spora\Core;

use Delight\Auth\Role;
use ReflectionClass;
use Spora\Auth\AuthService;
use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Models\MailTemplate;
use Spora\Models\User;
use Spora\Services\EmailTemplateLoader;
use Spora\Tools\AgentMemoryTool;
use Spora\Tools\CalculatorTool;
use Spora\Tools\CurrentTimeTool;
use Spora\Tools\GlobalMemoryTool;

/**
 * Seeds the database with a default Admin user and an integrated Agent.
 * Useful for bootstrapping the local development environment for the frontend.
 */
final class DatabaseSeeder
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly EmailTemplateLoader $templateLoader,
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

        // 2. Create or ensure Admin user exists.
        $user = User::where('email', 'admin@spora.local')->first();
        if ($user === null) {
            $userId = $this->authService->register('admin@spora.local', 'password');
            $user   = User::findOrFail($userId);
            echo "Created Admin User: admin@spora.local / password\n";
        } else {
            echo "Admin user already exists.\n";
            $userId = $user->id;
        }

        // 2b. Grant ADMIN role to the user and mark as verified (seeder admin bypasses email verification).
        User::where('id', $userId)->update([
            'roles_mask' => Role::ADMIN,
            'verified' => 1,
            'status' => 1,
        ]);

        // 3. Create or ensure default Agent exists.
        $agent = Agent::where('user_id', $userId)->where('name', 'Spora Core Agent')->first();
        if ($agent === null) {
            $agent = Agent::create([
                'user_id'      => $userId,
                'name'         => 'Spora Core Agent',
                'max_steps'    => 10,
                'is_active'    => true,
            ]);
            echo "Created Default Agent.\n";
        } else {
            echo "Default Agent already exists.\n";
        }

        // 4. Define the base tools every functional agent needs.
        $toolsToEnable = [
            CurrentTimeTool::class,
            CalculatorTool::class,
            AgentMemoryTool::class,
            GlobalMemoryTool::class,
        ];

        foreach ($toolsToEnable as $toolClass) {
            $ref = new ReflectionClass($toolClass);
            $attrs = $ref->getAttributes(\Spora\Tools\Attributes\Tool::class);
            $toolName = $attrs[0]->newInstance()->name;

            AgentTool::updateOrCreate(
                ['agent_id' => $agent->id, 'tool_class' => $toolClass],
                ['tool_name' => $toolName],
            );
        }

        echo "Enabled " . count($toolsToEnable) . " Base Tools for the Agent.\n";

        echo "Database Seeding Complete!\n";
    }
}
