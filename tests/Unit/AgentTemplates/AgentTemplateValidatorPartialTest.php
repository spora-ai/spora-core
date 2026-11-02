<?php

declare(strict_types=1);

namespace Tests\Unit\AgentTemplates;

use Spora\AgentTemplates\AgentTemplateValidator;

/**
 * The partial validation mode the scoped update endpoint uses.
 *
 * A create names everything a template must carry; an update names only
 * what it changes. The two are checked by the same rules, apart from the
 * "this field is required" ones — so a field that *is* present is still
 * checked in full.
 */
describe('AgentTemplateValidator::validatePartial', function (): void {
    it('accepts a payload that names only the agent block', function (): void {
        $result = (new AgentTemplateValidator())->validatePartial([
            'agent' => ['system_prompt' => 'Be helpful.'],
        ]);

        expect($result->isValid())->toBeTrue();
    });

    it('refuses an empty payload', function (): void {
        $result = (new AgentTemplateValidator())->validatePartial([]);

        expect($result->isValid())->toBeFalse();
        expect($result->errors()[0]['code'])->toBe('EMPTY_PAYLOAD');
    });

    it('refuses an agent block that is not an object', function (): void {
        $result = (new AgentTemplateValidator())->validatePartial(['agent' => 'nope']);

        expect($result->isValid())->toBeFalse();
        expect($result->errors()[0]['code'])->toBe('AGENT_INVALID');
    });

    it('refuses an unknown top-level key', function (): void {
        $result = (new AgentTemplateValidator())->validatePartial([
            'agent' => ['system_prompt' => 'Be helpful.'],
            'nonsense' => true,
        ]);

        expect($result->isValid())->toBeFalse();
        expect($result->errors()[0]['code'])->toBe('UNKNOWN_TOP_LEVEL_KEY');
    });

    it('checks a field it does find', function (): void {
        // The absence of `name` is not an error in a partial payload, but a
        // `name` that is there and empty still is.
        $result = (new AgentTemplateValidator())->validatePartial([
            'name' => '',
            'agent' => ['system_prompt' => 'Be helpful.'],
        ]);

        expect($result->isValid())->toBeFalse();
        expect($result->errors()[0]['code'])->toBe('NAME_INVALID');
    });
});
