<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Spora\Console\Commands\MailTemplatesSyncCommand;
use Spora\Core\Database;
use Spora\Core\Paths;
use Spora\Models\MailTemplate;
use Spora\Services\EmailTemplateLoader;
use Spora\Services\Mail\MailTemplateRenderer;
use Spora\Services\MailTemplateService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

function makeSyncTester(): CommandTester
{
    Database::resetBootState();
    $db = new Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']);
    $db->bootDatabaseConnectionOnly();

    Capsule::schema()->create('mail_templates', static function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->string('name')->unique();
        $table->text('subject')->nullable();
        $table->longText('body')->nullable();
        $table->text('body_html')->nullable();
        $table->timestamps();
    });

    $loader      = new EmailTemplateLoader(new Paths(BASE_PATH));
    $mailService = new MailTemplateService(MailTemplateRenderer::createDefault());

    $command = new MailTemplatesSyncCommand($db, $loader, $mailService);
    $command->setName('mail:templates:sync');

    return new CommandTester($command);
}

function seedMailTemplate(string $name, string $subject = 'Seeded subject', ?string $body = 'Seeded body', ?string $bodyHtml = null): int
{
    $now = date('Y-m-d H:i:s');
    return Capsule::table('mail_templates')->insertGetId([
        'name'       => $name,
        'subject'    => $subject,
        'body'       => $body,
        'body_html'  => $bodyHtml,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

it('creates every YAML template when the DB is empty', function (): void {
    $tester = makeSyncTester();

    $status = $tester->execute([]);
    $display = $tester->getDisplay();

    expect($status)->toBe(Command::SUCCESS)
        ->and($display)->toContain('[created]')
        ->and(MailTemplate::count())->toBeGreaterThan(0)
        ->and(MailTemplate::where('name', 'welcome')->exists())->toBeTrue()
        ->and(MailTemplate::where('name', 'welcome')->value('subject'))->toContain('Welcome');
});

it('reports unchanged when DB rows match YAML defaults', function (): void {
    $tester = makeSyncTester();

    $defaults = (new EmailTemplateLoader(new Paths(BASE_PATH)))->getAll();
    foreach ($defaults as $template) {
        seedMailTemplate(
            (string) $template['name'],
            (string) $template['subject'],
            $template['body'] ?? null,
            $template['body_html'] ?? null,
        );
    }

    $status = $tester->execute([]);
    $display = $tester->getDisplay();

    expect($status)->toBe(Command::SUCCESS)
        ->and($display)->toContain('unchanged')
        ->and($display)->not->toContain('[created]');
});

it('skips a drifted row in interactive mode when the user declines', function (): void {
    $tester = makeSyncTester();

    seedMailTemplate('welcome', 'OLD subject that drifted', 'OLD body that drifted', null);

    $tester->setInputs(['no']);
    $status = $tester->execute([]);

    expect($status)->toBe(Command::SUCCESS)
        ->and($tester->getDisplay())->toContain('[skipped]')
        ->and(MailTemplate::where('name', 'welcome')->value('subject'))->toBe('OLD subject that drifted');
});

it('forces a drifted row to match YAML when --force is passed', function (): void {
    $tester = makeSyncTester();

    seedMailTemplate('welcome', 'OLD subject that drifted', 'OLD body that drifted', null);

    $status = $tester->execute(['--force' => true]);
    $display = $tester->getDisplay();

    expect($status)->toBe(Command::SUCCESS)
        ->and($display)->toContain('[forced]')
        ->and(MailTemplate::where('name', 'welcome')->value('subject'))->toContain('Welcome');
});

it('reports drift and exits non-zero under --check', function (): void {
    $tester = makeSyncTester();

    seedMailTemplate('welcome', 'OLD subject that drifted', 'OLD body that drifted', null);

    $status = $tester->execute(['--check' => true]);
    $display = $tester->getDisplay();

    expect($status)->toBe(Command::FAILURE)
        ->and($display)->toContain('[drift]')
        ->and($display)->toContain('drifted from YAML defaults')
        ->and(MailTemplate::where('name', 'welcome')->value('subject'))->toBe('OLD subject that drifted');
});

it('leaves orphan DB rows untouched and does not list them in the summary', function (): void {
    $tester = makeSyncTester();

    seedMailTemplate('welcome', 'Welcome to {{site_name}}', "Hi {{user_name}},\n\nwelcome aboard! Your {{site_name}} account is ready at **{{email}}**.\n\nYou can now create AI agents, schedule runs, and explore the tools. If\nyou have any questions, the documentation and community are a click away.\n\n— The {{site_name}} team\n", null);
    $orphanId = seedMailTemplate('orphan_template', 'orphan subject', 'orphan body', null);

    $status = $tester->execute([]);
    $display = $tester->getDisplay();

    expect($status)->toBe(Command::SUCCESS)
        ->and($display)->not->toContain('orphan_template')
        ->and(MailTemplate::where('name', 'orphan_template')->value('subject'))->toBe('orphan subject')
        ->and((int) MailTemplate::where('name', 'orphan_template')->value('id'))->toBe($orphanId);
});

it('exits non-zero when a YAML template fails to parse', function (): void {
    Database::resetBootState();
    $db = new Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']);
    $db->bootDatabaseConnectionOnly();

    $tmpDir = sys_get_temp_dir() . '/spora-broken-yaml-' . uniqid('', true);
    $emailDir = $tmpDir . '/email-templates';
    mkdir($emailDir, 0755, true);
    file_put_contents($emailDir . '/broken.yaml', "name: broken\nsubject: ': unterminated quote\n");

    $paths  = new Paths($tmpDir);
    $loader = new EmailTemplateLoader($paths);

    $command = new MailTemplatesSyncCommand($db, $loader, new MailTemplateService(MailTemplateRenderer::createDefault()));
    $command->setName('mail:templates:sync');
    $tester = new CommandTester($command);

    try {
        $status = $tester->execute([]);
        expect($status)->toBe(Command::FAILURE)
            ->and($tester->getDisplay())->toContain('YAML parse failed');
    } finally {
        @unlink($emailDir . '/broken.yaml');
        @rmdir($emailDir);
        @rmdir($tmpDir);
    }
});
