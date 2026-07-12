<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        // Marker column: `1` for rows that originally carried an inline
        // `data:` URL before this migration. The `down()` reverts only
        // these rows back to a data: URL — fresh rows created post-refactor
        // are left alone because their `payload` is the source of truth.
        Capsule::schema()->table('media_assets', function (Blueprint $table) {
            $table->boolean('migrated_from_inline_data_url')->default(false);
        });

        // SQLite (no FOR UPDATE) and MySQL both honour cursor-based chunking
        // via Eloquent's `orderBy('id')->cursor()`, so each row's base64
        // decode + write runs in its own short-lived transaction.
        $rows = Capsule::table('media_assets')
            ->select(['id', 'asset_url', 'payload'])
            ->orderBy('id')
            ->cursor();

        foreach ($rows as $row) {
            $url = $row->asset_url ?? '';
            if (!is_string($url) || !str_starts_with($url, 'data:')) {
                continue;
            }

            // Decode `data:<mime>;base64,<payload>` to raw bytes. Failures
            // (corrupt rows, oversized payloads above the 64 KiB BLOB cap)
            // are skipped — better to keep the original data: URL intact
            // than to lose the row. Each row's update is its own
            // transaction so a bad row doesn't block the rest.
            $sep = strpos($url, ',', 5);
            if ($sep === false) {
                continue;
            }
            $b64 = substr($url, $sep + 1);
            $bytes = base64_decode($b64, strict: true);
            if ($bytes === false) {
                continue;
            }

            $token = bin2hex(random_bytes(16));
            $newUrl = '/api/v1/assets/' . $row->id;

            try {
                Capsule::connection()->transaction(function () use ($row, $bytes, $token, $newUrl): void {
                    Capsule::table('media_assets')
                        ->where('id', $row->id)
                        ->update([
                            'payload' => $bytes,
                            'asset_token' => $token,
                            'asset_url' => $newUrl,
                            'migrated_from_inline_data_url' => true,
                        ]);
                });
            } catch (\Throwable) {
                // 64 KiB BLOB cap (MySQL/MariaDB) or write failure: leave
                // the row untouched. Operator can manually re-ingest via
                // the LocalAssetStore path or apply `MEDIUMBLOB`.
            }
        }
    }

    public function down(): void
    {
        // Re-encode rows that originated from an inline data: URL back to
        // that shape. Fresh rows (created post-refactor) have the marker
        // `false` (or NULL) and are left alone.
        $rows = Capsule::table('media_assets')
            ->select(['id', 'asset_url', 'payload', 'mime_type'])
            ->where('migrated_from_inline_data_url', true)
            ->orderBy('id')
            ->cursor();

        foreach ($rows as $row) {
            if ($row->payload === null) {
                continue;
            }
            $mime = $row->mime_type ?? 'application/octet-stream';
            $url = 'data:' . $mime . ';base64,' . base64_encode($row->payload);
            try {
                Capsule::connection()->transaction(function () use ($row, $url): void {
                    Capsule::table('media_assets')
                        ->where('id', $row->id)
                        ->update([
                            'asset_url' => $url,
                            'payload' => null,
                            'asset_token' => null,
                        ]);
                });
            } catch (\Throwable) {
                // Ignore — payload re-encoding should never fail.
            }
        }

        Capsule::schema()->table('media_assets', function (Blueprint $table) {
            $table->dropColumn('migrated_from_inline_data_url');
        });
    }
};
