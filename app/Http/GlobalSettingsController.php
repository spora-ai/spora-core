<?php

declare(strict_types=1);

namespace Spora\Http;

use OpenApi\Attributes as OA;
use Spora\Auth\AuthService;
use Spora\Core\Config;
use Spora\I18n\CatalogLoader;
use Spora\I18n\Translator;
use Spora\Settings\SettingDefinition;
use Spora\Settings\SettingsRegistry;
use Spora\Settings\SettingsRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin-editable settings surface.
 *
 * Everything the schema says comes from {@see SettingsRegistry}: a key that
 * is not declared there is not a setting, and this controller rejects it.
 * That is the whole point of the registry — it cannot drift from the form,
 * because it *is* the form.
 */
final class GlobalSettingsController
{
    use JsonControllerHelpers;

    /** The mask. Submitted back unchanged, it means "leave this alone". */
    private const MASK = '***';

    private readonly Translator $translator;

    public function __construct(
        private readonly SettingsRegistry $registry,
        private readonly SettingsRepository $repository,
        private readonly Config $config,
        private readonly AuthService $authService,
        ?Translator $translator = null,
    ) {
        $this->translator = $translator ?? new Translator(new CatalogLoader());
    }

    #[OA\Get(
        path: '/api/v1/admin/settings',
        summary: 'Every declared setting, its current value and where that value came from',
        responses: [
            new OA\Response(response: 200, description: 'Settings grouped by section'),
        ],
    )]
    public function index(): JsonResponse
    {
        $undecryptable = $this->config->undecryptableKeys();

        $bySection = [];
        foreach ($this->registry->all() as $definition) {
            $bySection[$definition->section][] = $this->present($definition, $undecryptable);
        }

        $sections = [];
        foreach ($this->registry->sectionLabels() as $key => $label) {
            if (isset($bySection[$key])) {
                $sections[] = ['key' => $key, 'label' => $this->translator->trans($label), 'settings' => $bySection[$key]];
            }
        }

        return new JsonResponse([
            'data' => ['sections' => $sections, 'undecryptable' => $undecryptable],
        ], Response::HTTP_OK);
    }

    #[OA\Patch(
        path: '/api/v1/admin/settings',
        summary: 'Upsert settings',
        responses: [
            new OA\Response(response: 200, description: 'Keys written'),
            new OA\Response(response: 422, description: 'Unknown key, boot-only key, or failed validation'),
        ],
    )]
    public function update(Request $request): JsonResponse
    {
        $incoming = $this->incomingEntries($request);
        if ($incoming instanceof JsonResponse) {
            return $incoming;
        }

        $userId = $this->authService->currentUserId();

        $accepted = $this->admitAll($incoming);
        if ($accepted instanceof JsonResponse) {
            return $accepted;
        }

        $written = [];
        foreach ($accepted as [$definition, $value]) {
            $this->repository->put($definition, $value, $userId);
            $written[] = $definition->key;
        }

        return new JsonResponse(['data' => ['updated' => $written]], Response::HTTP_OK);
    }

    /**
     * @return array<mixed>|JsonResponse
     */
    private function incomingEntries(Request $request): array|JsonResponse
    {
        $body = $this->safeDecodeJson($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $incoming = $body['settings'] ?? null;
        if (!is_array($incoming)) {
            return $this->error('VALIDATION_ERROR', 'Expected a `settings` array.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $incoming;
    }

    /**
     * Validate the whole batch before anything is written.
     *
     * The order is load-bearing: a batch refused half-way through leaves the
     * operator unable to say what was saved. Validating and writing in one
     * pass persisted everything before the failing entry and then answered
     * 422 saying nothing about those rows.
     *
     * @param array<mixed> $incoming
     * @return list<array{0: SettingDefinition, 1: mixed}>|JsonResponse
     */
    private function admitAll(array $incoming): array|JsonResponse
    {
        $accepted = [];

        foreach ($incoming as $entry) {
            $admitted = $this->admit($entry);
            if ($admitted instanceof JsonResponse) {
                return $admitted;
            }

            // A secret the operator did not touch comes back as the mask.
            // Writing that back would overwrite the real value with '***'.
            [$definition, $value] = $admitted;
            if ($definition === null) {
                continue;
            }

            $accepted[] = [$definition, $value];
        }

        return $accepted;
    }

    #[OA\Delete(
        path: '/api/v1/admin/settings/{group}/{key}',
        summary: 'Reset one setting to its declared default',
        responses: [
            new OA\Response(response: 200, description: 'Key reset'),
            new OA\Response(response: 422, description: 'Unknown or boot-only key'),
        ],
    )]
    public function reset(string $group, string $key): JsonResponse
    {
        $definition = $this->registry->get($key);
        if ($definition === null || $definition->group !== $group) {
            return $this->error('VALIDATION_ERROR', sprintf('Unknown setting "%s".', $key), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($definition->bootOnly) {
            return $this->error('VALIDATION_ERROR', sprintf('"%s" is set in .env or config.php and cannot be edited here.', $key), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->repository->delete($definition->group, $definition->key);

        return new JsonResponse(['data' => ['reset' => [$key]]], Response::HTTP_OK);
    }

    /**
     * Decide whether one batch entry may be written.
     *
     * `null` for the definition means "skip": an untouched secret, whose mask
     * came back as its value.
     *
     * @param mixed $entry
     * @return array{0: ?SettingDefinition, 1: mixed}|JsonResponse
     */
    private function admit(mixed $entry): array|JsonResponse
    {
        $definition = $this->lookUp($entry);
        if ($definition instanceof JsonResponse) {
            return $definition;
        }

        $value = $entry['value'] ?? null;
        if ($definition->secret && $value === self::MASK) {
            return [null, null];
        }

        return $this->rejectInvalid($definition, $value) ?? [$definition, $value];
    }

    /**
     * Resolve one batch entry to the setting it names, or say why it cannot.
     *
     * @return SettingDefinition|JsonResponse
     */
    private function lookUp(mixed $entry): SettingDefinition|JsonResponse
    {
        $key = is_array($entry) && isset($entry['key']) ? (string) $entry['key'] : null;
        if ($key === null) {
            return $this->error('VALIDATION_ERROR', 'Each entry needs a `key`.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $definition = $this->registry->get($key);
        if ($definition === null) {
            return $this->error('VALIDATION_ERROR', sprintf('Unknown setting "%s".', $key), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // A DB-backed `db_host` would lock the operator out on the very
        // request that wrote it. These are read before the database this
        // row lives in is open.
        return $definition->bootOnly
            ? $this->error('VALIDATION_ERROR', sprintf('"%s" is set in .env or config.php and cannot be edited here.', $key), Response::HTTP_UNPROCESSABLE_ENTITY)
            : $definition;
    }

    /**
     * @param list<string> $undecryptable
     * @return array<string, mixed>
     */
    private function present(SettingDefinition $definition, array $undecryptable): array
    {
        $isUndecryptable = in_array($definition->key, $undecryptable, true);

        // An unreadable secret falls back to its declared default, so the page
        // shows a usable value and says plainly that it needs re-entering.
        $value = $definition->secret ? self::MASK : $this->config->get($definition->key, $definition->default);

        return [
            'key'           => $definition->key,
            // The storage discriminator, NOT the section. They differ — every
            // setting except mail's lives in `framework` while spanning
            // several sections — and `DELETE /{group}/{key}` is addressed by
            // this one.
            'group'         => $definition->group,
            'label'         => $this->translator->trans($definition->label),
            'type'          => $definition->type,
            'description'   => $this->translator->trans($definition->description),
            'value'         => $value,
            'default'       => $definition->default,
            'source'        => $this->config->sourceOf($definition->key),
            'readonly'      => $definition->bootOnly,
            'secret'        => $definition->secret,
            'undecryptable' => $isUndecryptable,
            'options'       => $this->localiseOptions($definition),
        ];
    }

    /**
     * A declared setting's option labels are operator copy, unlike a
     * `#[ToolSetting]` option label — this surface never reaches a model.
     * The stored `value` is left alone so a translated label cannot change
     * what gets written.
     *
     * @return list<array{value: string, label: string}>
     */
    private function localiseOptions(SettingDefinition $definition): array
    {
        $options = [];
        foreach ($definition->options as $option) {
            $option['label'] = $this->translator->trans($option['label']);
            $options[] = $option;
        }

        return $options;
    }

    private function rejectInvalid(SettingDefinition $definition, mixed $value): ?JsonResponse
    {
        $reason = $this->rejectionReason($definition, $value);

        return $reason === null ? null : $this->error('VALIDATION_ERROR', $reason, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * Why this value cannot be stored, or `null` when it can.
     *
     * Separate from {@see self::rejectInvalid()} so this reads as a list of
     * reasons rather than a list of exits.
     */
    private function rejectionReason(SettingDefinition $definition, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // An array where a single value belongs. `coerce()` would cast it with
        // `(string)`, which emits "Array to string conversion" and stores the
        // literal string "Array".
        $isList = in_array($definition->type, [SettingDefinition::TYPE_MULTI_SELECT, SettingDefinition::TYPE_LIST], true);
        if ($isList !== is_array($value)) {
            return $isList
                ? sprintf('"%s" takes a list of values.', $definition->key)
                : sprintf('"%s" takes a single value.', $definition->key);
        }

        return $this->singleValueRejection($definition, $value, $isList);
    }

    /**
     * The two rules that can only be applied to a single scalar value.
     *
     * @param bool $isList Whether the declared type is a list.
     */
    private function singleValueRejection(SettingDefinition $definition, mixed $value, bool $isList): ?string
    {
        if (!$isList && $definition->validation !== '' && is_scalar($value)
            && preg_match($definition->validation, (string) $value) !== 1) {
            return sprintf('"%s" does not accept that value.', $definition->key);
        }

        // The declared option set, checked against the *coerced* value so a
        // legacy spelling (`php_mail` → `sendmail`) is accepted and stored
        // canonically. Without this the API accepted anything: writing
        // `nonsense` to SPORA_MAIL_DRIVER returned 200 and then made every
        // send throw.
        $allowed = array_column($definition->options, 'value');
        if ($allowed !== [] && !$isList && is_scalar($value)
            && !in_array($this->registry->coerce($definition, $value), $allowed, true)) {
            return sprintf('"%s" must be one of: %s.', $definition->key, implode(', ', $allowed));
        }

        return null;
    }
}
