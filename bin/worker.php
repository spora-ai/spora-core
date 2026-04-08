<?php

declare(strict_types=1);

/**
 * CLI entry point for Spora worker commands.
 *
 * Usage:
 *   php bin/worker.php worker:run           # Cron mode: drain QUEUED tasks once
 *   php bin/worker.php worker:run --daemon  # Daemon mode: run until SIGTERM/SIGINT
 *
 * Environment:
 *   SPORA_WORKER_MODE  sync | cron | worker  (default: sync)
 */

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/vendor/autoload.php';

use Spora\Core\Database;
use Spora\Core\Kernel;
use Symfony\Component\Console\Application;

$kernel    = new Kernel();
$container = $kernel->getContainer();
$container->get(Database::class)->boot();

$app = new Application('Spora Worker', '1.0.0');
$app->add($container->get(Spora\Console\Commands\WorkerRunCommand::class));
$app->run();
