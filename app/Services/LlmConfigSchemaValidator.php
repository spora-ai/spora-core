<?php

declare(strict_types=1);

namespace Spora\Services;

use InvalidArgumentException;
use ReflectionClass;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\EffortAwareDriverInterface;
use Spora\Drivers\EffortLevelSet;
use Spora\Drivers\Exceptions\DriverClassNotFoundException;
use Spora\Drivers\LLMDriverInterface;
use Spora\Http\Exceptions\EffortValidationException;
use Spora\Tools\Attributes\ToolSetting;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Driver schema reflection + per-field validation for LLM configs.
 *
 * Extracted from {@see LlmConfigValidator} so the validator stays under
 * the SonarCloud 20-method-per-class ceiling (S1448). This class owns
 * the reflection-based `#[ToolSetting]` walker and the regex/key/value
 * coercion rules. Body-shape validation, data shaping, authorization,
 * and the JSON response helpers remain on {@see LlmConfigValidator}.
 *
 * The reasoning-effort value is checked against the driver the settings
 * describe, not against a static list: the validator and the runtime must
 * agree exactly on which levels a configuration offers, including for an
 * out-of-tree driver.
 */
final class LlmConfigSchemaValidator
{
    /**
     * Upper bound on `context_window` and `max_tokens_output`.
     *
     * The context window is bounded by the largest window a hosted API
     * publishes — Gemini 3 Pro and Grok 4.20 sit at two million, the
     * GPT-6 family at 1,050,000 and Gemini 4 Argon at 1,048,576. Above
     * that, the value is a typo rather than a window: the cap is internal
     * operator hygiene, not something the UI should advertise.
     *
     * The output budget is bounded separately and lower: it is the
     * per-request `max_output_tokens`, so a stray zero asks the provider
     * for a response an order of magnitude larger than any model allows
     * and the operator pays for the tokens.
     */
    private const MAX_CONTEXT_WINDOW = 2_000_000;
    private const MAX_OUTPUT_TOKENS = 1_000_000;

    public function __construct(
        private readonly ?DriverFactory $driverFactory = null,
    ) {}

    /**
     * Validate `context_window` and `max_tokens_output` if present.
     * Absent keys are treated as "leave unchanged" / "use default" so a
     * partial update payload can update one field without resetting the
     * other. A present-but-invalid value is rejected with a 422 naming the
     * field and the bound it broke.
     *
     * @param array<string, mixed> $body
     */
    public function validateLimits(array $body): ?JsonResponse
    {
        foreach (['context_window', 'max_tokens_output'] as $key) {
            if (!array_key_exists($key, $body)) {
                continue;
            }
            $value = $body[$key];
            $max = $key === 'context_window' ? self::MAX_CONTEXT_WINDOW : self::MAX_OUTPUT_TOKENS;
            if (!$this->isValidPositiveInteger($value, $max)) {
                return $this->errorResponse(
                    'VALIDATION_ERROR',
                    "{$key} must be a positive integer up to {$max}.",
                );
            }
        }

        return null;
    }

    /**
     * @return list<array>
     */
    public function getSchemaForDriver(string $driverClass): array
    {
        if (! class_exists($driverClass)) {
            return [];
        }

        $schema = [];
        foreach ((new ReflectionClass($driverClass))->getAttributes(ToolSetting::class) as $attr) {
            /** @var ToolSetting $setting */
            $setting = $attr->newInstance();
            $schema[] = [
                'key' => $setting->key,
                'label' => $setting->label,
                'type' => $setting->type,
                'description' => $setting->description,
                'default' => $setting->default,
                'required' => $setting->required,
                'options' => $setting->options,
                'validation' => $setting->validation,
            ];
        }

        return $schema;
    }

    /**
     * The reason the settings cannot be accepted, or null.
     *
     * The effort refusals are thrown rather than returned: the message
     * reaches the operator through the error envelope, and a message that
     * only ever existed as a return value is never harvested into the
     * catalogue — so it would stay English on every German install.
     *
     * @param array<string, mixed> $settings
     * @param list<array> $schema
     * @throws EffortValidationException when the effort the configuration names cannot be accepted
     */
    public function validateSettings(string $driverClass, array $settings, array $schema): ?string
    {
        foreach ($schema as $field) {
            $error = $this->validateField($field, $settings);

            if ($error !== null) {
                return $error;
            }
        }

        $this->validateEffortLevels($settings);

        $this->assertEffortIsOffered($this->driverFor($driverClass, $settings), $settings);

        return null;
    }

    /**
     * The reason one field cannot be accepted, or null.
     *
     * @param array<string, mixed> $field
     * @param array<string, mixed> $settings
     */
    private function validateField(array $field, array $settings): ?string
    {
        $key = $field['key'];
        $required = $field['required'] ?? false;

        if ($required && (! array_key_exists($key, $settings) || $settings[$key] === '')) {
            return "Field '{$field['label']}' is required.";
        }

        if (!array_key_exists($key, $settings) || $settings[$key] === '') {
            return null;
        }

        return $this->valueError($field, (string) $settings[$key]);
    }

    /**
     * The reason a value does not fit the field, or null.
     *
     * @param array<string, mixed> $field
     */
    private function valueError(array $field, string $value): ?string
    {
        $validation = $field['validation'] ?? '';

        if ($validation !== '' && !preg_match($validation, $value)) {
            return "Field '{$field['label']}' has an invalid value.";
        }

        // A select's option list is the only description of what it
        // accepts, so a value outside it is refused here rather than
        // reaching the provider. A free-text field has no such list —
        // what it accepts is decided by the configuration it belongs to,
        // which is checked separately.
        $options = $field['options'] ?? [];

        if (($field['type'] ?? '') === 'select' && $options !== [] && !array_key_exists($value, $options)) {
            return "Field '{$field['label']}' has an invalid value.";
        }

        return null;
    }

    /**
     * The reasoning-effort default the configuration cannot carry.
     *
     * The value is checked as it was given, not trimmed: a level name
     * cannot carry whitespace, and the value that is checked is the value
     * that is saved — a trimmed one would be a level the saved default is
     * not, and the saved default would silently lose to the model's own.
     *
     * @param array<string, mixed> $settings
     * @throws EffortValidationException when the level is not one the configuration offers
     */
    private function assertEffortIsOffered(?LLMDriverInterface $driver, array $settings): void
    {
        $effort = (string) ($settings['effort'] ?? '');

        if ($effort === '') {
            return;
        }

        $levels = $this->levelNames($driver, $settings);

        if ($levels === null || in_array($effort, $levels, true)) {
            return;
        }

        if ($levels === []) {
            throw new EffortValidationException(
                sprintf(
                    'effort "%s" is not offered by this LLM configuration: it accepts no reasoning-effort level.',
                    $effort,
                ),
                EffortValidationException::CODE_UNSUPPORTED,
            );
        }

        throw new EffortValidationException(
            sprintf(
                'effort "%s" is not offered by this LLM configuration. Supported levels: %s.',
                $effort,
                implode(', ', $levels),
            ),
            EffortValidationException::CODE_UNSUPPORTED,
        );
    }

    /**
     * The levels the configuration offers, or null when they are unknown.
     *
     * The driver's own set is authoritative — it already accounts for the
     * configured list. Without a driver to ask, the configured list is the
     * only thing known, and an unset list leaves the effort unchecked: the
     * container always wires a driver factory, so that path is the test
     * harness, not production.
     *
     * @param array<string, mixed> $settings
     * @return list<string>|null
     */
    private function levelNames(?LLMDriverInterface $driver, array $settings): ?array
    {
        if ($driver instanceof EffortAwareDriverInterface) {
            return EffortLevelSet::namesFromMap($driver->effortLevels());
        }

        $configured = trim((string) ($settings['effort_levels'] ?? ''));

        return $configured === '' ? null : EffortLevelSet::fromSetting($configured)->names();
    }

    /**
     * The level list the configuration cannot carry.
     *
     * @param array<string, mixed> $settings
     * @throws EffortValidationException when the level list is malformed
     */
    private function validateEffortLevels(array $settings): void
    {
        $raw = $settings['effort_levels'] ?? '';

        if (!is_string($raw)) {
            throw new EffortValidationException(
                'Field \'Effort levels\' has an invalid value.',
                EffortValidationException::CODE_MALFORMED,
            );
        }

        try {
            EffortLevelSet::fromSetting($raw);
        } catch (InvalidArgumentException $e) {
            throw new EffortValidationException(
                $e->getMessage(),
                EffortValidationException::CODE_MALFORMED,
            );
        }
    }

    /**
     * The driver the settings describe, or null when it cannot be built.
     *
     * @param array<string, mixed> $settings
     */
    private function driverFor(string $driverClass, array $settings): ?LLMDriverInterface
    {
        if ($this->driverFactory === null) {
            return null;
        }

        try {
            return $this->driverFactory->makeDriverFromSettings($driverClass, $settings);
        } catch (DriverClassNotFoundException | InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Strict positive-integer check. Rejects strings with trailing junk
     * (`"200000abc"`), floats (`1.5`), and any non-positive value. The
     * `$max` cap exists to bound the wire `max_tokens` request and keep
     * accidental DoS amplification in check.
     *
     * @param mixed $value
     */
    private function isValidPositiveInteger(mixed $value, int $max): bool
    {
        if (!is_numeric($value)) {
            return false;
        }
        // String round-trip rejects "200000abc" — `(int) "200000abc"` would
        // silently truncate to 200000 otherwise.
        if ((string) (int) $value !== (string) $value) {
            return false;
        }
        $intValue = (int) $value;
        return $intValue > 0 && $intValue <= $max;
    }

    private function errorResponse(string $code, string $message): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => $code, 'message' => $message]],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
