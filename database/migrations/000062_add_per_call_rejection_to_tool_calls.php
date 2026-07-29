<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Capsule::schema()->table('tool_calls', static function (Blueprint $table): void {
            $table->timestamp('rejected_at')->nullable();
            $table->unsignedBigInteger('rejected_by')->nullable();
            $table->text('reject_reason')->nullable();
            $table->foreign('rejected_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Capsule::schema()->table('tool_calls', static function (Blueprint $table): void {
            $table->dropForeign(['rejected_by']);
            $table->dropColumn(['rejected_at', 'rejected_by', 'reject_reason']);
        });
    }
};
