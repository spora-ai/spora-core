<?php

declare(strict_types=1);

namespace Tests\Feature\Agents;

use Spora\Agents\MessageHistoryBuilder;
use Spora\Core\Paths;
use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Models\MediaAsset;
use Spora\Models\TaskHistory;
use Symfony\Component\HttpClient\MockHttpClient;

defined('TOOL_IMAGE_PASSWORD') || define('TOOL_IMAGE_PASSWORD', 'Password1!');

/**
 * The tool-result image channel: a tool row that points at an archive
 * image becomes the tool result plus a user turn that carries the image.
 *
 * The bytes are read when the request is built, not when the tool ran —
 * the row persists a reference, and the reference is resolved per request.
 */
function seedToolImageAgent(): int
{
    $authService = bootAuthLayer();
    $userId      = $authService->register('tool-image@example.com', TOOL_IMAGE_PASSWORD, 'Tool Image');

    $config = \Spora\Models\LLMDriverConfiguration::create([
        'principal_id'       => null,
        'name'               => 'Tool Image Config',
        'driver_class'       => AnthropicCompatibleDriver::class,
        'settings'           => json_encode(['api_key' => 'test']),
        'is_global'          => true,
        'is_default'         => true,
        'context_window'     => 200000,
        'max_tokens_output'  => 4096,
    ]);

    return \Spora\Models\Agent::create([
        'principal_id'       => createUserPrincipalPublic($userId),
        'name'               => 'Tool Image Agent',
        'llm_driver_config_id' => $config->id,
        'max_steps'          => 10,
        'is_active'          => true,
    ])->id;
}

function makeToolImageTask(int $agentId): \Spora\Models\Task
{
    $agent = \Spora\Models\Agent::find($agentId);

    return \Spora\Models\Task::create([
        'agent_id'        => $agentId,
        'principal_id'    => (int) $agent->principal_id,
        'trigger_user_id' => $agent->user_id,
        'status'          => 'RUNNING',
        'user_prompt'     => 'tool image test',
        'step_count'      => 0,
        'max_steps'       => 10,
    ]);
}

function toolImagePng(): string
{
    return base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=',
        strict: true,
    );
}

/**
 * The transcript a tool call leaves behind: the assistant turn that asked
 * for it, and the tool result.
 *
 * @param list<array{media_id: string, kind: string}>|null $attachments
 */
function writeToolImageTranscript(
    \Spora\Models\Task $task,
    ?array $attachments,
    string $toolContent = 'The image is attached.',
): void {
    TaskHistory::create([
        'task_id'           => $task->id,
        'sequence'          => 0,
        'role'              => 'assistant',
        'content'           => null,
        'tool_call_payload' => json_encode([
            [
                'id'       => 'call_1',
                'type'     => 'function',
                'function' => ['name' => 'media', 'arguments' => '{}'],
            ],
        ]),
    ]);

    $row = [
        'task_id'      => $task->id,
        'sequence'     => 1,
        'role'         => 'tool',
        'tool_call_id' => 'call_1',
        'tool_name'    => 'media',
        'content'      => $toolContent,
    ];

    if ($attachments !== null) {
        $row['attachments'] = $attachments;
    }

    TaskHistory::create($row);
}

describe('the tool-result image channel', function (): void {
    it('turns a tool row with an image attachment into the tool message plus a user message carrying the image', function (): void {
        $agentId = seedToolImageAgent();
        $task = makeToolImageTask($agentId);
        $png = toolImagePng();

        $asset = MediaAsset::create([
            'id'           => '12345678-1234-4234-8234-123456789012',
            'asset_url'    => '/api/v1/assets/12345678-1234-4234-8234-123456789012.png',
            'storage_mode' => 'data_url',
            'media_type'   => 'image',
            'mime_type'    => 'image/png',
            'byte_size'    => strlen($png),
            'user_id'      => 1,
            'payload'      => $png,
            'filename'     => 'pixel.png',
        ]);

        writeToolImageTranscript($task, [['media_id' => $asset->id, 'kind' => 'image']]);

        $messages = (new MessageHistoryBuilder())->build($task->id);

        expect($messages)->toHaveCount(3);
        expect($messages[0]['role'])->toBe('assistant');
        expect($messages[1]['role'])->toBe('tool');
        expect($messages[1]['content'])->toBe('The image is attached.');
        expect($messages[2]['role'])->toBe('user');

        $blocks = $messages[2]['content'];
        expect($blocks)->toBeArray();
        expect($blocks)->toHaveCount(2);
        // The identity block hands the asset_id back, so the turn is never
        // image-only and the asset can be chained onward.
        expect($blocks[0]['type'])->toBe('text');
        expect($blocks[0]['text'])->toContain('[Attached asset_id=');
        expect($blocks[0]['text'])->toContain($asset->id);
        expect($blocks[1]['type'])->toBe('image');
        expect($blocks[1]['mediaType'])->toBe('image/png');
        expect(base64_decode((string) $blocks[1]['base64'], strict: true))->toBe($png);

        // The image rides on a sibling turn, so the tool results of the
        // batch stay contiguous.
        expect(toolCallPairingFaults($messages))->toBe([]);
    });

    it('reads the bytes when the request is built, not when the tool ran', function (): void {
        $agentId = seedToolImageAgent();
        $task = makeToolImageTask($agentId);

        $dir = sys_get_temp_dir() . '/spora-tool-image-' . bin2hex(random_bytes(4));
        mkdir($dir . '/assets', 0755, recursive: true);
        putenv("SPORA_STORAGE_DIR={$dir}");
        $_ENV['SPORA_STORAGE_DIR']    = $dir;
        $_SERVER['SPORA_STORAGE_DIR'] = $dir;

        $paths = new Paths(BASE_PATH);
        $token = 'dddddddddddddddddddddddddddddddd';
        $atToolTime = toolImagePng();
        file_put_contents($paths->storage('assets') . '/' . $token . '.png', $atToolTime);

        $asset = MediaAsset::create([
            'id'           => '23456789-2345-4345-8345-234567890123',
            'asset_url'    => '/api/v1/assets/23456789-2345-4345-8345-234567890123.png',
            'storage_mode' => 'local',
            'asset_token'  => $token,
            'media_type'   => 'image',
            'mime_type'    => 'image/png',
            'byte_size'    => strlen($atToolTime),
            'user_id'      => 1,
            'filename'     => 'pixel.png',
        ]);

        writeToolImageTranscript($task, [['media_id' => $asset->id, 'kind' => 'image']]);

        // The asset changes on disk between the tool call and the build.
        $atBuildTime = "\x89PNG-replaced-after-the-tool-ran";
        file_put_contents($paths->storage('assets') . '/' . $token . '.png', $atBuildTime);

        $messages = (new MessageHistoryBuilder())->build($task->id);

        expect($messages)->toHaveCount(3);
        $blocks = $messages[2]['content'];
        expect(base64_decode((string) $blocks[1]['base64'], strict: true))->toBe($atBuildTime);

        // The row itself carries a reference, never the bytes.
        $row = TaskHistory::where('task_id', $task->id)->where('role', 'tool')->first();
        expect((string) $row->content)->not->toContain(base64_encode($atBuildTime));

        foreach (glob($dir . '/assets/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($dir . '/assets');
        @rmdir($dir);
    });

    it('leaves a tool row without attachments byte-identical on the wire', function (): void {
        $agentId = seedToolImageAgent();
        $task = makeToolImageTask($agentId);

        writeToolImageTranscript($task, null);

        $messages = (new MessageHistoryBuilder())->build($task->id);

        expect($messages)->toHaveCount(2);
        expect($messages[1])->toBe([
            'role' => 'tool',
            'tool_call_id' => 'call_1',
            'name' => 'media',
            'content' => 'The image is attached.',
        ]);
    });

    it('still delivers the tool text when the asset cannot be rendered', function (): void {
        $agentId = seedToolImageAgent();
        $task = makeToolImageTask($agentId);

        // An `external` asset has no bytes on hand: the archive holds a
        // pointer to someone else's server.
        $asset = MediaAsset::create([
            'id'           => '34567890-3456-4456-8456-345678901234',
            'asset_url'    => 'https://cdn.example.com/34567890-3456-4456-8456-345678901234.png',
            'storage_mode' => 'external',
            'media_type'   => 'image',
            'mime_type'    => 'image/png',
            'byte_size'    => 1024,
            'user_id'      => 1,
            'filename'     => 'pixel.png',
        ]);

        writeToolImageTranscript($task, [['media_id' => $asset->id, 'kind' => 'image']]);

        $messages = (new MessageHistoryBuilder())->build($task->id);

        expect($messages)->toHaveCount(2);
        expect($messages[1]['role'])->toBe('tool');
        expect($messages[1]['content'])->toBe('The image is attached.');
    });

    it('drops the image for a driver that cannot take one', function (): void {
        $agentId = seedToolImageAgent();
        $task = makeToolImageTask($agentId);
        $png = toolImagePng();

        $asset = MediaAsset::create([
            'id'           => '45678901-2345-4678-9123-456789012345',
            'asset_url'    => '/api/v1/assets/45678901-2345-4678-9123-456789012345.png',
            'storage_mode' => 'data_url',
            'media_type'   => 'image',
            'mime_type'    => 'image/png',
            'byte_size'    => strlen($png),
            'user_id'      => 1,
            'payload'      => $png,
            'filename'     => 'pixel.png',
        ]);

        writeToolImageTranscript($task, [['media_id' => $asset->id, 'kind' => 'image']]);

        $driver = new \Spora\Drivers\OpenAICompatibleDriver(
            apiKey: 'test',
            model: 'gpt-3.5-turbo',
            baseUrl: 'https://api.openai.com/v1',
            httpClient: new MockHttpClient(),
            logger: new \Psr\Log\NullLogger(),
            timeout: 60,
        );

        $messages = (new MessageHistoryBuilder($driver))->build($task->id);

        expect($messages)->toHaveCount(2);
        expect($messages[1]['role'])->toBe('tool');
        expect($messages[1]['content'])->toBe('The image is attached.');
    });
});
