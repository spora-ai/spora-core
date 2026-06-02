<?php

declare(strict_types=1);

namespace Spora\Tools;

use Illuminate\Support\Carbon;
use Spora\Models\Agent;
use Spora\Models\User;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Accesses the authenticated user's personal information including
 * profile data, saved locations, and health measurements.
 */
#[Tool(
    name: 'user_info',
    description: 'Access the users personal information, locations, and health data.',
    displayName: 'User Info',
    category: 'data',
)]
#[ToolOperation(name: 'get_base_data', description: 'Get the users base profile data', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'get_locations', description: 'Get the users saved locations', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'get_health_data', description: 'Get the users health measurements', enabledByDefault: false, requiresApprovalByDefault: true)]
final class UserInfoTool extends AbstractTool
{
    private function getUser(int $agentId): ?User
    {
        /** @var Agent|null $agent */
        $agent = Agent::find($agentId);

        /** @var User|null $user */
        $user = $agent?->user;

        return $user;
    }

    public function execute(array $arguments, int $agentId, ?int $userId = null): ToolResult
    {
        $operation = $this->getOperationName($arguments);

        return match ($operation) {
            'get_base_data'    => $this->getBaseData($agentId),
            'get_locations'    => $this->getLocations($agentId),
            'get_health_data'  => $this->getHealthData($agentId),
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
        $user = $this->getUser($agentId);
        if ($user === null) {
            return new ToolResult(false, 'User not found.');
        }

        $locations = $user->locations ?? collect();

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

    private function getHealthData(int $agentId): ToolResult
    {
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
