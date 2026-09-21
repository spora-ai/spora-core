<?php

declare(strict_types=1);

use Spora\Tools\ScheduleTool\ScheduleUpdateValidator;

describe('ScheduleUpdateValidator — direct unit tests', function (): void {
    beforeEach(function (): void {
        $this->validator = new ScheduleUpdateValidator();
    });

    describe('validateUpdateSchedulePatch', function (): void {
        test('empty patch is rejected', function (): void {
            $result = $this->validator->validateUpdateSchedulePatch(['schedule_patch' => []]);
            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('patch object is required');
        });

        test('patch must be an array', function (): void {
            $result = $this->validator->validateUpdateSchedulePatch(['schedule_patch' => 'not-an-array']);
            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('patch object is required');
        });

        test('unknown keys are rejected with the allowlist', function (): void {
            $result = $this->validator->validateUpdateSchedulePatch([
                'schedule_patch' => ['sql_injection' => '; DROP TABLE'],
            ]);
            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('not a mutable key')
                ->and($result->content)->toContain('template_id')
                ->and($result->content)->toContain('is_active');
        });

        test('is_active must be a boolean', function (): void {
            $result = $this->validator->validateUpdateSchedulePatch([
                'schedule_patch' => ['is_active' => 'yes'],
            ]);
            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('is_active')
                ->and($result->content)->toContain('boolean');
        });

        test('both cron_expression and run_at populated at once is rejected', function (): void {
            $result = $this->validator->validateUpdateSchedulePatch([
                'schedule_patch' => [
                    'cron_expression' => '0 7 * * *',
                    'run_at'          => '2026-12-31T23:59:00+00:00',
                ],
            ]);
            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('mutually exclusive');
        });

        test('timezone as non-string is rejected', function (): void {
            $result = $this->validator->validateUpdateSchedulePatch([
                'schedule_patch' => ['timezone' => 42],
            ]);
            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('timezone')
                ->and($result->content)->toContain('string');
        });

        test('timezone over 50 chars is rejected', function (): void {
            $result = $this->validator->validateUpdateSchedulePatch([
                'schedule_patch' => ['timezone' => str_repeat('a', 51)],
            ]);
            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('50 characters');
        });

        test('unknown IANA timezone is rejected', function (): void {
            $result = $this->validator->validateUpdateSchedulePatch([
                'schedule_patch' => ['timezone' => 'Mars/Olympus'],
            ]);
            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('valid IANA');
        });

        test('invalid cron_expression is rejected', function (): void {
            $result = $this->validator->validateUpdateSchedulePatch([
                'schedule_patch' => ['cron_expression' => 'not-a-cron'],
            ]);
            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('cron_expression')
                ->and($result->content)->toContain('invalid');
        });

        test('empty cron_expression is rejected', function (): void {
            $result = $this->validator->validateUpdateSchedulePatch([
                'schedule_patch' => ['cron_expression' => '   '],
            ]);
            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('cron_expression');
        });

        test('a valid cron_expression is accepted', function (): void {
            $result = $this->validator->validateUpdateSchedulePatch([
                'schedule_patch' => ['cron_expression' => '0 9 * * *'],
            ]);
            expect($result)->toBeArray();
        });

        test('run_at must be ISO 8601 in the patch timezone', function (): void {
            $result = $this->validator->validateUpdateSchedulePatch([
                'schedule_patch' => [
                    'timezone' => 'Europe/Berlin',
                    'run_at'   => 'not-a-date',
                ],
            ]);
            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('ISO 8601');
        });

        test('run_at as empty string is rejected', function (): void {
            $result = $this->validator->validateUpdateSchedulePatch([
                'schedule_patch' => ['run_at' => '   '],
            ]);
            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('ISO 8601');
        });

        test('template_id with non-int is rejected', function (): void {
            $result = $this->validator->validateUpdateSchedulePatch([
                'schedule_patch' => ['template_id' => 'oops'],
            ]);
            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('positive integer');
        });

        test('max_steps_override must be in range 1..100 or null', function (): void {
            $tooLarge = $this->validator->validateUpdateSchedulePatch([
                'schedule_patch' => ['max_steps_override' => 999],
            ]);
            expect($tooLarge->success)->toBeFalse()
                ->and($tooLarge->content)->toContain('max_steps_override');

            $tooSmall = $this->validator->validateUpdateSchedulePatch([
                'schedule_patch' => ['max_steps_override' => 0],
            ]);
            expect($tooSmall->success)->toBeFalse();

            $wrongType = $this->validator->validateUpdateSchedulePatch([
                'schedule_patch' => ['max_steps_override' => 'oops'],
            ]);
            expect($wrongType->success)->toBeFalse();

            $okNull = $this->validator->validateUpdateSchedulePatch([
                'schedule_patch' => ['max_steps_override' => null],
            ]);
            expect($okNull)->toBeArray();

            $okRange = $this->validator->validateUpdateSchedulePatch([
                'schedule_patch' => ['max_steps_override' => 50],
            ]);
            expect($okRange)->toBeArray();
        });

        test('cron_expression = null lets the service reset to one-shot mode', function (): void {
            $result = $this->validator->validateUpdateSchedulePatch([
                'schedule_patch' => [
                    'cron_expression' => null,
                    'run_at'          => date('Y-m-d\TH:i:sP', strtotime('+1 hour')),
                ],
            ]);
            expect($result)->toBeArray();
        });
    });

    describe('validateUpdateTemplatePatch', function (): void {
        test('name must be 1..100 chars', function (): void {
            $tooLong = $this->validator->validateUpdateTemplatePatch([
                'template_patch' => ['name' => str_repeat('a', 101)],
            ]);
            expect($tooLong->success)->toBeFalse()
                ->and($tooLong->content)->toContain('1..100 chars');

            $empty = $this->validator->validateUpdateTemplatePatch([
                'template_patch' => ['name' => '   '],
            ]);
            expect($empty->success)->toBeFalse();

            $nonString = $this->validator->validateUpdateTemplatePatch([
                'template_patch' => ['name' => 123],
            ]);
            expect($nonString->success)->toBeFalse();

            $ok = $this->validator->validateUpdateTemplatePatch([
                'template_patch' => ['name' => 'Renamed'],
            ]);
            expect($ok)->toBeArray();
        });

        test('max_steps must be int 1..100 or null', function (): void {
            $bad = $this->validator->validateUpdateTemplatePatch([
                'template_patch' => ['max_steps' => 1000],
            ]);
            expect($bad->success)->toBeFalse()
                ->and($bad->content)->toContain('max_steps');

            $nullOk = $this->validator->validateUpdateTemplatePatch([
                'template_patch' => ['max_steps' => null],
            ]);
            expect($nullOk)->toBeArray();
        });

        test('variables accepts a well-formed list or null', function (): void {
            $bad = $this->validator->validateUpdateTemplatePatch([
                'template_patch' => ['variables' => 'not-an-array'],
            ]);
            expect($bad->success)->toBeFalse()
                ->and($bad->content)->toContain('variables');

            $badEntry = $this->validator->validateUpdateTemplatePatch([
                'template_patch' => ['variables' => [['no_key' => 'oops']]],
            ]);
            expect($badEntry->success)->toBeFalse()
                ->and($badEntry->content)->toContain('variables');

            $ok = $this->validator->validateUpdateTemplatePatch([
                'template_patch' => ['variables' => [['key' => 'name', 'default_value' => 'world']]],
            ]);
            expect($ok)->toBeArray();

            $nullOk = $this->validator->validateUpdateTemplatePatch([
                'template_patch' => ['variables' => null],
            ]);
            expect($nullOk)->toBeArray();
        });

        test('rejects unknown keys', function (): void {
            $result = $this->validator->validateUpdateTemplatePatch([
                'template_patch' => ['no_such_key' => 'x'],
            ]);
            expect($result->success)->toBeFalse();
        });
    });
});
