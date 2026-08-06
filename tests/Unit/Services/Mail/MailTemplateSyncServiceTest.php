<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Spora\Core\Database;
use Spora\Core\Paths;
use Spora\Models\MailTemplate;
use Spora\Services\EmailTemplateLoader;
use Spora\Services\Mail\MailTemplateRenderer;
use Spora\Services\Mail\MailTemplateSyncService;
use Spora\Services\MailTemplateService;

/**
 * Tests for {@see MailTemplateSyncService}. Each test boots a fresh
 * in-memory SQLite database with the production schema, then exercises
 * one branch of the reconcile logic.
 */
describe('MailTemplateSyncService', function (): void {

    beforeEach(function (): void {
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
    });

    afterEach(function (): void {
        Database::resetBootState();
    });

    function makeService(string $dir): MailTemplateSyncService
    {
        $loader = new EmailTemplateLoader(new Paths($dir));
        $mailService = new MailTemplateService(MailTemplateRenderer::createDefault());

        return new MailTemplateSyncService($loader, $mailService);
    }

    function writeTemplate(string $dir, string $filename, string $name, string $subject, string $body): void
    {
        $emailDir = $dir . '/email-templates';
        if (!is_dir($emailDir)) {
            mkdir($emailDir, 0755, true);
        }
        // `|-` keeps the body literal but strips the trailing newline,
        // so the stored value matches the fixture text exactly.
        file_put_contents(
            $emailDir . '/' . $filename,
            "name: {$name}\nsubject: \"{$subject}\"\nbody: |-\n  {$body}\n",
        );
    }

    function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/email-templates/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir . '/email-templates');
        @rmdir($dir);
    }

    it('inserts templates that exist on disk but not in the DB', function (): void {
        $dir = sys_get_temp_dir() . '/spora-sync-new-' . uniqid('', true);
        writeTemplate($dir, 'welcome.yaml', 'welcome', 'Welcome!', 'Hello body.');
        writeTemplate($dir, 'password_reset.yaml', 'password_reset', 'Reset', 'Reset body.');

        try {
            $statuses = makeService($dir)->reconcileDefaults(force: false);

            expect($statuses)->toHaveKey('welcome')
                ->and($statuses)->toHaveKey('password_reset')
                ->and($statuses['welcome'])->toBe(MailTemplateSyncService::STATUS_CREATED)
                ->and($statuses['password_reset'])->toBe(MailTemplateSyncService::STATUS_CREATED)
                ->and(MailTemplate::where('name', 'welcome')->exists())->toBeTrue()
                ->and(MailTemplate::where('name', 'password_reset')->exists())->toBeTrue();
        } finally {
            removeDir($dir);
        }
    });

    it('reports unchanged when DB rows already match YAML defaults', function (): void {
        $dir = sys_get_temp_dir() . '/spora-sync-unchanged-' . uniqid('', true);
        writeTemplate($dir, 'welcome.yaml', 'welcome', 'Welcome!', 'Hello body.');

        try {
            // Seed the DB row from YAML first.
            makeService($dir)->reconcileDefaults(force: false);

            // Re-running with force=false should report unchanged.
            $statuses = makeService($dir)->reconcileDefaults(force: false);

            expect($statuses['welcome'])->toBe(MailTemplateSyncService::STATUS_UNCHANGED);
        } finally {
            removeDir($dir);
        }
    });

    it('reports drift for existing rows that do not match YAML defaults (no force)', function (): void {
        $dir = sys_get_temp_dir() . '/spora-sync-drift-' . uniqid('', true);
        writeTemplate($dir, 'welcome.yaml', 'welcome', 'Welcome FROM YAML', 'Body from YAML.');

        try {
            $service = makeService($dir);
            $service->reconcileDefaults(force: false);

            // Operator customises the row directly.
            MailTemplate::where('name', 'welcome')->update([
                'subject' => 'Welcome FROM OPERATOR',
                'body'    => 'Body customised by operator.',
            ]);

            $statuses = $service->reconcileDefaults(force: false);

            expect($statuses['welcome'])->toBe(MailTemplateSyncService::STATUS_DRIFT);
            // Operator customisation is preserved.
            $row = MailTemplate::where('name', 'welcome')->first();
            expect($row->subject)->toBe('Welcome FROM OPERATOR');
            expect($row->body)->toBe('Body customised by operator.');
        } finally {
            removeDir($dir);
        }
    });

    it('overwrites drifted rows when force is true', function (): void {
        $dir = sys_get_temp_dir() . '/spora-sync-force-' . uniqid('', true);
        writeTemplate($dir, 'welcome.yaml', 'welcome', 'Welcome FROM YAML', 'Body from YAML.');

        try {
            $service = makeService($dir);
            $service->reconcileDefaults(force: false);

            // Operator diverges from the YAML.
            MailTemplate::where('name', 'welcome')->update([
                'subject' => 'Welcome FROM OPERATOR',
            ]);

            $statuses = $service->reconcileDefaults(force: true);

            expect($statuses['welcome'])->toBe(MailTemplateSyncService::STATUS_FORCED);
            $row = MailTemplate::where('name', 'welcome')->first();
            expect($row->subject)->toBe('Welcome FROM YAML');
        } finally {
            removeDir($dir);
        }
    });

    it('does not leave orphan DB rows alone (does not delete them)', function (): void {
        $dir = sys_get_temp_dir() . '/spora-sync-orphan-' . uniqid('', true);
        writeTemplate($dir, 'welcome.yaml', 'welcome', 'Welcome!', 'Hello body.');

        try {
            $service = makeService($dir);

            // Insert a custom template that has no matching YAML file.
            MailTemplate::create([
                'name'    => 'orphan_template',
                'subject' => 'orphan subject',
                'body'    => 'orphan body',
            ]);

            $statuses = $service->reconcileDefaults(force: true);

            expect($statuses)->toHaveKey('welcome');
            expect($statuses)->not->toHaveKey('orphan_template');
            expect(MailTemplate::where('name', 'orphan_template')->exists())->toBeTrue();
        } finally {
            removeDir($dir);
        }
    });

    it('diffDefaults returns only the fields that differ', function (): void {
        $dir = sys_get_temp_dir() . '/spora-sync-diff-' . uniqid('', true);
        writeTemplate($dir, 'welcome.yaml', 'welcome', 'Subject A', 'Body A');

        try {
            $service = makeService($dir);
            $service->reconcileDefaults(force: false);

            $row = MailTemplate::where('name', 'welcome')->first();

            // Equal fields → empty diff.
            $sameDiff = $service->diffDefaults([
                'name' => 'welcome', 'subject' => 'Subject A', 'body' => 'Body A', 'body_html' => null,
            ], $row);
            expect($sameDiff)->toBe([]);

            // Subject differs → only subject is reported.
            $subjectDiff = $service->diffDefaults([
                'name' => 'welcome', 'subject' => 'Subject B', 'body' => 'Body A', 'body_html' => null,
            ], $row);
            expect($subjectDiff)->toBe(['subject' => 'Subject B']);

            // Body differs → only body.
            $bodyDiff = $service->diffDefaults([
                'name' => 'welcome', 'subject' => 'Subject A', 'body' => 'Body B', 'body_html' => null,
            ], $row);
            expect($bodyDiff)->toBe(['body' => 'Body B']);
        } finally {
            removeDir($dir);
        }
    });

    it('createMissing inserts a new template row', function (): void {
        $dir = sys_get_temp_dir() . '/spora-sync-createmissing-' . uniqid('', true);
        writeTemplate($dir, 'welcome.yaml', 'welcome', 'Welcome!', 'Hello body.');

        try {
            $service = makeService($dir);
            $service->createMissing(['name' => 'welcome', 'subject' => 'Welcome!', 'body' => 'Hello body.', 'body_html' => null]);

            $row = MailTemplate::where('name', 'welcome')->first();
            expect($row)->not->toBeNull();
            expect($row->subject)->toBe('Welcome!');
        } finally {
            removeDir($dir);
        }
    });

    it('updateTemplate patches only the supplied fields', function (): void {
        $dir = sys_get_temp_dir() . '/spora-sync-update-' . uniqid('', true);
        writeTemplate($dir, 'welcome.yaml', 'welcome', 'Subject A', 'Body A');

        try {
            $service = makeService($dir);
            $service->reconcileDefaults(force: false);

            $row = MailTemplate::where('name', 'welcome')->first();
            $service->updateTemplate((int) $row->id, ['subject' => 'Subject B']);

            $row->refresh();
            expect($row->subject)->toBe('Subject B');
            // Body was not in the patch — must be preserved.
            expect($row->body)->toBe('Body A');
        } finally {
            removeDir($dir);
        }
    });

    it('runs idempotently: two passes against the same YAML produce the same row count', function (): void {
        // The framework ships 5 default templates that {@see Paths::emailTemplatesPaths()}
        // also returns, so the loader will pick them up alongside any project overrides.
        // Use those framework names so the project overrides win and the total
        // template count is the 5 we expect.
        $dir = sys_get_temp_dir() . '/spora-sync-idempotent-' . uniqid('', true);
        writeTemplate($dir, 'welcome.yaml', 'welcome', 'Welcome!', 'Hello body.');
        writeTemplate($dir, 'email_verification.yaml', 'email_verification', 'Verify', 'Verify body.');
        writeTemplate($dir, 'email_change_verification.yaml', 'email_change_verification', 'Change', 'Change body.');
        writeTemplate($dir, 'password_reset.yaml', 'password_reset', 'Reset', 'Reset body.');
        writeTemplate($dir, 'scheduled_run_completed.yaml', 'scheduled_run_completed', 'Done', 'Done body.');

        try {
            $service = makeService($dir);
            $first  = $service->reconcileDefaults(force: false);
            $second = $service->reconcileDefaults(force: false);

            expect($first)->toHaveCount(5);
            expect($second)->toHaveCount(5);
            expect(MailTemplate::count())->toBe(5);

            // Second pass: every template is unchanged.
            foreach ($second as $name => $status) {
                expect($status)->toBe(MailTemplateSyncService::STATUS_UNCHANGED);
            }
        } finally {
            removeDir($dir);
        }
    });
});
