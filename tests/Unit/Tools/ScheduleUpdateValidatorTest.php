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

        test('whitespace-only cron_expression is coerced to null (clears)', function (): void {
            // After the Round 5 leniency, `trim()`ed "" / "null" / "  "
            // all coerce to null and clear the field. A genuinely-invalid
            // cron like "definitely not a cron" is still rejected (see
            // the regression test at the bottom of this describe).
            $result = $this->validator->validateUpdateSchedulePatch([
                'schedule_patch' => ['cron_expression' => '   '],
            ]);
            expect($result)->toBeArray()
                ->and($result)->toBe(['cron_expression' => null]);
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

        test('run_at as whitespace-only string is coerced to null (clears)', function (): void {
            $result = $this->validator->validateUpdateSchedulePatch([
                'schedule_patch' => ['run_at' => '   '],
            ]);
            expect($result)->toBeArray()
                ->and($result)->toBe(['run_at' => null]);
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

    describe('lenient LLM type coercion', function (): void {
        test('is_active accepts bool, int 0/1, and string "true"/"false"', function (): void {
            foreach ([true, false, 0, 1, '0', '1', 'true', 'false'] as $value) {
                $result = $this->validator->validateUpdateSchedulePatch([
                    'schedule_patch' => ['is_active' => $value],
                ]);
                expect($result)->toBeArray("is_active " . var_export($value, true) . " should validate as array");
            }

            // Truly hostile inputs still reject
            $r = $this->validator->validateUpdateSchedulePatch([
                'schedule_patch' => ['is_active' => 'maybe'],
            ]);
            expect($r->success)->toBeFalse()->and($r->content)->toContain('`is_active` must be a boolean');
        });

        test('max_steps_override accepts int, numeric string, integral float', function (): void {
            foreach ([25, '25', 25.0] as $value) {
                $result = $this->validator->validateUpdateSchedulePatch([
                    'schedule_patch' => ['max_steps_override' => $value],
                ]);
                expect($result)->toBeArray(
                    "max_steps_override " . var_export($value, true) . " should validate as array",
                );
            }

            // Out-of-range still rejected
            $r = $this->validator->validateUpdateSchedulePatch([
                'schedule_patch' => ['max_steps_override' => 200],
            ]);
            expect($r->success)->toBeFalse()->and($r->content)->toContain('between 1 and 100');

            // Non-numeric still rejected
            $r2 = $this->validator->validateUpdateSchedulePatch([
                'schedule_patch' => ['max_steps_override' => 'abc'],
            ]);
            expect($r2->success)->toBeFalse();
        });

        test('template_id on update accepts int and numeric string', function (): void {
            foreach ([4, '4'] as $value) {
                $result = $this->validator->validateUpdateSchedulePatch([
                    'schedule_patch' => ['template_id' => $value],
                ]);
                expect($result)->toBeArray(
                    "template_id " . var_export($value, true) . " should validate as array",
                );
            }

            $r = $this->validator->validateUpdateSchedulePatch([
                'schedule_patch' => ['template_id' => 'oops'],
            ]);
            expect($r->success)->toBeFalse()->and($r->content)->toContain('`template_id`');
        });

        test('multi-field patch stays atomic — one bad field rejects the whole patch', function (): void {
            $result = $this->validator->validateUpdateSchedulePatch([
                'schedule_patch' => [
                    'is_active' => false,
                    'timezone'  => 'Europe/Prague',
                    'max_steps_override' => 'abc',
                ],
            ]);
            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('`max_steps_override`');
        });
    });

    /**
     * Bug report (Round 5): a user reported that all five "clear by null"
     * fields rejected the literal four-character string "null" — what
     * models emit on the wire when they intend JSON null.
     *
     * Investigation showed:
     *   - JSON null works correctly (the previous "send JSON null, not
     *     the string" guidance was honest, but unhelpful in practice —
     *     LLMs don't reliably produce JSON null through the OpenAI
     *     tool-call wire shape, where `arguments` is a JSON-encoded
     *     string and `"cron_expression":"null"` arrives at the
     *     validator as the PHP string "null").
     *   - Real PHP null was already accepted.
     *
     * The fix is lenient normalisation: the validator coerces the
     * literal string "null" / "NULL" / "Null" / "" to PHP null BEFORE
     * the field-type checks, so the existing null-aware paths treat
     * these as real clears.
     *
     * These tests pin both halves of the new contract for every clearable
     * field:
     *   (a) JSON null clears each field (the strict-positive contract).
     *   (b) the four-character string "null" / "" / "NULL" / "Null" is
     *       now coerced to null and clears the field too.
     */
    describe('Null clears fields (JSON null and string "null" both work)', function (): void {
        test('JSON null clears max_steps_override', function (): void {
            $result = $this->validator->validateUpdateSchedulePatch([
                'schedule_id'     => 1,
                'schedule_patch'  => ['max_steps_override' => null],
            ]);
            expect($result)->toBeArray()
                ->and($result)->toBe(['max_steps_override' => null]);
        });

        test('JSON null clears template_id', function (): void {
            $result = $this->validator->validateUpdateSchedulePatch([
                'schedule_id'     => 1,
                'schedule_patch'  => ['template_id' => null],
            ]);
            expect($result)->toBeArray()
                ->and($result)->toBe(['template_id' => null]);
        });

        test('JSON null clears cron_expression (kept with valid run_at)', function (): void {
            $result = $this->validator->validateUpdateSchedulePatch([
                'schedule_id'     => 1,
                'schedule_patch'  => [
                    'cron_expression' => null,
                    'run_at'          => '2099-12-31T00:00:00+00:00',
                ],
            ]);
            expect($result)->toBeArray();
        });

        test('JSON null clears run_at (kept with valid cron_expression)', function (): void {
            $result = $this->validator->validateUpdateSchedulePatch([
                'schedule_id'     => 1,
                'schedule_patch'  => [
                    'cron_expression' => '0 9 * * *',
                    'run_at'          => null,
                ],
            ]);
            expect($result)->toBeArray();
        });

        test('JSON null clears max_steps on prompt template', function (): void {
            $result = $this->validator->validateUpdateTemplatePatch([
                'template_id'     => 1,
                'template_patch'  => ['max_steps' => null],
            ]);
            expect($result)->toBeArray()
                ->and($result)->toBe(['max_steps' => null]);
        });

        test('string "null" for max_steps_override is coerced to null and clears', function (): void {
            $result = $this->validator->validateUpdateSchedulePatch([
                'schedule_patch' => ['max_steps_override' => 'null'],
            ]);
            expect($result)->toBeArray()
                ->and($result)->toBe(['max_steps_override' => null]);
        });

        test('string "null" for template_id is coerced to null and clears', function (): void {
            $result = $this->validator->validateUpdateSchedulePatch([
                'schedule_patch' => ['template_id' => 'null'],
            ]);
            expect($result)->toBeArray()
                ->and($result)->toBe(['template_id' => null]);
        });

        test('string "null" for cron_expression is coerced to null and clears', function (): void {
            $result = $this->validator->validateUpdateSchedulePatch([
                'schedule_patch' => ['cron_expression' => 'null'],
            ]);
            expect($result)->toBeArray()
                ->and($result)->toBe(['cron_expression' => null]);
        });

        test('string "null" for run_at is coerced to null and clears', function (): void {
            $result = $this->validator->validateUpdateSchedulePatch([
                'schedule_patch' => ['run_at' => 'null'],
            ]);
            expect($result)->toBeArray()
                ->and($result)->toBe(['run_at' => null]);
        });

        test('string "null" for template max_steps is coerced to null and clears', function (): void {
            $result = $this->validator->validateUpdateTemplatePatch([
                'template_patch' => ['max_steps' => 'null'],
            ]);
            expect($result)->toBeArray()
                ->and($result)->toBe(['max_steps' => null]);
        });

        test('empty string is also coerced to null and clears', function (): void {
            $result = $this->validator->validateUpdateSchedulePatch([
                'schedule_patch' => ['cron_expression' => ''],
            ]);
            expect($result)->toBeArray()
                ->and($result)->toBe(['cron_expression' => null]);
        });

        test('genuinely-invalid string is still rejected (not "null" or "")', function (): void {
            $result = $this->validator->validateUpdateSchedulePatch([
                'schedule_patch' => ['cron_expression' => 'definitely not a cron'],
            ]);
            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('`cron_expression`');
        });
    });
});
