<?php

declare(strict_types=1);

namespace Spora\Tools;

use Illuminate\Support\Carbon;
use Spora\Models\Group;
use Spora\Models\Principal;
use Spora\Models\User;
use Spora\Services\PrincipalContext;
use Spora\Services\PrincipalResolver;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Accesses the principal behind the agent: a user's profile, locations and
 * health measurements, or a group's name, description, information and
 * locations.
 */
#[Tool(
    name: 'principal_info',
    description: 'Access the principal behind this agent: personal details, saved locations and health data for a user, or the name, description, information and locations for a group.',
    displayName: 'Principal Info',
    category: 'data',
    icon: 'user',
)]
#[ToolOperation(name: 'get_base_data', description: 'Get the base data of the principal: its type, name and description, plus the date of birth for a user.', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'get_locations', description: 'Get the locations saved for the principal.', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'get_health_data', description: 'Get the health measurements of the principal. Only a user principal has them.', enabledByDefault: false, requiresApprovalByDefault: true)]
final class PrincipalInfoTool extends AbstractTool
{
    private const ERR_PRINCIPAL_NOT_FOUND = 'Principal not found.';

    private const ERR_HEALTH_DATA_NOT_AVAILABLE = 'Health data is only available for user principals. This agent belongs to a group.';

    private const NOT_SET = '(not set)';

    public function execute(
        array $arguments,
        int $agentId,
        ?int $taskId = null,
        ?PrincipalContext $context = null,
    ): ToolResult {
        $operation = $this->getOperationName($arguments);

        $principal = $this->resolvePrincipal($agentId, $taskId, $context);
        if ($principal === null) {
            return new ToolResult(false, self::ERR_PRINCIPAL_NOT_FOUND);
        }

        return match ($operation) {
            'get_base_data'   => $this->getBaseData($principal),
            'get_locations'   => $this->getLocations($principal),
            'get_health_data' => $this->getHealthData($principal),
            default           => new ToolResult(false, "Unknown principal info operation: {$operation}"),
        };
    }

    public function describeAction(array $arguments): string
    {
        $operation = $this->getOperationName($arguments);

        return match ($operation) {
            'get_base_data'   => 'Read the base data of the principal (name, description, date of birth)',
            'get_locations'   => 'Read the locations saved for the principal',
            'get_health_data' => 'Read the health measurements of the principal (height, weight)',
            default           => 'Access principal information',
        };
    }

    /**
     * The principal the agent speaks for.
     *
     * The execution's context, else the agent's own principal — the same
     * fallback the skill tool uses, so a scheduled run with no triggering
     * member still resolves. The row is read here rather than taken from
     * the context's type, because the row is what says which entity the
     * principal stands for.
     */
    private function resolvePrincipal(int $agentId, ?int $taskId, ?PrincipalContext $context): ?Principal
    {
        $principalId = $context !== null
            ? $context->principalId
            : (new PrincipalResolver())->resolveForToolExecute($agentId, $taskId)->principalId;

        if ($principalId <= 0) {
            return null;
        }

        return Principal::find($principalId);
    }

    private function getBaseData(Principal $principal): ToolResult
    {
        if ($principal->type === Principal::TYPE_GROUP) {
            $group = Group::find((int) $principal->group_id);
            if ($group === null) {
                return new ToolResult(false, self::ERR_PRINCIPAL_NOT_FOUND);
            }

            $output = "Principal Type: " . Principal::TYPE_GROUP . "\n";
            $output .= "Name: " . ($group->name ?: self::NOT_SET) . "\n";
            $output .= "Description: " . ($group->description ?: self::NOT_SET) . "\n";
            $output .= "Information:\n" . ($group->information ?: self::NOT_SET) . "\n";

            return new ToolResult(true, $output);
        }

        $user = User::find((int) $principal->user_id);
        if ($user === null) {
            return new ToolResult(false, self::ERR_PRINCIPAL_NOT_FOUND);
        }

        $output = "Principal Type: " . Principal::TYPE_USER . "\n";
        $output .= "Name: " . ($user->name ?: self::NOT_SET) . "\n";
        $output .= "Description: " . ($user->about_me ?: self::NOT_SET) . "\n";
        $output .= "Date of Birth: " . ($user->date_of_birth ? Carbon::parse($user->date_of_birth)->format('Y-m-d') : self::NOT_SET) . "\n";

        return new ToolResult(true, $output);
    }

    private function getLocations(Principal $principal): ToolResult
    {
        $locations = $principal->locations()->orderBy('id')->get();

        if ($locations->isEmpty()) {
            return new ToolResult(true, 'No locations saved.');
        }

        $output = "Locations:\n\n";
        foreach ($locations as $location) {
            $default = $location->is_default ? ' (default)' : '';
            $output .= "— {$location->name}{$default}\n";
            $output .= "  Address: {$location->address}\n\n";
        }

        return new ToolResult(true, $output);
    }

    private function getHealthData(Principal $principal): ToolResult
    {
        // A group has no body to measure. Saying so beats an empty field,
        // which the model reads as "no measurement recorded".
        if ($principal->type !== Principal::TYPE_USER) {
            return new ToolResult(false, self::ERR_HEALTH_DATA_NOT_AVAILABLE);
        }

        $user = User::find((int) $principal->user_id);
        if ($user === null) {
            return new ToolResult(false, self::ERR_PRINCIPAL_NOT_FOUND);
        }

        $output = "Health Data:\n";
        $output .= "Height: " . ($user->height_cm !== null ? "{$user->height_cm} cm" : self::NOT_SET) . "\n";
        $output .= "Weight: " . ($user->weight_kg !== null ? "{$user->weight_kg} kg" : self::NOT_SET) . "\n";

        return new ToolResult(true, $output);
    }
}
