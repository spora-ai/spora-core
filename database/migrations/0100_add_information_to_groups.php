<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * The rich "about the organisation" block a group carries.
 *
 * `groups.description` is the short blurb that shows up in the group
 * list and is set at creation; `information` is the long-form markdown
 * an operator edits on the group settings page and the agent reads.
 *
 * MEDIUMTEXT, because the repo already uses it for long operator content
 * (migration 0066 widened the text columns to it) and a group's
 * information block is exactly that shape of content. SQLite has no
 * intrinsic TEXT cap, so Laravel maps it to TEXT there.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable('groups') || $schema->hasColumn('groups', 'information')) {
            return;
        }

        $schema->table('groups', static function (Blueprint $table): void {
            $table->mediumText('information')->nullable();
        });
    }

    public function down(): void
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable('groups') || !$schema->hasColumn('groups', 'information')) {
            return;
        }

        $schema->table('groups', static function (Blueprint $table): void {
            $table->dropColumn('information');
        });
    }
};
