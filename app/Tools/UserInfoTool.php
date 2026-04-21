<?php

declare(strict_types=1);

namespace Spora\Tools;

use Illuminate\Support\Carbon;
use Spora\Models\Agent;
use Spora\Models\User;
use Spora\Services\ToolConfigService;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\Traits\HasOperations;
use Spora\Tools\ValueObjects\ToolResult;

#[Tool(
    name: 'user_info',
    description: 'Access the users personal information, locations, and health data.',
    displayName: 'User Info',
    category: 'data',
)]
#[ToolOperation(name: 'get_base_data',    description: 'Get the users base profile data', enabledByDefault: true,  requiresApprovalByDefault: false)]
#[ToolOperation(name: 'get_locations',    description: 'Get the users saved locations',   enabledByDefault: true,  requiresApprovalByDefault: false)]
#[ToolOperation(name: 'get_health_data', description: 'Get the users health measurements', enabledByDefault: false, requiresApprovalByDefault: true)]
#[ToolSetting(key: 'user_info.health_data.enabled', label: 'Enable Health Data', type: 'toggle', scope: 'agent', description: 'Allow the agent to access health measurements', default: false)]
#[ToolParameter(name: 'action', type: 'string', description: 'The operation to perform: get_base_data, get_locations, get_health_data', required: true, enum: ['get_base_data', 'get_locations', 'get_health_data'])]
final class UserInfoTool implements ToolInterface
{
    use HasOperations;

    private const KEY_HEALTH_ENABLED = 'user_info.health_data.enabled';

    public function __construct(
        private readonly ToolConfigService $configService,
    ) {}

    private function getUser(int $agentId): ?User
    {
        /** @var Agent|null $agent */
        $agent = Agent::find($agentId);

        /** @var User|null $user */
        $user = $agent?->user;

        return $user;
    }

    public function execute(array $arguments, int $agentId): ToolResult
    {
        $operation = $this->getOperationName($arguments);

        return match ($operation) {
            'get_base_data'    => $this->getBaseData($agentId),
            'get_locations'    => $this->getLocations($agentId),
            'get_health_data'  => $this->getHealthData($arguments, $agentId),
            default            => new ToolResult(false, "Unknown user info operation: {$operation}"),
        };
    }

    public function describeAction(array $arguments): string
    {
        $operation = $this->getOperationName($arguments);

        return match ($operation) {
            'get_base_data'    => 'Read the users base profile data (name, date of birth, about me)',
            'get_locations'    => 'Read the users saved locations',
            'get_health_data'  => 'Read the users health measurements (height, weight)',
            default            => 'Access user information',
        };
    }

    public function getParametersSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'action' => [
                    'type'        => 'string',
                    'description' => 'The operation to perform: get_base_data, get_locations, get_health_data',
                    'enum'        => ['get_base_data', 'get_locations', 'get_health_data'],
                ],
            ],
            'required' => ['action'],
        ];
    }

    private function getBaseData(int $agentId): ToolResult
    {
        $user = $this->getUser($agentId);
        if ($user === null) {
            return new ToolResult(false, 'User not found.');
        }

        $output = "Base Data:\n";
        $output .= "Name: " . ($user->name ?: '(not set)') . "\n";
        $output .= "Date of Birth: " . ($user->date_of_birth ? Carbon::parse($user->date_of_birth)->format('Y-m-d') : '(not set)') . "\n";
        $output .= "About Me: " . ($user->about_me ?: '(not set)') . "\n";

        return new ToolResult(true, $output);
    }

    private function getLocations(int $agentId): ToolResult
    {
        $locations = $this->getUser($agentId)->locations ?? collect();

        if ($locations->isEmpty()) {
            return new ToolResult(true, 'No locations saved.');
        }

        $output = "Locations:\n\n";
        foreach ($locations as $loc) {
            $default = $loc->is_default ? ' (default)' : '';
            $output .= "— {$loc->name}{$default}\n";
            $output .= "  Address: {$loc->address}\n\n";
        }

        return new ToolResult(true, $output);
    }

    private function getHealthData(array $arguments, int $agentId): ToolResult
    {
        $settings = $this->configService->getEffectiveSettings(static::class, $agentId);
        if (($settings[self::KEY_HEALTH_ENABLED] ?? false) !== true) {
            return new ToolResult(false, 'Health data access is not enabled for this agent. Please enable the "Enable Health Data" setting.');
        }

        $user = $this->getUser($agentId);
        if ($user === null) {
            return new ToolResult(false, 'User not found.');
        }

        $output = "Health Data:\n";
        $output .= "Height: " . ($user->height_cm !== null ? "{$user->height_cm} cm" : '(not set)') . "\n";
        $output .= "Weight: " . ($user->weight_kg !== null ? "{$user->weight_kg} kg" : '(not set)') . "\n";

        return new ToolResult(true, $output);
    }
}
