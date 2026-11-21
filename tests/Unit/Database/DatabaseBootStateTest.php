<?php

declare(strict_types=1);

use Spora\Core\Database;

/**
 * The connection a test opens has to go when the test is over.
 *
 * Resetting the boot state drops the capsule, and the capsule is freed — but
 * the *manager* it created outlives it, and the manager holds the
 * connection, so the socket stays open on the server. The manager and the
 * connection hold each other, so the pair is a reference cycle: only the
 * cycle collector breaks it, and the collector runs on its own schedule.
 *
 * Every test therefore opened a connection the collector had not got round
 * to yet, and the server's `max_connections` was reached long before it did.
 * The MySQL and MariaDB jobs failed with SQLSTATE[08004] — too many
 * connections — and how many were open depended on when the collector
 * happened to run.
 */
it('frees the connection the previous test left, so they do not pile up', function (): void {
    $manager = Database::getCapsule()->getDatabaseManager();
    $freed   = WeakReference::create($manager);

    // The local reference would keep it alive through the boot.
    unset($manager);

    TestDatabaseFactory::boot();

    expect($freed->get())->toBeNull();
});

it('keeps the connection the container holds', function (): void {
    $pdo = Database::getCapsule()->getConnection()->getPdo();

    TestDatabaseFactory::boot();

    // The container captured the `PDO` itself, not the manager — so the
    // collector frees the manager and the `PDO` the container still
    // references stays open and usable.
    expect($pdo)->not->toBeNull();
});
