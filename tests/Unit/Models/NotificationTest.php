<?php

declare(strict_types=1);

use Spora\Models\Notification;
use Spora\Models\User;

const NOTIFICATION_TEST_PASSWORD = 'Password1!';

it('uses the notifications table', function (): void {
    $notification = new Notification();

    expect($notification->getTable())->toBe('notifications');
});

it('disables Eloquent timestamps (created_at managed by hook)', function (): void {
    expect((new Notification())->timestamps)->toBeFalse();
});

it('allows mass assignment and creation without created_at', function (): void {
    $userId = bootAuthLayer()->register('notif@example.com', NOTIFICATION_TEST_PASSWORD, 'Notif');

    $notification = Notification::create([
        'user_id' => $userId,
        'type'    => 'TASK_COMPLETED',
        'title'   => 'Task done',
        'body'    => 'Your task is done',
    ]);

    expect($notification->user_id)->toBe($userId)
        ->and($notification->type)->toBe('TASK_COMPLETED')
        ->and($notification->title)->toBe('Task done')
        ->and($notification->body)->toBe('Your task is done');
});

it('casts data to array and read_at to Carbon', function (): void {
    $userId = bootAuthLayer()->register('notif-cast@example.com', NOTIFICATION_TEST_PASSWORD, 'NotifCast');

    $notification = Notification::create([
        'user_id' => $userId,
        'type'    => 'TASK_FAILED',
        'title'   => 'Oops',
        'data'    => ['code' => 500],
        'read_at' => '2099-01-01 12:00:00',
    ]);

    $reloaded = Notification::find($notification->id);
    expect($reloaded->data)->toBe(['code' => 500])
        ->and($reloaded->read_at)->toBeInstanceOf(Carbon\Carbon::class);
});

it('belongs to a user', function (): void {
    $userId = bootAuthLayer()->register('notif-rel@example.com', NOTIFICATION_TEST_PASSWORD, 'NotifRel');
    $notification = Notification::create([
        'user_id' => $userId,
        'type'    => 'INFO',
        'title'   => 'Hi',
    ]);

    expect($notification->user)->toBeInstanceOf(User::class)
        ->and((int) $notification->user->getKey())->toBe($userId);
});
