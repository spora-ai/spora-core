<?php

declare(strict_types=1);

use Spora\Models\TaskHistory;
use Spora\Services\TaskHistorySerializer;

/**
 * Pin the wire projection of `task_history.attachments` — the JSON
 * refs the chat list consumes from `GET /api/v1/tasks/{id}` and that
 * `MediaResolveController` resolves into `MediaAsset` payloads.
 */
it('returns null for empty attachments', function (): void {
    expect(TaskHistorySerializer::sanitizeAttachmentsForApi(null))->toBeNull();
    expect(TaskHistorySerializer::sanitizeAttachmentsForApi([]))->toBeNull();
    expect(TaskHistorySerializer::sanitizeAttachmentsForApi('not-an-array'))->toBeNull();
});

it('passes through valid image and text refs', function (): void {
    $raw = [
        ['media_id' => 'aabbccdd-0000-4000-8000-000000000001', 'kind' => 'image'],
        ['media_id' => 'aabbccdd-0000-4000-8000-000000000002', 'kind' => 'text'],
    ];

    expect(TaskHistorySerializer::sanitizeAttachmentsForApi($raw))->toBe($raw);
});

it('drops entries without a media_id', function (): void {
    $raw = [
        ['media_id' => 'aabbccdd-0000-4000-8000-000000000001', 'kind' => 'image'],
        ['kind' => 'image'], // missing media_id
        ['media_id' => '', 'kind' => 'image'], // empty media_id
    ];

    expect(TaskHistorySerializer::sanitizeAttachmentsForApi($raw))->toBe([
        ['media_id' => 'aabbccdd-0000-4000-8000-000000000001', 'kind' => 'image'],
    ]);
});

it('folds unknown kind values into text', function (): void {
    $raw = [
        ['media_id' => 'aabbccdd-0000-4000-8000-000000000001', 'kind' => 'mystery'],
        ['media_id' => 'aabbccdd-0000-4000-8000-000000000002', 'kind' => null],
        ['media_id' => 'aabbccdd-0000-4000-8000-000000000003'], // missing kind
    ];

    expect(TaskHistorySerializer::sanitizeAttachmentsForApi($raw))->toBe([
        ['media_id' => 'aabbccdd-0000-4000-8000-000000000001', 'kind' => 'text'],
        ['media_id' => 'aabbccdd-0000-4000-8000-000000000002', 'kind' => 'text'],
        ['media_id' => 'aabbccdd-0000-4000-8000-000000000003', 'kind' => 'text'],
    ]);
});

it('strips unknown keys from each entry', function (): void {
    $raw = [
        ['media_id' => 'aabbccdd-0000-4000-8000-000000000001', 'kind' => 'image', 'rogue' => 'data'],
    ];

    expect(TaskHistorySerializer::sanitizeAttachmentsForApi($raw))->toBe([
        ['media_id' => 'aabbccdd-0000-4000-8000-000000000001', 'kind' => 'image'],
    ]);
});

it('returns null when every entry is rejected', function (): void {
    $raw = [
        ['kind' => 'image'],
        'not-an-object',
    ];

    expect(TaskHistorySerializer::sanitizeAttachmentsForApi($raw))->toBeNull();
});

it('emits the attachments field on the wire message', function (): void {
    $history = new TaskHistory([
        'task_id'  => 1,
        'sequence' => 1,
        'role'     => 'user',
        'content'  => 'see attached',
        'attachments' => [
            ['media_id' => 'aabbccdd-0000-4000-8000-000000000001', 'kind' => 'image'],
        ],
    ]);

    $message = TaskHistorySerializer::buildHistoryMessage($history);

    expect($message)->toHaveKey('attachments')
        ->and($message['attachments'])->toBe([
            ['media_id' => 'aabbccdd-0000-4000-8000-000000000001', 'kind' => 'image'],
        ]);
});

it('omits attachments on the wire message when the column is empty', function (): void {
    $history = new TaskHistory([
        'task_id'  => 1,
        'sequence' => 1,
        'role'     => 'user',
        'content'  => 'no attachments',
    ]);

    $message = TaskHistorySerializer::buildHistoryMessage($history);

    expect($message)->toHaveKey('attachments')
        ->and($message['attachments'])->toBeNull();
});
