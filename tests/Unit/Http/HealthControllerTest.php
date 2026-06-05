<?php

declare(strict_types=1);

use Spora\Http\HealthController;

beforeEach(function (): void {
    Spora\Core\Database::resetBootState();
    $db = new Spora\Core\Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']);
    $db->boot();
});

afterEach(function (): void {
    Spora\Core\Database::resetBootState();
});

test('check() returns 200 with status ok when DB is up', function (): void {
    $controller = new HealthController();
    $response = $controller->check();

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['status'])->toBe('ok')
        ->and($body['database'])->toBe('connected');
});

test('check() returns 503 with status error when DB query throws', function (): void {
    // Build a controller, then break the underlying PDO so SELECT 1 throws.
    $controller = new HealthController();

    $connection = Illuminate\Database\Capsule\Manager::connection();
    $reflection = new ReflectionObject($connection);
    $pdoProperty = $reflection->getProperty('pdo');
    $originalPdo = $pdoProperty->getValue($connection);

    $brokenPdo = new class extends PDO {
        // intentionally do NOT call parent::__construct so this is unusable
        public function __construct() {}
        public function query(string $statement, ?int $fetchMode = null, mixed ...$fetch_mode_args): false|PDOStatement
        {
            throw new RuntimeException('forced failure for test');
        }
    };
    $pdoProperty->setValue($connection, $brokenPdo);

    try {
        $response = $controller->check();
        expect($response->getStatusCode())->toBe(503);
        $body = json_decode($response->getContent(), true);
        expect($body['status'])->toBe('error')
            ->and($body['database'])->toBe('unavailable');
    } finally {
        $pdoProperty->setValue($connection, $originalPdo);
    }
});
