<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Core\Database;

beforeEach(function (): void {
    Database::resetBootState();
    $db = new Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']);
    $db->bootDatabaseConnectionOnly();

    // Create the mail_templates table with the legacy column shape so renameColumn has something to act on.
    Capsule::schema()->create('mail_templates', static function (Illuminate\Database\Schema\Blueprint $table): void {
        $table->bigIncrements('id');
        $table->string('name')->unique();
        $table->text('subject')->nullable();
        $table->text('body_text')->nullable();
        $table->text('body_html')->nullable();
        $table->timestamps();
    });
});

test('up renames body_text to body', function (): void {
    $migration = require __DIR__ . '/../../../database/migrations/0065_rename_body_text_to_body_in_mail_templates.php';
    $migration->up();

    expect(Capsule::schema()->hasColumn('mail_templates', 'body'))->toBeTrue();
    expect(Capsule::schema()->hasColumn('mail_templates', 'body_text'))->toBeFalse();
});

test('down renames body back to body_text', function (): void {
    $migration = require __DIR__ . '/../../../database/migrations/0065_rename_body_text_to_body_in_mail_templates.php';
    $migration->up();
    $migration->down();

    expect(Capsule::schema()->hasColumn('mail_templates', 'body_text'))->toBeTrue();
    expect(Capsule::schema()->hasColumn('mail_templates', 'body'))->toBeFalse();
});
