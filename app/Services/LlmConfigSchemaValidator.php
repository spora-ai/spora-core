<?php

declare(strict_types=1);

namespace Spora\Services;

use ReflectionClass;
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
     * @param array<string, mixed> $settings
     * @param list<array> $schema
     */
    public function validateSettings(array $settings, array $schema): ?string
    {
        foreach ($schema as $field) {
            $key = $field['key'];
            $required = $field['required'] ?? false;

            if ($required && (! array_key_exists($key, $settings) || $settings[$key] === '')) {
                return "Field '{$field['label']}' is required.";
            }

            if (array_key_exists($key, $settings) && $settings[$key] !== '') {
                $value = (string) $settings[$key];
                $validation = $field['validation'] ?? '';
                if ($validation !== '' && !preg_match($validation, $value)) {
                    return "Field '{$field['label']}' has an invalid value.";
                }
            }
        }

        return null;
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
