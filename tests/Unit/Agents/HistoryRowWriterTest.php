<?php

declare(strict_types=1);

use Spora\Agents\HistoryRowWriter;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\Task;
use Spora\Models\TaskHistory;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * The tool row is the one the model reads back: the content is scrubbed of
 * data URLs, and the result's attachments travel with the row so the
 * builder can put the assets in front of the model.
 */
describe('HistoryRowWriter::appendToolResult', function (): void {
    /**
     * A task row to write history against.
     */
    function toolResultTask(): Task
    {
        $authService = bootAuthLayer();
        $userId      = $authService->register('toolrow@example.com', 'Password1!', 'ToolRow');

        $config = LLMDriverConfiguration::create([
            'principal_id'    => null,
            'name'            => 'Test Global Config',
            'driver_class'    => Spora\Drivers\OpenAICompatibleDriver::class,
            'settings'        => json_encode(['api_key' => 'test']),
            'is_global'       => true,
            'is_default'      => true,
            'context_window'  => 128000,
            'max_tokens_output' => 4096,
        ]);

        $agent = Agent::create([
            'principal_id'          => createUserPrincipalPublic($userId),
            'name'                  => 'Test Agent',
            'llm_driver_config_id'  => $config->id,
            'max_steps'             => 10,
            'is_active'             => true,
        ]);

        return Task::create([
            'agent_id'        => $agent->id,
            'principal_id'    => (int) $agent->principal_id,
            'trigger_user_id' => $agent->user_id,
            'status'          => 'RUNNING',
            'user_prompt'     => 'tool result test',
            'step_count'      => 0,
            'max_steps'       => 10,
        ]);
    }

    it('writes the result\'s attachments onto the tool row', function (): void {
        $task = toolResultTask();

        HistoryRowWriter::appendToolResult(
            $task->id,
            ToolResult::ok('The image is attached.', null, [['media_id' => 'asset-1', 'kind' => 'image']]),
            'call_1',
            'media',
        );

        $row = TaskHistory::where('task_id', $task->id)->where('role', 'tool')->first();
        expect($row)->not->toBeNull();
        expect($row->content)->toBe('The image is attached.');
        expect($row->tool_call_id)->toBe('call_1');
        expect($row->tool_name)->toBe('media');

        // A fresh query, so Eloquent applies the `array` cast on raw read.
        $refs = array_values((array) $row->attachments);
        expect($refs)->toHaveCount(1)
            ->and($refs[0])->toMatchArray([
                'media_id' => 'asset-1',
                'kind'     => 'image',
            ]);
    })->afterEach(fn() => Spora\Core\Database::resetBootState());

    it('leaves the attachments column null for a result that carries none', function (): void {
        $task = toolResultTask();

        HistoryRowWriter::appendToolResult($task->id, ToolResult::ok('All done.'), 'call_1', 'media');

        $row = TaskHistory::where('task_id', $task->id)->where('role', 'tool')->first();
        expect($row)->not->toBeNull();
        expect($row->attachments)->toBeNull();
    })->afterEach(fn() => Spora\Core\Database::resetBootState());

    it('scrubs the data URLs out of the content', function (): void {
        $task = toolResultTask();

        HistoryRowWriter::appendToolResult(
            $task->id,
            ToolResult::ok('Here it is: data:image/png;base64,QUJD'),
            'call_1',
            'media',
        );

        $row = TaskHistory::where('task_id', $task->id)->where('role', 'tool')->first();
        expect($row)->not->toBeNull();
        expect((string) $row->content)->toBe('Here it is: [data-omitted]');
    })->afterEach(fn() => Spora\Core\Database::resetBootState());
});
