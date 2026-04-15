#!/usr/bin/env php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use Dunglas\FrankenPHP\Server;

$server = new Server();
$server->run();