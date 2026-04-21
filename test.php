<?php
define('BASE_PATH', __DIR__);
require 'vendor/autoload.php';
$kernel = new Spora\Core\Kernel();
$container = $kernel->getContainer();
$container->get(\Spora\Core\Database::class)->bootDatabaseConnectionOnly();
use Spora\Models\Notification;

$notif = new Notification();
$notif->fill([
    'user_id' => 1,
    'type' => 'test',
    'title' => 'Test',
]);
$notif->save();
var_dump($notif->toArray());
