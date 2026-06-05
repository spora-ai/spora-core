<?php

declare(strict_types=1);

use Spora\Models\MailTemplate;
use Spora\Services\MailTemplateService;

describe('MailTemplateService', function (): void {

    it('returns an empty list when there are no templates', function (): void {
        $service = new MailTemplateService();
        expect($service->getAllTemplates())->toBe([]);
    });

    it('returns a list of (id, name) tuples', function (): void {
        $service = new MailTemplateService();
        MailTemplate::create(['name' => 'welcome', 'subject' => 'Hi']);
        MailTemplate::create(['name' => 'reset',   'subject' => 'Reset']);

        $all = $service->getAllTemplates();
        expect($all)->toHaveCount(2);
        expect(array_column($all, 'name'))->toContain('welcome', 'reset');
    });

    it('creates a template and returns the resource', function (): void {
        $service = new MailTemplateService();

        $result = $service->createTemplate([
            'name'      => 'verify',
            'subject'   => 'Verify your account',
            'body_text' => 'Click the link',
            'body_html' => '<p>Click the link</p>',
        ]);

        expect($result['mail_template']['name'])->toBe('verify');
        expect($result['mail_template']['subject'])->toBe('Verify your account');
        expect($result['mail_template']['body_text'])->toBe('Click the link');
        expect($result['mail_template']['body_html'])->toBe('<p>Click the link</p>');
    });

    it('returns null from getTemplate for an unknown id', function (): void {
        $service = new MailTemplateService();
        expect($service->getTemplate(9999))->toBeNull();
    });

    it('returns the resource from getTemplate when found', function (): void {
        $service = new MailTemplateService();
        $created = $service->createTemplate(['name' => 't1', 'subject' => 's1']);

        $found = $service->getTemplate($created['mail_template']['id']);
        expect($found['mail_template']['name'])->toBe('t1');
    });

    it('updates a template and returns the new resource', function (): void {
        $service = new MailTemplateService();
        $created = $service->createTemplate(['name' => 'orig', 'subject' => 'old']);

        $result = $service->updateTemplate($created['mail_template']['id'], [
            'name'    => 'new',
            'subject' => 'new subject',
        ]);

        expect($result['mail_template']['name'])->toBe('new');
        expect($result['mail_template']['subject'])->toBe('new subject');
    });

    it('returns null from updateTemplate for an unknown id', function (): void {
        $service = new MailTemplateService();
        expect($service->updateTemplate(9999, ['name' => 'x']))->toBeNull();
    });

    it('deletes a template and returns true', function (): void {
        $service = new MailTemplateService();
        $created = $service->createTemplate(['name' => 'del', 'subject' => 's']);

        expect($service->deleteTemplate($created['mail_template']['id']))->toBeTrue();
        expect(MailTemplate::find($created['mail_template']['id']))->toBeNull();
    });

    it('refuses to delete a system template and returns false', function (): void {
        $service = new MailTemplateService();
        $created = $service->createTemplate(['name' => 'welcome', 'subject' => 's']);

        expect($service->deleteTemplate($created['mail_template']['id']))->toBeFalse();
        expect(MailTemplate::find($created['mail_template']['id']))->not->toBeNull();
    });

    it('returns false from deleteTemplate for an unknown id', function (): void {
        $service = new MailTemplateService();
        expect($service->deleteTemplate(9999))->toBeFalse();
    });

    it('previewTemplate renders the template with the supplied variables', function (): void {
        $service = new MailTemplateService();
        $service->createTemplate([
            'name'      => 'preview-test',
            'subject'   => 'Hello {{name}}',
            'body_text' => 'Hi {{name}}, your code is {{code}}.',
            'body_html' => '<p>Hi {{name}}, your code is {{code}}.</p>',
        ]);

        $rendered = $service->previewTemplate('preview-test', [
            'name' => 'Fabian',
            'code' => '1234',
        ]);

        expect($rendered['subject'])->toBe('Hello Fabian');
        expect($rendered['body_text'])->toBe('Hi Fabian, your code is 1234.');
        expect($rendered['body_html'])->toBe('<p>Hi Fabian, your code is 1234.</p>');
    });

    it('previewTemplate leaves unknown placeholders intact', function (): void {
        $service = new MailTemplateService();
        $service->createTemplate([
            'name'    => 'preview-unknown',
            'subject' => 'Hello {{name}} ({{unknown}})',
        ]);

        $rendered = $service->previewTemplate('preview-unknown', [
            'name' => 'Ada',
        ]);

        expect($rendered['subject'])->toBe('Hello Ada ({{unknown}})');
    });
});
