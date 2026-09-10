<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Add `transcript` and `transcript_language` to `media_assets`.
 *
 * The transcribe controller ({@see \Spora\Http\SpeechTranscribeController})
 * writes both columns on every successful STT provider call so chat
 * re-renders re-use the cached transcript without re-billing the
 * upstream STT API.
 *
 * Why nullable:
 *
 *   Pre-existing audio assets have no transcript. Promoting the columns
 *   to NOT NULL would force a backfill — and we have no way to backfill
 *   STT for historical rows without operator consent (it would cost
 *   money). The MediaAssetSerializer omits null fields so legacy audio
 *   rows render as "no transcript yet" until the user re-transcribes.
 *
 * Why text columns (not varchar):
 *
 *   Transcripts run 1–4 KB per minute of audio; a 30-minute recording
 *   tops out around 12 KB. Text columns cap at the SQLite/MySQL TEXT
 *   ceiling (64 KB on SQLite, ~65 KB on MySQL/MariaDB), which is well
 *   above any realistic speech clip. `transcript_language` is a
 *   16-char BCP-47 short tag (e.g. `en-US`); `string` is the right fit.
 *
 * Idempotency:
 *
 *   Both columns are gated on `hasColumn` so re-running on a partially
 *   migrated DB is safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Capsule::schema();

        if (!$schema->hasColumn('media_assets', 'transcript')) {
            $schema->table('media_assets', static function (Blueprint $t): void {
                $t->text('transcript')->nullable()->after('markdown_content');
            });
        }

        if (!$schema->hasColumn('media_assets', 'transcript_language')) {
            $schema->table('media_assets', static function (Blueprint $t): void {
                $t->string('transcript_language', 16)->nullable()->after('transcript');
            });
        }
    }

    public function down(): void
    {
        $schema = Capsule::schema();

        if ($schema->hasColumn('media_assets', 'transcript_language')) {
            $schema->table('media_assets', static function (Blueprint $t): void {
                $t->dropColumn('transcript_language');
            });
        }

        if ($schema->hasColumn('media_assets', 'transcript')) {
            $schema->table('media_assets', static function (Blueprint $t): void {
                $t->dropColumn('transcript');
            });
        }
    }
};
