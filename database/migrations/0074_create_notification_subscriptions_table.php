<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Per-user notification subscriptions.
 *
 * A row says "user X wants email notifications for tasks on target Y".
 * Two target shapes:
 *
 *   - `target_type = 'agent'`,     `target_id = agents.id`     — that one agent
 *   - `target_type = 'principal'`, `target_id = principals.id` — every agent
 *     that principal owns (covers both user-owned and group-owned agents)
 *
 * Replaces the previous `tasks.principal->user` routing, which silently
 * dropped emails for group-owned runs (a `type=group` principal has
 * `user_id IS NULL` per the XOR invariant in `Principal::validateXor()`).
 * The new model also makes unsubscribe a first-class state instead of
 * something the immutable `trigger_user_id` column couldn't express.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Capsule::schema();

        if ($schema->hasTable('notification_subscriptions')) {
            return;
        }

        $schema->create('notification_subscriptions', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->string('target_type', 20);
            $table->unsignedBigInteger('target_id');
            $table->timestamps();

            $table->foreign('user_id', 'fk_notification_subscriptions_user_id')
                ->references('id')->on('users')
                ->onDelete('cascade');

            // One row per (user, target). Re-subscribing is a no-op; a real
            // delete is the only way to clear it.
            $table->unique(
                ['user_id', 'target_type', 'target_id'],
                'uq_notification_subscriptions_user_target',
            );

            // Fan-out index: "every subscriber to this target" is the
            // hot path on every scheduled-run dispatch.
            $table->index(
                ['target_type', 'target_id'],
                'idx_notification_subscriptions_target',
            );
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('notification_subscriptions');
    }
};
