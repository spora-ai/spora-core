<?php

declare(strict_types=1);

namespace Spora\Core;

use ReflectionClass;
use Spora\Auth\AuthService;
use Spora\Drivers\LLMConfiguration;
use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Models\User;
use Spora\Tools\CalculatorTool;
use Spora\Tools\CurrentTimeTool;
use Spora\Tools\ScratchpadTool;

/**
 * Seeds the database with a default Admin user and an integrated Agent.
 * Useful for bootstrapping the local development environment for the frontend.
 */
final class DatabaseSeeder
{
    public function __construct(
        private readonly AuthService $authService,
    ) {}

    public function run(): void
    {
        // 1. Create or ensure Admin user exists.
        $user = User::where('email', 'admin@spora.local')->first();
        if ($user === null) {
            $userId = $this->authService->register('admin@spora.local', 'password');
            $user   = User::findOrFail($userId);
            echo "Created Admin User: admin@spora.local / password\n";
        } else {
            echo "Admin user already exists.\n";
            $userId = $user->id;
        }

        // 2. Create or ensure default Agent exists.
        $agent = Agent::where('user_id', $userId)->where('name', 'Spora Core Agent')->first();
        if ($agent === null) {
            $agent = Agent::create([
                'user_id'      => $userId,
                'name'         => 'Spora Core Agent',
                'llm_provider' => 'anthropic',
                'llm_model'    => 'claude-3-7-sonnet-20250219',
                'max_steps'    => 10,
                'is_active'    => true,
            ]);
            echo "Created Default Agent.\n";
        } else {
            echo "Default Agent already exists.\n";
        }

        // 3. Define the base tools every functional agent needs.
        $toolsToEnable = [
            LLMConfiguration::class,
            CurrentTimeTool::class,
            CalculatorTool::class,
            ScratchpadTool::class,
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
