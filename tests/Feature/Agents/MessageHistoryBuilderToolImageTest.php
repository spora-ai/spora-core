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
 * An archive asset whose bytes are on hand inline.
 */
function toolImageAsset(string $uuid, string $payload): MediaAsset
{
    return MediaAsset::create([
        'id'           => $uuid,
        'asset_url'    => "/api/v1/assets/{$uuid}.png",
        'storage_mode' => 'data_url',
        'media_type'   => 'image',
        'mime_type'    => 'image/png',
        'byte_size'    => strlen($payload),
        'user_id'      => 1,
        'payload'      => $payload,
        'filename'     => 'pixel.png',
    ]);
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

/**
 * The transcript a batch of tool calls leaves behind: the assistant turn
 * that asked for all of them, and one tool result per call.
 *
 * @param  list<list<array{media_id: string, kind: string}>|null>  $attachmentsPerResult
 */
function writeToolImageBatchTranscript(
    \Spora\Models\Task $task,
    array $attachmentsPerResult,
): void {
    $calls = [];

    foreach (array_keys($attachmentsPerResult) as $index) {
        $calls[] = [
            'id'       => 'call_' . ($index + 1),
            'type'     => 'function',
            'function' => ['name' => 'media', 'arguments' => '{}'],
        ];
    }

    TaskHistory::create([
        'task_id'           => $task->id,
        'sequence'          => 0,
        'role'              => 'assistant',
        'content'           => null,
        'tool_call_payload' => json_encode($calls),
    ]);

    foreach ($attachmentsPerResult as $index => $attachments) {
        $row = [
            'task_id'      => $task->id,
            'sequence'     => $index + 1,
            'role'         => 'tool',
            'tool_call_id' => 'call_' . ($index + 1),
            'tool_name'    => 'media',
            'content'      => 'The image is attached.',
        ];

        if ($attachments !== null) {
            $row['attachments'] = $attachments;
        }

        TaskHistory::create($row);
    }
}

/**
 * An assistant turn after a run of tool results, so the image turn has a
 * non-tool row to be flushed in front of.
 */
function writeTrailingAssistantRow(\Spora\Models\Task $task, int $sequence): void
{
    TaskHistory::create([
        'task_id' => $task->id,
        'sequence' => $sequence,
        'role' => 'assistant',
        'content' => 'Looking at the image now.',
    ]);
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

    it('keeps the images that fit the row budget and drops the ones that do not', function (): void {
        // The row's image bytes are capped as a whole, the same budget the
        // attachment path spends: the per-asset cap bounds one image, not
        // the row. An image that does not fit is dropped — the tool result
        // text still reaches the model.
        $agentId = seedToolImageAgent();
        $task = makeToolImageTask($agentId);
        $png = toolImagePng();

        $first  = toolImageAsset('56789012-3456-4789-0123-456789012345', $png);
        $second = toolImageAsset('67890123-4567-4890-1234-567890123456', $png);

        writeToolImageTranscript($task, [
            ['media_id' => $first->id, 'kind' => 'image'],
            ['media_id' => $second->id, 'kind' => 'image'],
        ]);

        // One image fits, the second does not.
        $messages = (new MessageHistoryBuilder(null, maxRowImageBytes: strlen($png) + 1))->build($task->id);

        expect($messages)->toHaveCount(3);

        // The tool result survives the drop.
        expect($messages[1]['role'])->toBe('tool');
        expect($messages[1]['content'])->toBe('The image is attached.');

        $blocks = $messages[2]['content'];
        expect($blocks)->toBeArray();
        expect(array_values(array_filter($blocks, static fn(array $block): bool => $block['type'] === 'image')))
            ->toHaveCount(1);

        // The identity block belongs to the image that was kept.
        $identities = array_values(array_filter($blocks, static fn(array $block): bool => $block['type'] === 'text'));
        expect($identities)->toHaveCount(1);
        expect($identities[0]['text'])->toContain($first->id);
    });

    it('keeps every image when the row budget has room for all of them', function (): void {
        $agentId = seedToolImageAgent();
        $task = makeToolImageTask($agentId);
        $png = toolImagePng();

        $first  = toolImageAsset('78901234-5678-4901-2345-678901234567', $png);
        $second = toolImageAsset('89012345-6789-4012-3456-789012345678', $png);

        writeToolImageTranscript($task, [
            ['media_id' => $first->id, 'kind' => 'image'],
            ['media_id' => $second->id, 'kind' => 'image'],
        ]);

        $messages = (new MessageHistoryBuilder(null, maxRowImageBytes: 2 * strlen($png)))->build($task->id);

        expect($messages)->toHaveCount(3);
        $blocks = $messages[2]['content'];
        expect($blocks)->toBeArray();
        expect(array_values(array_filter($blocks, static fn(array $block): bool => $block['type'] === 'image')))
            ->toHaveCount(2);
    });

    it('holds the image turn back until the run of tool results ends', function (): void {
        // A provider accepts a tool result only directly after the
        // assistant turn that asked for it, so a user turn between two
        // results of one batch is a 400.
        $agentId = seedToolImageAgent();
        $task = makeToolImageTask($agentId);
        $png = toolImagePng();

        $asset = toolImageAsset('90123456-7890-4123-8234-567890123456', $png);

        writeToolImageBatchTranscript($task, [
            [['media_id' => $asset->id, 'kind' => 'image']],
            null,
        ]);

        $messages = (new MessageHistoryBuilder())->build($task->id);

        expect(array_column($messages, 'role'))->toBe(['assistant', 'tool', 'tool', 'user']);
        expect(toolCallPairingFaults($messages))->toBe([]);

        // The image turn is the last one, and it carries the image.
        $blocks = $messages[3]['content'];
        expect($blocks)->toBeArray();
        expect($blocks[1]['type'])->toBe('image');
        expect(base64_decode((string) $blocks[1]['base64'], strict: true))->toBe($png);
    });

    it('holds the image turn back when the second result of the batch carries it', function (): void {
        $agentId = seedToolImageAgent();
        $task = makeToolImageTask($agentId);
        $png = toolImagePng();

        $asset = toolImageAsset('01234567-8901-4234-8234-567890123456', $png);

        writeToolImageBatchTranscript($task, [
            null,
            [['media_id' => $asset->id, 'kind' => 'image']],
        ]);

        $messages = (new MessageHistoryBuilder())->build($task->id);

        expect(array_column($messages, 'role'))->toBe(['assistant', 'tool', 'tool', 'user']);
        expect(toolCallPairingFaults($messages))->toBe([]);

        $blocks = $messages[3]['content'];
        expect($blocks)->toBeArray();
        expect($blocks[1]['type'])->toBe('image');
        expect(base64_decode((string) $blocks[1]['base64'], strict: true))->toBe($png);
    });

    it('holds the image turn back when a later result of a longer run carries it', function (): void {
        // The image is on the second of three results, so the turn it rides
        // on has to wait for the third as well.
        $agentId = seedToolImageAgent();
        $task = makeToolImageTask($agentId);
        $png = toolImagePng();

        $asset = toolImageAsset('21234567-8901-4234-8234-567890123456', $png);

        writeToolImageBatchTranscript($task, [
            null,
            [['media_id' => $asset->id, 'kind' => 'image']],
            null,
        ]);

        $messages = (new MessageHistoryBuilder())->build($task->id);

        expect(array_column($messages, 'role'))->toBe(['assistant', 'tool', 'tool', 'tool', 'user']);
        expect(toolCallPairingFaults($messages))->toBe([]);

        $blocks = $messages[4]['content'];
        expect($blocks)->toBeArray();
        expect($blocks[1]['type'])->toBe('image');
        expect(base64_decode((string) $blocks[1]['base64'], strict: true))->toBe($png);
    });

    it('flushes the image turn in front of the assistant row that follows the batch', function (): void {
        $agentId = seedToolImageAgent();
        $task = makeToolImageTask($agentId);
        $png = toolImagePng();

        $asset = toolImageAsset('11234567-8901-4234-8234-567890123456', $png);

        writeToolImageBatchTranscript($task, [
            [['media_id' => $asset->id, 'kind' => 'image']],
            null,
        ]);
        writeTrailingAssistantRow($task, 3);

        $messages = (new MessageHistoryBuilder())->build($task->id);

        expect(array_column($messages, 'role'))->toBe(['assistant', 'tool', 'tool', 'user', 'assistant']);
        expect(toolCallPairingFaults($messages))->toBe([]);
    });
});

describe('an image the operator removed from the Media Archive', function (): void {
    it('tells the model the image is gone rather than leaving the claim standing', function (): void {
        // The tool result says "you can see it now". The operator deletes
        // the asset from the Media Archive, and the row — which is what the
        // provider sees on the next tick — still claims the image is there.
        // The image block is dropped silently, so without a note the model
        // reads a claim the payload no longer supports.
        $agentId = seedToolImageAgent();
        $task = makeToolImageTask($agentId);

        writeToolImageTranscript($task, [['media_id' => '99999999-9999-4999-8999-999999999999', 'kind' => 'image']]);

        $messages = (new MessageHistoryBuilder())->build($task->id);

        // The tool result and nothing else: the image turn went with the
        // image, and no message was added or reordered.
        expect($messages)->toHaveCount(2);
        expect($messages[1]['role'])->toBe('tool');
        expect($messages[1]['content'])->toStartWith('The image is attached.');
        expect($messages[1]['content'])->toContain('removed from the Media Archive');
        expect($messages[1]['content'])->toContain('no longer visible');
    });

    it('leaves the tool result byte-identical when every declared image still resolves', function (): void {
        $agentId = seedToolImageAgent();
        $task = makeToolImageTask($agentId);
        $asset = toolImageAsset('11111111-1111-4111-8111-111111111111', toolImagePng());

        writeToolImageTranscript($task, [['media_id' => $asset->id, 'kind' => 'image']]);

        $messages = (new MessageHistoryBuilder())->build($task->id);

        expect($messages[1])->toBe([
            'role' => 'tool',
            'tool_call_id' => 'call_1',
            'name' => 'media',
            'content' => 'The image is attached.',
        ]);
    });

    it('names the count when several images are gone', function (): void {
        // One sentence per row: several missing assets are one fact.
        $agentId = seedToolImageAgent();
        $task = makeToolImageTask($agentId);

        writeToolImageTranscript($task, [
            ['media_id' => '22222222-2222-4222-8222-222222222222', 'kind' => 'image'],
            ['media_id' => '33333333-3333-4333-8333-333333333333', 'kind' => 'image'],
        ]);

        $messages = (new MessageHistoryBuilder())->build($task->id);

        expect($messages)->toHaveCount(2);
        expect($messages[1]['content'])->toContain(
            '2 images referenced by this message were removed from the Media Archive',
        );
        expect(substr_count((string) $messages[1]['content'], 'removed from the Media Archive'))->toBe(1);
    });
});
