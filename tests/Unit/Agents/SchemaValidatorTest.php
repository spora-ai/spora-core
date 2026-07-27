<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Spora\Agents\SchemaValidator;
use Spora\Tools\Schema\ToolParameterSchemaBuilder;

/**
 * Regression coverage for {@see SchemaValidator}'s per-op `required[]` narrowing.
 *
 * Before this fix the runtime validator consumed the unfiltered schema's
 * `required[]` and rejected every multi-op call that left a per-op-only param
 * unset — most visibly `time(action: "now")` was rejected for missing `epoch`.
 * The LLM-facing schema was already correct (filtered via
 * {@see OperationSchemaFilter::filter()}) but the runtime path was not.
 */
final class SchemaValidatorTest extends TestCase
{
    public function test_no_operation_uses_global_required_unchanged(): void
    {
        $schema = $this->makeSchema(
            required: ['action', 'name'],
        );

        SchemaValidator::validate(['action' => 'read', 'name' => 'foo'], $schema);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Required argument 'name' is missing");
        SchemaValidator::validate(['action' => 'read'], $schema);
    }

    public function test_required_against_supplied_operation_is_enforced(): void
    {
        $schema = $this->makeSchema(
            required: ['action', 'epoch'],
            requiredWhen: ['epoch' => ['format']],
        );

        // `now` op + no `epoch` in args → validator narrows required to
        // `['action']` only and accepts the call. epoch is bound to format
        // only, so dropping it here is correct.
        SchemaValidator::validate(['action' => 'now'], $schema, 'now');

        // `format` op + no `epoch` → epoch is required for format, throws.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Required argument 'epoch' is missing");

        SchemaValidator::validate(['action' => 'format'], $schema, 'format');
    }

    public function test_per_op_required_drops_when_op_not_in_binding(): void
    {
        $schema = $this->makeSchema(
            required: ['action', 'epoch'],
            requiredWhen: ['epoch' => ['format']],
        );

        // `now` op — epoch is bound to `format` only, so it must NOT be required.
        // Reaching this line means the validator did not throw; PHPUnit's
        // "no assertions" rule is not enforced for class-based TestCases.
        SchemaValidator::validate(['action' => 'now'], $schema, 'now');
        $this->addToAssertionCount(1);
    }

    public function test_per_op_required_keeps_globally_required_param(): void
    {
        $schema = $this->makeSchema(
            required: ['action', 'name', 'epoch'],
            requiredWhen: ['epoch' => ['format']],
        );

        // `now` op drops `epoch` but `name` stays required.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Required argument 'name' is missing");

        SchemaValidator::validate(['action' => 'now'], $schema, 'now');
    }

    public function test_required_false_is_never_required(): void
    {
        $schema = $this->makeSchema(
            required: ['action'],
            properties: [
                'action'   => ['type' => 'string'],
                'optional' => ['type' => 'string'],
            ],
        );

        // `optional` is in properties, not in required — should pass without
        // the LLM needing to provide it.
        SchemaValidator::validate(['action' => 'now'], $schema, 'now');
        $this->addToAssertionCount(1);
    }

    public function test_type_mismatch_still_throws_after_narrowing(): void
    {
        $schema = $this->makeSchema(
            required: ['action', 'epoch'],
            requiredWhen: ['epoch' => ['format']],
            properties: [
                'action' => ['type' => 'string'],
                'epoch'  => ['type' => 'integer'],
            ],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("expects JSON Schema type 'integer'");

        SchemaValidator::validate(
            ['action' => 'format', 'epoch' => 'not-an-int'],
            $schema,
            'format',
        );
    }

    public function test_unknown_operation_name_falls_through_safely(): void
    {
        $schema = $this->makeSchema(
            required: ['action', 'epoch'],
            requiredWhen: ['epoch' => ['format']],
        );

        // `whatever` is not in any binding — narrowing has no entries where
        // the op matches, so `epoch` is dropped; the call passes (no per-op
        // params required). The unknown-op behaviour itself is enforced by
        // the tool's own execute() — the validator just narrows required[].
        SchemaValidator::validate(
            ['action' => 'whatever'],
            $schema,
            'whatever',
        );
        $this->addToAssertionCount(1);
    }

    /**
     * @param  list<string>                                            $required
     * @param  array<string, list<string>>                             $requiredWhen
     * @param  array<string, array{type?: string, enum?: list<string>}> $properties
     * @return array<string, mixed>
     */
    private function makeSchema(
        array $required,
        array $requiredWhen = [],
        array $properties = [
            'action' => ['type' => 'string', 'enum' => ['now', 'format']],
            'epoch'  => ['type' => 'integer'],
        ],
    ): array {
        $schema = [
            'type'       => 'object',
            'properties' => $properties,
            'required'   => $required,
        ];
        if ($requiredWhen !== []) {
            $schema[ToolParameterSchemaBuilder::REQUIRED_WHEN_KEY] = $requiredWhen;
        }
        return $schema;
    }
}
