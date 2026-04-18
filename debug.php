<?php
require 'vendor/autoload.php';
$app = require_once __DIR__ . '/app/Core/container.php';
$app->get(\Spora\Core\Database::class)->connect();

use Spora\Models\Notification;
use Illuminate\Database\Capsule\Manager as DB;

DB::statement('CREATE TABLE IF NOT EXISTS test_notifications (id integer primary key, type text, created_at datetime default "CURRENT_TIMESTAMP")');

// Try to insert one
DB::table('test_notifications')->insert(['type' => 'test']);
$row = DB::table('test_notifications')->first();
var_dump($row->created_at);

