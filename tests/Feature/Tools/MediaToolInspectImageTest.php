<?php

declare(strict_types=1);

use Spora\Agents\Orchestrator;
use Spora\Agents\OrchestratorConfig;
use Spora\Agents\ToolDefinitionBuilder;
use Spora\Core\SecurityManager;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Models\Agent;
use Spora\Models\AgentToolOperationOverride;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\Task;
use Spora\Models\TaskHistory;
use Spora\Services\LLMConfigService;
use Spora\Services\MediaArchive\MediaImageBlocks;
use Spora\Tools\MediaTool;

/**
 * `media inspect_image` — the operation that puts an archive image in front
 * of the model.
 *
 * Two gates stand in front of it and both refuse with a message the model
 * reads: the agent's LLM has to accept image content blocks, and the asset
 * has to be visible under the media tool's `scope` setting.
 *
 * The capability refusal is asserted on the **persisted tool history row**,
 * not on the returned value: the return value never reaches the model, the
 * row does. That is the whole reason the row writer takes the result's
 * attachments.
 */

/**
 * A driver factory whose agent resolves to a real driver, so the capability
 * gate reads the same value the runtime would.
 */
function inspectImageDriverFactory(): DriverFactory
{
    $llmService = new LLMConfigService(
        new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
        [OpenAICompatibleDriver::class],
    );

    return new DriverFactory(new Psr\Log\NullLogger(), $llmService, 60);
}

/**
 * An agent whose LLM is the named model, with the image toggle at the
 * chosen value. A null toggle leaves the key out of the settings, so the
 * driver's model heuristic decides.
 */
function inspectImageAgent(string $model, ?bool $supportsImages): int
{
    $settings = [
        'api_key'  => '',
        'model'    => $model,
        'base_url' => 'https://example.invalid/v1',
        'timeout'  => '60',
    ];
    if ($supportsImages !== null) {
        $settings['supports_image_input'] = $supportsImages ? 'true' : 'false';
    }

    $config = LLMDriverConfiguration::create([
        'principal_id' => null,
        'name'         => 'inspect-image-' . bin2hex(random_bytes(3)),
        'driver_class' => OpenAICompatibleDriver::class,
        'settings'     => json_encode($settings),
        'is_global'    => true,
        'is_default'   => true,
    ]);

    $agentId = seedMediaToolAgent('Inspect Image Agent');
    Agent::query()->where('id', $agentId)->update(['llm_driver_config_id' => $config->id]);

    return $agentId;
}

/**
 * The tool row the model reads on its next turn, for the result of a call.
 */
function inspectImagePersistedRow(int $agentId, Spora\Tools\ValueObjects\ToolResult $result): TaskHistory
{
    $agent = Agent::findOrFail($agentId);
    $task  = Task::create([
        'agent_id'         => $agentId,
        'principal_id'     => (int) $agent->principal_id,
        'trigger_user_id'  => $agent->user_id,
        'status'           => 'RUNNING',
        'user_prompt'      => 'inspect an image',
        'step_count'       => 0,
        'max_steps'        => 10,
    ]);

    $driver = Mockery::mock(Spora\Drivers\LLMDriverInterface::class);
    $factory = Mockery::mock(DriverFactory::class);
    $factory->allows('makeFromAgent')->andReturn($driver);

    $orchestrator = new Orchestrator($factory, new OrchestratorConfig());
    $orchestrator->appendToolResult($task->id, $result, 'call_1', 'media');

    $row = TaskHistory::where('task_id', $task->id)->where('role', 'tool')->first();
    expect($row)->not->toBeNull();

    return $row;
}

describe('MediaTool::inspect_image', function (): void {
    it('attaches the image to the result and names the asset', function (): void {
        $agentId = inspectImageAgent('gpt-4o', true);
        $asset   = seedMediaAsset(agentId: $agentId, userId: 99, payload: "\x89PNG\r\n\x1a\nfake");

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(
            makeMediaToolNonAdminAuth(),
            mediaToolScopeConfig('agent'),
            null,
            null,
            inspectImageDriverFactory(),
        );
        try {
            $result = $tool->execute(
                ['action' => 'inspect_image', 'asset_id' => $asset->id, 'question' => 'Is the axis labelled?'],
                agentId: $agentId,
            );

            expect($result->success)->toBeTrue();
            expect($result->attachments)->toBe([['media_id' => $asset->id, 'kind' => 'image']]);
            expect($result->content)->toContain($asset->id)
                ->and($result->content)->toContain('Attached media asset')
                ->and($result->content)->toContain('Is the axis labelled?');
            expect($result->data['asset_id'])->toBe($asset->id);
        } finally {
            $restore();
        }
    });

    it('puts the capability failure into the tool history row the model reads', function (): void {
        // The return value never reaches the model — the persisted row does.
        // So the assertion is on the row.
        $agentId = inspectImageAgent('gpt-3.5-turbo', false);
        $asset   = seedMediaAsset(agentId: $agentId, userId: 99, payload: 'bytes');

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(
            makeMediaToolNonAdminAuth(),
            mediaToolScopeConfig('agent'),
            null,
            null,
            inspectImageDriverFactory(),
        );
        try {
            $result = $tool->execute(
                ['action' => 'inspect_image', 'asset_id' => $asset->id],
                agentId: $agentId,
            );

            expect($result->success)->toBeFalse();

            $row = inspectImagePersistedRow($agentId, $result);

            expect((string) $row->content)->toContain('Allow images')
                ->and((string) $row->content)->toContain('gpt-3.5-turbo')
                ->and((string) $row->content)->toContain('vision-capable LLM');
            // A refusal attaches nothing: there is no image for the model.
            expect($row->attachments)->toBeNull();
        } finally {
            $restore();
        }
    });

    it('refuses on the capability before it looks for the asset', function (): void {
        // The order matters: an agent whose LLM cannot read an image must
        // hear that, not "asset_id is required" or "not found".
        $agentId = inspectImageAgent('gpt-3.5-turbo', false);

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(
            makeMediaToolNonAdminAuth(),
            mediaToolScopeConfig('agent'),
            null,
            null,
            inspectImageDriverFactory(),
        );
        try {
            $result = $tool->execute(
                ['action' => 'inspect_image', 'asset_id' => '99999999-9999-9999-9999-999999999999'],
                agentId: $agentId,
            );

            expect($result->success)->toBeFalse();
            expect($result->content)->toContain('vision-capable LLM')
                ->and($result->content)->not->toContain('asset_id is required')
                ->and($result->content)->not->toContain('Media asset not found');
        } finally {
            $restore();
        }
    });

    it('refuses an asset that is not a raster image', function (): void {
        $agentId = inspectImageAgent('gpt-4o', true);
        $asset   = seedMediaAsset(agentId: $agentId, userId: 99, mime: 'application/pdf', payload: '%PDF');

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(
            makeMediaToolNonAdminAuth(),
            mediaToolScopeConfig('agent'),
            null,
            null,
            inspectImageDriverFactory(),
        );
        try {
            $result = $tool->execute(
                ['action' => 'inspect_image', 'asset_id' => $asset->id],
                agentId: $agentId,
            );

            expect($result->success)->toBeFalse();
            expect($result->content)->toContain('only reads raster images')
                ->and($result->content)->toContain('application/pdf');
            expect($result->attachments)->toBe([]);
        } finally {
            $restore();
        }
    });

    it('refuses an SVG, which is script-capable rather than raster', function (): void {
        $agentId = inspectImageAgent('gpt-4o', true);
        $asset   = seedMediaAsset(agentId: $agentId, userId: 99, mime: 'image/svg+xml', payload: '<svg/>');

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(
            makeMediaToolNonAdminAuth(),
            mediaToolScopeConfig('agent'),
            null,
            null,
            inspectImageDriverFactory(),
        );
        try {
            $result = $tool->execute(
                ['action' => 'inspect_image', 'asset_id' => $asset->id],
                agentId: $agentId,
            );

            expect($result->success)->toBeFalse();
            expect($result->content)->toContain('does not read SVG')
                ->and($result->content)->toContain('script-capable')
                // The remedy has to be one the platform can perform: no
                // registered derivative producer rasterises an SVG, so a
                // message that names `create_derivative` sends the model
                // into a call that cannot succeed.
                ->and($result->content)->not->toContain('create_derivative');
        } finally {
            $restore();
        }
    });

    it('refuses an asset stored externally, which has no bytes on hand', function (): void {
        $agentId = inspectImageAgent('gpt-4o', true);
        $asset   = seedMediaAsset(agentId: $agentId, userId: 99, payload: 'bytes');
        Illuminate\Database\Capsule\Manager::table('media_assets')
            ->where('id', $asset->id)
            ->update(['storage_mode' => 'external']);

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(
            makeMediaToolNonAdminAuth(),
            mediaToolScopeConfig('agent'),
            null,
            null,
            inspectImageDriverFactory(),
        );
        try {
            $result = $tool->execute(
                ['action' => 'inspect_image', 'asset_id' => $asset->id],
                agentId: $agentId,
            );

            expect($result->success)->toBeFalse();
            expect($result->content)->toContain('stored externally')
                ->and($result->content)->toContain('external');
        } finally {
            $restore();
        }
    });

    it('reads a local asset stored under the suffix its own filename produced', function (): void {
        // The archive stores a local asset under the suffix its `filename`
        // produced, and the filename wins over the MIME map: an upload named
        // `photo.jpeg` lands as `<token>.jpeg`, not `<token>.jpg`. A reader
        // that derives the suffix from the MIME alone finds no file and
        // reports a missing blob for an asset that is sitting on disk.
        $agentId = inspectImageAgent('gpt-4o', true);

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(
            makeMediaToolNonAdminAuth(),
            mediaToolScopeConfig('agent'),
            null,
            null,
            inspectImageDriverFactory(),
        );
        try {
            $storage = (string) getenv('SPORA_STORAGE_DIR');
            $dir     = $storage . '/assets';
            mkdir($dir, 0755, true);
            $token = bin2hex(random_bytes(16));
            $jpeg  = "\xFF\xD8\xFF\xE0fake-jpeg";
            file_put_contents($dir . '/' . $token . '.jpeg', $jpeg);

            $asset = seedMediaAsset(agentId: $agentId, userId: 99, mime: 'image/jpeg', payload: null);
            Illuminate\Database\Capsule\Manager::table('media_assets')
                ->where('id', $asset->id)
                ->update([
                    'storage_mode' => 'local',
                    'asset_token'  => $token,
                    'filename'     => 'Gemini_Generated_Image_lt65axlt65axlt65.jpeg',
                    'byte_size'    => strlen($jpeg),
                    'payload'      => null,
                ]);

            $result = $tool->execute(
                ['action' => 'inspect_image', 'asset_id' => $asset->id],
                agentId: $agentId,
            );

            expect($result->success)->toBeTrue();
            expect($result->attachments)->toBe([['media_id' => $asset->id, 'kind' => 'image']]);
            expect($result->content)->not->toContain('could not be read');
        } finally {
            $restore();
        }
    });

    it('refuses an asset whose stored size is over the ceiling', function (): void {
        $agentId = inspectImageAgent('gpt-4o', true);
        $asset   = seedMediaAsset(agentId: $agentId, userId: 99, payload: 'bytes');
        Illuminate\Database\Capsule\Manager::table('media_assets')
            ->where('id', $asset->id)
            ->update(['byte_size' => MediaImageBlocks::MAX_IMAGE_BYTES + 1]);

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(
            makeMediaToolNonAdminAuth(),
            mediaToolScopeConfig('agent'),
            null,
            null,
            inspectImageDriverFactory(),
        );
        try {
            $result = $tool->execute(
                ['action' => 'inspect_image', 'asset_id' => $asset->id],
                agentId: $agentId,
            );

            expect($result->success)->toBeFalse();
            expect($result->content)->toContain('over the')
                ->and($result->content)->toContain((string) MediaImageBlocks::MAX_IMAGE_BYTES);
        } finally {
            $restore();
        }
    });

    it('re-measures the bytes, so a stale byte_size cannot smuggle an oversized body through', function (): void {
        // The stored size is a hint. The body is measured after the load —
        // the order the attachment path uses — so a row that claims 1 KB
        // and carries 20 MB is refused on the measurement.
        $agentId = inspectImageAgent('gpt-4o', true);

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(
            makeMediaToolNonAdminAuth(),
            mediaToolScopeConfig('agent'),
            null,
            null,
            inspectImageDriverFactory(),
        );
        try {
            $storage = (string) getenv('SPORA_STORAGE_DIR');
            $dir     = $storage . '/assets';
            mkdir($dir, 0755, true);
            $token = bin2hex(random_bytes(16));
            file_put_contents(
                $dir . '/' . $token . '.png',
                str_repeat('A', MediaImageBlocks::MAX_IMAGE_BYTES + 1),
            );

            $asset = seedMediaAsset(agentId: $agentId, userId: 99, idOverride: 'abcdabcd-abcd-abcd-abcd-abcdabcdabcd');
            Illuminate\Database\Capsule\Manager::table('media_assets')
                ->where('id', $asset->id)
                ->update([
                    'storage_mode' => 'local',
                    'asset_token'  => $token,
                    'byte_size'    => 1024,
                    'payload'      => null,
                ]);

            $result = $tool->execute(
                ['action' => 'inspect_image', 'asset_id' => $asset->id],
                agentId: $agentId,
            );

            expect($result->success)->toBeFalse();
            expect($result->content)->toContain('over the')
                ->and($result->content)->toContain((string) MediaImageBlocks::MAX_IMAGE_BYTES);
        } finally {
            $restore();
        }
    });

    it('names the active scope and the remedy when the asset is not visible', function (): void {
        $agentId = inspectImageAgent('gpt-4o', true);
        $other   = seedMediaToolAgent('Another Agent');
        $asset   = seedMediaAsset(agentId: $other, userId: 99, payload: 'bytes');

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(
            makeMediaToolNonAdminAuth(),
            mediaToolScopeConfig('agent'),
            null,
            null,
            inspectImageDriverFactory(),
        );
        try {
            $result = $tool->execute(
                ['action' => 'inspect_image', 'asset_id' => $asset->id],
                agentId: $agentId,
            );

            expect($result->success)->toBeFalse();
            expect($result->content)->toContain('No media asset ' . $asset->id . ' is visible to this agent')
                ->and($result->content)->toContain('Library scope is set to "agent"')
                ->and($result->content)->toContain('Library scope to "principal"');
            // The existence-hiding posture: the answer never says the asset
            // exists, so the message is not an oracle to probe with.
            expect($result->content)->not->toContain('forbidden')
                ->and($result->content)->not->toContain('exists');
        } finally {
            $restore();
        }
    });

    it('names the principal scope when that is the active setting', function (): void {
        $agentId = inspectImageAgent('gpt-4o', true);

        ['tool' => $tool, 'restore' => $restore] = makeMediaToolWithRealArchive(
            makeMediaToolNonAdminAuth(),
            mediaToolScopeConfig('principal'),
            null,
            null,
            inspectImageDriverFactory(),
        );
        try {
            $result = $tool->execute(
                ['action' => 'inspect_image', 'asset_id' => '99999999-9999-9999-9999-999999999999'],
                agentId: $agentId,
            );

            expect($result->success)->toBeFalse();
            expect($result->content)->toContain('Library scope is set to "principal"');
        } finally {
            $restore();
        }
    });

    it('renders the action as Media inspect_image(<asset_id>)', function (): void {
        $tool = buildMediaToolForSchema();

        expect($tool->describeAction(['action' => 'inspect_image', 'asset_id' => 'abc-123']))
            ->toBe('Media inspect_image(abc-123)');
    });
});

describe('the inspect_image opt-in gate', function (): void {
    it('keeps the operation out of the model schema while it is disabled', function (): void {
        $agentId = inspectImageAgent('gpt-4o', true);

        $builder = new ToolDefinitionBuilder([buildMediaToolForSchema()], null, null);
        $defs    = $builder->buildToolDefinitions([MediaTool::class], agentId: $agentId, context: null);

        expect($defs)->toHaveCount(1);
        $enum = $defs[0]['function']['parameters']['properties']['action']['enum'];
        expect($enum)->not->toContain('inspect_image');
        expect($enum)->toContain('get_media');
    });

    it('advertises the operation once the operator enables it for the agent', function (): void {
        $agentId = inspectImageAgent('gpt-4o', true);

        AgentToolOperationOverride::create([
            'agent_id'                  => $agentId,
            'tool_class'                => MediaTool::class,
            'operation'                 => 'inspect_image',
            'enabled'                   => 1,
            'default_requires_approval' => 0,
        ]);

        $builder = new ToolDefinitionBuilder([buildMediaToolForSchema()], null, null);
        $defs    = $builder->buildToolDefinitions([MediaTool::class], agentId: $agentId, context: null);

        expect($defs)->toHaveCount(1);
        $enum = $defs[0]['function']['parameters']['properties']['action']['enum'];
        expect($enum)->toContain('inspect_image');
    });
});
