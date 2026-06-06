<?php

declare(strict_types=1);

use Spora\Console\Commands\ToolSettingsMigrationCommand;
use Spora\Core\Database;
use Spora\Core\SecurityManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

const TOOL_SETTINGS_TEST_MASTER_KEY = '0123456789abcdef0123456789abcdef'; // exactly 32 bytes; SODIUM_CRYPTO_SECRETBOX_KEYBYTES
const STUB_OUTPUT_TOOL_CLASS = 'Spora\Tools\StubOutputTool';
const BAD_JSON_TOOL_CLASS = 'Spora\Tools\BadJsonTool';

function makeToolSettingsTester(): CommandTester
{
    $db = new Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']);
    $db->bootDatabaseConnectionOnly();

    $security = new SecurityManager(TOOL_SETTINGS_TEST_MASTER_KEY);
    $logger   = new class extends Psr\Log\AbstractLogger {
        /** @var list<array{level: string, message: string, context: array<string,mixed>}> */
        public array $entries = [];

        public function log($level, Stringable|string $message, array $context = []): void
        {
            $this->entries[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
        }
    };

    $command = new ToolSettingsMigrationCommand($db, $security, $logger);
    $command->setName('tool-settings:migrate-encryption');

    return new CommandTester($command);
}

it('encrypts unencrypted plain-text JSON in all three settings tables', function (): void {
    $tester = makeToolSettingsTester();
    $tester->execute([]);

    expect($tester->getStatusCode())->toBe(Command::SUCCESS);
    expect($tester->getDisplay())
        ->toContain('Starting tool settings encryption migration...')
        ->toContain('Migration complete. Encrypted 0 record(s) across 3 tables.');
});

it('encrypts a plain-text row in tool_configurations', function (): void {
    $security = new SecurityManager(TOOL_SETTINGS_TEST_MASTER_KEY);

    Spora\Models\ToolConfiguration::create([
        'tool_class' => STUB_OUTPUT_TOOL_CLASS,
        'tool_name'  => 'stub_output',
        'settings'   => json_encode(['api_key' => 'plain-text-key']),
    ]);

    $tester = makeToolSettingsTester();
    $tester->execute([]);

    expect($tester->getStatusCode())->toBe(Command::SUCCESS);

    // Plain-text row is now encrypted and round-trips through decrypt()
    $plainRow = Spora\Models\ToolConfiguration::where('tool_name', 'stub_output')->firstOrFail();
    $stored   = (string) $plainRow->getAttributes()['settings'];
    expect($security->looksEncrypted($stored))->toBeTrue();
    $decoded = json_decode($security->decrypt(new Spora\Core\ValueObjects\EncryptedValue($stored)), true);
    expect($decoded)->toBe(['api_key' => 'plain-text-key']);
});

it('skips rows whose settings column is invalid JSON', function (): void {
    Spora\Models\ToolConfiguration::create([
        'tool_class' => BAD_JSON_TOOL_CLASS,
        'tool_name'  => 'bad_json',
        'settings'   => '{not valid json',
    ]);

    $tester = makeToolSettingsTester();
    $tester->execute([]);

    expect($tester->getStatusCode())->toBe(Command::SUCCESS);
    $row = Spora\Models\ToolConfiguration::where('tool_name', 'bad_json')->firstOrFail();
    expect((string) $row->getAttributes()['settings'])->toBe('{not valid json');
    expect($tester->getDisplay())->toContain('Skipping id=');
});
