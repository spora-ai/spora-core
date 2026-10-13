<?php

declare(strict_types=1);

namespace Spora\Settings;

/**
 * One operator-facing setting, declared exactly once.
 *
 * This is simultaneously the default, the UI field, the API schema and the
 * validation rule. That is the point: the old arrangement needed an option
 * registered in `ContainerDefinitions::collectEnvOverrides()`, a default in
 * `$defaults`, and a matching line in the operator's `.env.example`, and
 * nothing checked that the three agreed — which is how a numeric setting's
 * type lived only in an `(int)` cast inside `collectEnvOverrides()`, invisible
 * to the UI and to the API.
 *
 * A key that is not declared here is not a setting: the admin API rejects it
 * and nothing resolves it from the store.
 */
final readonly class SettingDefinition
{
    public const TYPE_TOGGLE = 'toggle';
    public const TYPE_TEXT = 'text';
    public const TYPE_NUMBER = 'number';
    public const TYPE_SELECT = 'select';
    public const TYPE_PASSWORD = 'password';
    public const TYPE_MULTI_SELECT = 'multi-select';
    public const TYPE_TEXTAREA = 'textarea';
    public const TYPE_LIST = 'list';

    /**
     * @param string     $key          `SPORA_*`. The same string in `.env`, `config.php`, the API and the DB.
     * @param string     $label        Shown in the UI. Operator-facing prose, not the key.
     * @param string     $type         One of the `TYPE_*` constants. Drives coercion and the UI field.
     * @param mixed      $default      Used when no layer sets a value. Must survive a round-trip through `encode()`.
     * @param string     $group        `settings.group_key`. Part of the unique key with `$key`.
     * @param string     $section      UI grouping. Independent of `$group`, which is a storage discriminator.
     * @param string     $description  Why an operator would change this. Not a restatement of the label.
     * @param bool       $secret       Stored encrypted, never sent over the wire. The API returns `'***'`.
     * @param bool       $bootOnly     Needed before, or to open, the database. Never stored in it.
     * @param bool       $workerRestart Read once before the worker loop, so a change needs a worker restart.
     * @param list<array{value: string, label: string}> $options For `select`.
     * @param string     $validation   Regex the raw string must match, or '' for none.
     * @param string     $transform    Named coercion for a value the raw-string form cannot express.
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $type,
        public mixed $default = null,
        public string $group = 'framework',
        public string $section = 'general',
        public string $description = '',
        public bool $secret = false,
        public bool $bootOnly = false,
        public bool $workerRestart = false,
        public array $options = [],
        public string $validation = '',
        public string $transform = '',
    ) {}
}
