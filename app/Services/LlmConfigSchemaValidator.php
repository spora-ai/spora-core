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
     * Upper bound on `context_window` and `max_tokens_output`. Covers every
     * known provider ceiling with headroom (Anthropic 200k, OpenAI o-series
     * 100k). Above this, we reject with a generic 422 — the cap is internal
     * operator hygiene, not something the UI should advertise.
     */
    private const MAX_CONTEXT_WINDOW = 1_000_000;
    private const MAX_OUTPUT_TOKENS = 1_000_000;

    public function __construct(
        private readonly ?DriverFactory $driverFactory = null,
    ) {}

    /**
     * Validate `context_window` and `max_tokens_output` if present.
     * Absent keys are treated as "leave unchanged" / "use default" so a
     * partial update payload can update one field without resetting the
     * other. A present-but-invalid value is rejected with a generic 422.
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
                    "Value must be a positive integer up to {$max}.",
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
     * @param array<string, mixed> $settings
     * @param list<array> $schema
     */
    public function validateSettings(string $driverClass, array $settings, array $schema): ?string
    {
        foreach ($schema as $field) {
            $error = $this->validateField($field, $settings);

            if ($error !== null) {
                return $error;
            }
        }

        $levelsError = $this->validateEffortLevels($settings);

        if ($levelsError !== null) {
            return $levelsError;
        }

        return $this->effortMembershipError($this->driverFor($driverClass, $settings), $settings);
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
     * The reason the reasoning-effort default cannot be accepted, or null.
     *
     * The value is checked as it was given, not trimmed: a level name
     * cannot carry whitespace, and the value that is checked is the value
     * that is saved — a trimmed one would be a level the saved default is
     * not, and the saved default would silently lose to the model's own.
     *
     * @param array<string, mixed> $settings
     */
    private function effortMembershipError(?LLMDriverInterface $driver, array $settings): ?string
    {
        $effort = (string) ($settings['effort'] ?? '');

        if ($effort === '') {
            return null;
        }

        $levels = $this->levelNames($driver, $settings);

        if ($levels === null || in_array($effort, $levels, true)) {
            return null;
        }

        return $this->unsupportedEffortMessage($effort, $levels);
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
     * The reason the level list cannot be accepted, or null.
     *
     * @param array<string, mixed> $settings
     */
    private function validateEffortLevels(array $settings): ?string
    {
        $raw = $settings['effort_levels'] ?? '';

        if (!is_string($raw)) {
            return "Field 'Effort levels' has an invalid value.";
        }

        try {
            EffortLevelSet::fromSetting($raw);
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * @param list<string> $levels
     */
    private function unsupportedEffortMessage(string $effort, array $levels): string
    {
        if ($levels === []) {
            return sprintf(
                'effort "%s" is not offered by this LLM configuration: it accepts no reasoning-effort level.',
                $effort,
            );
        }

        return sprintf(
            'effort "%s" is not offered by this LLM configuration. Supported levels: %s.',
            $effort,
            implode(', ', $levels),
        );
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
