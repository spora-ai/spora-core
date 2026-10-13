<?php

declare(strict_types=1);

namespace Spora\Http;

use OpenApi\Attributes as OA;
use Spora\Auth\AuthService;
use Spora\Core\Config;
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

    public function __construct(
        private readonly SettingsRegistry $registry,
        private readonly SettingsRepository $repository,
        private readonly Config $config,
        private readonly AuthService $authService,
    ) {}

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
                $sections[] = ['key' => $key, 'label' => $label, 'settings' => $bySection[$key]];
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
        $body = $this->safeDecodeJson($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $incoming = $body['settings'] ?? null;
        if (!is_array($incoming)) {
            return $this->error('VALIDATION_ERROR', 'Expected a `settings` array.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $userId = $this->authService->currentUserId();
        // Two phases, and the order is load-bearing: a batch refused half-way
        // through leaves the operator unable to say what was saved. Validating
        // and writing in one pass persisted everything before the failing
        // entry and then answered 422 saying nothing about those rows.
        $accepted = [];

        foreach ($incoming as $entry) {
            if (!is_array($entry) || !isset($entry['key'])) {
                return $this->error('VALIDATION_ERROR', 'Each entry needs a `key`.', Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $key = (string) $entry['key'];
            $definition = $this->registry->get($key);
            if ($definition === null) {
                return $this->error('VALIDATION_ERROR', sprintf('Unknown setting "%s".', $key), Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            if ($definition->bootOnly) {
                // A DB-backed `db_host` would lock the operator out on the very
                // request that wrote it. These are read before the database
                // this row lives in is open.
                return $this->error('VALIDATION_ERROR', sprintf('"%s" is set in .env or config.php and cannot be edited here.', $key), Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $value = $entry['value'] ?? null;

            // A secret the operator did not touch comes back as the mask.
            // Writing that back would overwrite the real value with '***'.
            if ($definition->secret && $value === self::MASK) {
                continue;
            }

            $invalid = $this->rejectInvalid($definition, $value);
            if ($invalid !== null) {
                return $invalid;
            }

            $accepted[] = [$definition, $value];
        }

        $written = [];
        foreach ($accepted as [$definition, $value]) {
            $this->repository->put($definition, $value, $userId);
            $written[] = $definition->key;
        }

        return new JsonResponse(['data' => ['updated' => $written]], Response::HTTP_OK);
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
            'label'         => $definition->label,
            'type'          => $definition->type,
            'description'   => $definition->description,
            'value'         => $value,
            'default'       => $definition->default,
            'source'        => $this->config->sourceOf($definition->key),
            'readonly'      => $definition->bootOnly,
            'secret'        => $definition->secret,
            'undecryptable' => $isUndecryptable,
            'applies_on'    => $definition->workerRestart ? 'worker_restart' : 'request',
            'options'       => $definition->options,
        ];
    }

    private function rejectInvalid(SettingDefinition $definition, mixed $value): ?JsonResponse
    {
        if ($value === null) {
            return null;
        }

        // An array where a single value belongs. `coerce()` would cast it with
        // `(string)`, which emits "Array to string conversion" and stores the
        // literal string "Array".
        $isList = in_array($definition->type, [SettingDefinition::TYPE_MULTI_SELECT, SettingDefinition::TYPE_LIST], true);
        if ($isList !== is_array($value)) {
            return $this->error(
                'VALIDATION_ERROR',
                $isList
                    ? sprintf('"%s" takes a list of values.', $definition->key)
                    : sprintf('"%s" takes a single value.', $definition->key),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if (!$isList && $definition->validation !== '' && is_scalar($value)) {
            if (preg_match($definition->validation, (string) $value) !== 1) {
                return $this->error(
                    'VALIDATION_ERROR',
                    sprintf('"%s" does not accept that value.', $definition->key),
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }
        }

        // The declared option set, checked against the *coerced* value so a
        // legacy spelling (`php_mail` → `sendmail`) is accepted and stored
        // canonically. Without this the API accepted anything: writing
        // `nonsense` to SPORA_MAIL_DRIVER returned 200 and then made every
        // send throw.
        $allowed = array_column($definition->options, 'value');
        if ($allowed !== [] && !$isList && is_scalar($value)) {
            if (!in_array($this->registry->coerce($definition, $value), $allowed, true)) {
                return $this->error(
                    'VALIDATION_ERROR',
                    sprintf('"%s" must be one of: %s.', $definition->key, implode(', ', $allowed)),
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }
        }

        return null;
    }
}
