<?php
require 'vendor/autoload.php';
$kernel = new \Spora\Core\Kernel();
$container = $kernel->getContainer();
echo get_class($container) . "\n";
