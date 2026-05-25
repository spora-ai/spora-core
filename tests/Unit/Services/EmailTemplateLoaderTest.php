<?php

declare(strict_types=1);

use Spora\Services\EmailTemplateLoader;

describe('EmailTemplateLoader', function (): void {

    function makeLoader(): EmailTemplateLoader
    {
        return new EmailTemplateLoader();
    }

    describe('getAll', function (): void {
        it('returns all templates from the email-templates directory', function (): void {
            $loader = makeLoader();
            $templates = $loader->getAll();

            expect($templates)->not->toBeEmpty();
            expect($templates)->toBeArray();
        });

        it('includes expected template keys', function (): void {
            $loader = makeLoader();
            $templates = $loader->getAll();

            expect($templates)->toHaveKeys(['welcome', 'email_verification', 'password_reset', 'scheduled_run_completed']);
        });

        it('each template has required fields', function (): void {
            $loader = makeLoader();
            $templates = $loader->getAll();

            foreach ($templates as $name => $template) {
                expect($template)->toHaveKeys(['name', 'subject', 'body_text']);
                expect($template['name'])->toBe($name);
                expect($template['subject'])->toBeString();
                expect($template['body_text'])->toBeString();
            }
        });
    });

    describe('get', function (): void {
        it('returns a specific template by name', function (): void {
            $loader = makeLoader();
            $template = $loader->get('welcome');

            expect($template)->not->toBeNull();
            expect($template['name'])->toBe('welcome');
            expect($template['subject'])->toBe('Welcome to Spora');
        });

        it('returns null for non-existent template', function (): void {
            $loader = makeLoader();
            $template = $loader->get('non_existent_template');

            expect($template)->toBeNull();
        });

        it('returns template with null body_html when not set', function (): void {
            $loader = makeLoader();
            $template = $loader->get('welcome');

            expect($template['body_html'])->toBeNull();
        });
    });

    describe('caching', function (): void {
        it('caches templates after first load', function (): void {
            $loader = makeLoader();

            $first = $loader->getAll();
            $second = $loader->getAll();

            expect($first)->toBe($second);
        });

        it('does not re-parse files on subsequent calls', function (): void {
            $loader = makeLoader();

            $loader->getAll();
            $loader->get('welcome');

            $templates = $loader->getAll();
            expect($templates)->not->toBeEmpty();
        });
    });

});