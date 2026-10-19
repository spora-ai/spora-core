<?php

declare(strict_types=1);

namespace Spora\Build;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Spora\Apps\AppInterface;
use Spora\Core\Paths;
use Spora\Settings\SettingDeclarations;
use Spora\Settings\SettingsRegistry;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\ToolSettingSchema;
use Throwable;

/**
 * Collects every operator-facing English literal in the framework, keyed by
 * the literal itself — `en.json` is that map, and `i18n:check` compares
 * every other catalogue against it.
 *
 * Harvest is by construction rather than by convention: a string is in the
 * catalogue because it came out of a place that renders to an operator, not
 * because someone remembered to mark it. That is also what makes the
 * never-translated list checkable — `#[ToolSetting].label` is absent here
 * because no code path below reads it, not because a flag says so.
 *
 * What is deliberately absent:
 *
 *  - `#[Tool].name` / `.description`, `#[ToolOperation].*`,
 *    `#[ToolParameter].description` — model input.
 *  - `#[ToolSetting].label` and its option labels — six labels enter the
 *    model's prompt, and the key *is* the English text, so a translated
 *    label would apply to every setting reusing the word.
 *  - `app/Console/**` — CLI output is permanently English.
 *  - Plugin-supplied strings. Each plugin harvests its own catalogue from
 *    its own repo (`PluginInterface::translationsPaths()`), so a plugin
 *    cannot block an unrelated core PR with a string nobody here can see.
 *
 * `file:line` is exact for the syntactic sources and best-effort for the
 * reflection ones, which is all the failure output needs.
 */
final class CatalogHarvester
{
    /**
     * Error-message positions this harvest recognises.
     *
     * `'message' => '…'` is the envelope; the four helper names build the
     * same envelope one frame up, which is where most of core's copy
     * actually lives. Harvesting only the literal envelope would leave the
     * majority of the operator's error text out of the catalogue and, since
     * `i18n:check` fails on any gap, permanently untranslatable.
     */
    private const ERROR_MESSAGE_HELPERS = ['error', 'notFound', 'forbidden', 'unprocessable'];

    /** The only setting whose option labels are language names rather than copy. */
    private const LANGUAGE_SETTING = 'SPORA_DEFAULT_LANG';

    /** @var array<string, list<string>> */
    private array $messages = [];

    public function __construct(
        private readonly string $basePath,
    ) {}

    /**
     * @return array<string, list<string>> message => `file:line` locators, in discovery order
     */
    public function harvest(): array
    {
        $this->messages = [];

        $this->harvestAttributes();
        $this->harvestDeclaredSettings();
        $this->harvestApps();
        $this->harvestPluginManifests();
        $this->harvestErrorMessages();

        return $this->messages;
    }

    /**
 * `en.json` as a flat identity map — key and value are the same English
 * string. That is what makes a catalogue miss render as the source text
 * with no separate fallback table, and it is why this file is generated
 * rather than translated.
 *
 * @param array<string, list<string>> $harvested
 * @return array<string, string>
 */
    public static function identityMap(array $harvested): array
    {
        return array_combine(array_keys($harvested), array_keys($harvested));
    }

    private function add(string $message, string $locator): void
    {
        if (trim($message) === '') {
            return;
        }

        $this->messages[$message][] = $locator;
    }

    /**
     * `#[Tool].displayName` / `.category` and `#[ToolSetting].description`,
     * read off every class in `app/` so a new tool is covered without
     * touching a list here.
     */
    private function harvestAttributes(): void
    {
        foreach ($this->classesIn($this->appDir()) as $class) {
            $reflection = new ReflectionClass($class);
            $absolute = $reflection->getFileName() ?: '';
            $relative = $this->relative($absolute);

            foreach ($reflection->getAttributes(Tool::class) as $attribute) {
                $tool = $attribute->newInstance();
                $this->add((string) $tool->displayName, $this->locate($absolute, (string) $tool->displayName, $relative));
                $this->add($tool->category, $this->locate($absolute, $tool->category, $relative));
            }

            foreach (ToolSettingSchema::collect($class) as $setting) {
                $this->add($setting->description, $this->locate($absolute, $setting->description, $relative));
            }
        }
    }

    /**
     * Every `SettingDefinition`'s operator copy, plus the section headings
     * the admin UI groups by. This surface is neither an attribute nor a
     * controller, which is exactly how it gets missed.
     */
    private function harvestDeclaredSettings(): void
    {
        $declarations = new SettingDeclarations(new Paths($this->basePath, $this->basePath));
        $locator = 'app/Settings/SettingDeclarations.php';

        // The admin UI groups by these, so they render as operator copy on
        // the same screen as the settings themselves.
        foreach (SettingsRegistry::SECTION_LABELS as $sectionLabel) {
            $this->add($sectionLabel, 'app/Settings/SettingsRegistry.php');
        }

        foreach ($declarations->build() as $definition) {
            $this->add($definition->label, $locator);
            $this->add($definition->description, $locator);

            // The language picker's options are endonyms: a user finds
            // their own language by reading it in that language, so
            // `Deutsch` must read `Deutsch` in the German catalogue too —
            // which rule 3 would otherwise reject as an untranslated
            // placeholder. Same rule as a `#[ToolSetting]` option label.
            if ($definition->key === self::LANGUAGE_SETTING) {
                continue;
            }

            foreach ($definition->options as $option) {
                $this->add($option['label'], $locator);
            }
        }
    }

    /**
     * Core's own apps. A plugin's apps are harvested by that plugin's own
     * extractor — same split as every other plugin-supplied string.
     */
    private function harvestApps(): void
    {
        $appsDir = $this->basePath . '/app/Apps';
        foreach ((array) glob($appsDir . '/*.php') as $file) {
            $class = 'Spora\\Apps\\' . basename((string) $file, '.php');
            if (!class_exists($class) || !is_subclass_of($class, AppInterface::class)) {
                continue;
            }

            try {
                $app = new $class();
            } catch (Throwable) {
                continue;
            }

            $locator = $this->relative((string) $file);
            $this->add($app->displayName(), $locator);
            $this->add($app->description(), $locator);
        }
    }

    /**
     * `plugin.json#description` for every installed plugin, so the plugins
     * screen is not the one surface that stays English on a German install.
     */
    private function harvestPluginManifests(): void
    {
        foreach ((array) glob($this->basePath . '/plugins/*/plugin.json') as $manifestPath) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            $description = is_array($manifest) ? ($manifest['description'] ?? null) : null;

            if (is_string($description)) {
                $this->add($description, $this->relative((string) $manifestPath));
            }
        }
    }

    /**
     * Every string literal that reaches an HTTP error envelope, found
     * syntactically so the locator is the real line rather than a
     * reconstructed one.
     */
    private function harvestErrorMessages(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->appDir(), FilesystemIterator::SKIP_DOTS),
        );

        $files = [$this->basePath . '/app/Core/Kernel.php'];
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            if (str_ends_with($path, '.php') && str_contains($path, '/Http/')) {
                $files[] = $path;
            }
        }

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            foreach ($this->errorMessageLiteralsIn((string) file_get_contents($file)) as $line => $message) {
                $this->add($message, $this->relative($file) . ':' . $line);
            }
        }
    }

    /**
     * @return array<int, string> line => message
     */
    private function errorMessageLiteralsIn(string $source): array
    {
        $tokens = token_get_all($source);
        $constants = $this->classConstants($tokens);
        $messageIndex = $this->messageParameterIndexes($tokens);
        $found = [];

        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                continue;
            }

            if ($this->startsHelperCall($tokens, $i)) {
                $argument = $this->stringArgument($tokens, $i, $messageIndex[$token[1]]);
                if ($argument !== null) {
                    $found[$argument[1]] = $argument[0];
                }
                continue;
            }

            if ($token[0] !== T_CONSTANT_ENCAPSED_STRING || trim($token[1], "'\"") !== 'message') {
                continue;
            }

            if (($tokens[$i + 1][0] ?? null) !== T_DOUBLE_ARROW) {
                continue;
            }

            $literal = $this->stringAfterArrow($tokens, $i + 2, $constants);
            if ($literal !== null) {
                $found[$token[2]] = $literal;
            }
        }

        return $found;
    }

    /**
     * Where each error helper's `$message` parameter sits in its own
     * signature.
     *
     * The position cannot be assumed. `JsonControllerHelpers::error()` is
     * `(code, message, status)`, but a controller is free to declare its
     * own — and core does, in all three shapes: `error(string $message)`,
     * `error(code, message, status)` and `error(status, code, message)`.
     * Reading the parameter *name* is what makes the rule work on all of
     * them; a controller that never declares one gets the trait's shape.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     * @return array<string, int>
     */
    private function messageParameterIndexes(array $tokens): array
    {
        $traitDefaults = ['error' => 1, 'notFound' => 1, 'forbidden' => 1, 'unprocessable' => 1];
        $indexes = [];

        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }

            $name = $this->nextSignificant($tokens, $i + 1);
            if (!is_array($name) || $name[0] !== T_STRING || !in_array($name[1], self::ERROR_MESSAGE_HELPERS, true)) {
                continue;
            }

            $position = array_search('$message', $this->parameterNames($tokens, $i), true);
            $indexes[$name[1]] = $position === false ? -1 : $position;
        }

        return array_merge($traitDefaults, $indexes);
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     * @return list<string>
     */
    private function parameterNames(array $tokens, int $functionIndex): array
    {
        $names = [];
        $depth = 0;
        $insignificant = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

        for ($i = $functionIndex + 1, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token === '(') {
                $depth++;
                continue;
            }
            if ($token === ')') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
                continue;
            }
            if ($depth !== 1 || !is_array($token) || in_array($token[0], $insignificant, true)) {
                continue;
            }
            if ($token[0] === T_VARIABLE) {
                $names[] = $token[1];
            }
        }

        return $names;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function nextSignificant(array $tokens, int $index): array|string|null
    {
        $insignificant = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

        for ($i = $index, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], $insignificant, true)) {
                continue;
            }
            return $token;
        }

        return null;
    }

    /**
     * The literal on the right of a `'message' =>`, whether it is written
     * inline or reached through a class constant in the same file.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     * @param array<string, string>                               $constants
     * @return ?array{0: string, 1: int} message, line
     */
    private function stringAfterArrow(array $tokens, int $index, array $constants): ?array
    {
        $value = $tokens[$index] ?? null;

        if (is_array($value) && $value[0] === T_CONSTANT_ENCAPSED_STRING) {
            return [$this->decode($value[1]), $value[2]];
        }

        // `self::ERR_X` / `JsonControllerHelpers::MSG_X`, resolved in the
        // file that declares it.
        if (is_array($value) && $value[0] === T_STRING && ($tokens[$index + 1][0] ?? null) === T_DOUBLE_COLON) {
            $name = $tokens[$index + 2] ?? null;
            if (is_array($name) && $name[0] === T_STRING && isset($constants[$name[1]])) {
                return [$constants[$name[1]], $value[2]];
            }
        }

        return null;
    }

    /**
     * Every `const NAME = 'literal';` in one file, by constant name. A
     * class constant resolved from another file is out of scope — the
     * extractor reads one file at a time on purpose.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     * @return array<string, string>
     */
    private function classConstants(array $tokens): array
    {
        $constants = [];

        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_CONST) {
                continue;
            }

            $name = null;
            $value = null;
            for ($j = $i + 1; $j < min($i + 8, $count); $j++) {
                $token = $tokens[$j];
                if (!is_array($token)) {
                    if ($token === ';') {
                        break;
                    }
                    continue;
                }
                if ($name === null && $token[0] === T_STRING) {
                    $name = $token[1];
                }
                if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $value = $this->decode($token[1]);
                    break;
                }
            }

            if ($name !== null && $value !== null) {
                $constants[$name] = $value;
            }
        }

        return $constants;
    }

    /**
     * True when token `$i` is the method name of `$this->error(` /
     * `->notFound(` / `->forbidden(` / `->unprocessable(`.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function startsHelperCall(array $tokens, int $index): bool
    {
        $token = $tokens[$index];
        if (!is_array($token) || $token[0] !== T_STRING || !in_array($token[1], self::ERROR_MESSAGE_HELPERS, true)) {
            return false;
        }
        if (($tokens[$index + 1] ?? null) !== '(') {
            return false;
        }

        $before = $this->precedingSignificant($tokens, $index, 2);
        if (count($before) < 2) {
            return false;
        }

        [$arrow, $receiver] = $before;

        return is_array($arrow)
            && $arrow[0] === T_OBJECT_OPERATOR
            && is_array($receiver)
            && $receiver[0] === T_VARIABLE
            && $receiver[1] === '$this';
    }

    /**
     * The `$count` tokens before `$index` that are not whitespace or
     * comments, nearest first. PHP puts a space on either side of `->`
     * whenever the author wrote one, so a fixed offset reads the wrong
     * token about half the time.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     * @return list<array{0: int, 1: string, 2: int}|string>
     */
    private function precedingSignificant(array $tokens, int $index, int $count): array
    {
        $significant = [];
        $insignificant = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

        for ($i = $index - 1; $i >= 0 && count($significant) < $count; $i--) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], $insignificant, true)) {
                continue;
            }
            $significant[] = $token;
        }

        return $significant;
    }

    /**
     * One argument of the helper call starting at `$index`, when it is a
     * bare string literal. `sprintf(...)` and concatenation land in the
     * catalogue through their *result*, not their format string, so they
     * are not harvestable here.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     * @return ?array{0: string, 1: int} message, line
     */
    private function stringArgument(array $tokens, int $index, int $position): ?array
    {
        if ($position < 0) {
            return null;
        }

        $depth = 0;
        $arguments = [];
        $current = [];
        $insignificant = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

        // Start past the opening paren so the commas separating this
        // call's own arguments sit at depth 0.
        for ($i = $index + 2, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token === '(' || $token === '[' || $token === '{') {
                $depth++;
            } elseif ($token === ')' && $depth === 0) {
                $arguments[] = $current;
                break;
            } elseif ($token === ')' || $token === ']' || $token === '}') {
                $depth--;
            } elseif ($token === ',' && $depth === 0) {
                $arguments[] = $current;
                $current = [];
                continue;
            }

            if (is_array($token) && in_array($token[0], $insignificant, true)) {
                continue;
            }
            $current[] = $token;
        }

        $argument = $arguments[$position] ?? null;
        if ($argument === null || count($argument) !== 1) {
            return null;
        }

        $literal = $argument[0];
        if (!is_array($literal) || $literal[0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        return [$this->decode($literal[1]), $literal[2]];
    }

    private function decode(string $literal): string
    {
        $quote = $literal[0] ?? "'";
        $inner = substr($literal, 1, -1);

        // Single-quoted PHP escapes only `\'` and `\\`; everything else is
        // literal, and un-escaping them would corrupt copy that genuinely
        // contains a backslash (a Windows path in a hint, a regex).
        if ($quote === "'") {
            return str_replace(['\\\\', "\\'"], ['\\', "'"], $inner);
        }

        return (string) stripcslashes($inner);
    }

    /**
     * Every class the `Spora\` PSR-4 mapping can reach under a directory,
     * loaded so the attributes on it are readable.
     *
     * @return list<class-string>
     */
    private function classesIn(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $classes = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            if (!$entry->isFile() || !str_ends_with($path, '.php')) {
                continue;
            }

            $relative = substr($path, strlen($this->appDir()) + 1, -4);
            $class = 'Spora\\' . str_replace('/', '\\', $relative);
            if (class_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * The line a harvested string is written on, for the sources reflection
     * read but the token pass did not already locate. Returns the file
     * alone when the string was built by concatenation rather than written
     * as one literal — better than a line number that points at a
     * neighbour.
     */
    private function locate(string $absolute, string $message, string $relative): string
    {
        if ($absolute === '' || !is_file($absolute)) {
            return $relative;
        }

        foreach (token_get_all((string) file_get_contents($absolute)) as $token) {
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING && $this->decode($token[1]) === $message) {
                return $relative . ':' . $token[2];
            }
        }

        return $relative;
    }

    private function appDir(): string
    {
        return $this->basePath . '/app';
    }

    private function relative(string $path): string
    {
        return str_starts_with($path, $this->basePath . '/') ? substr($path, strlen($this->basePath) + 1) : $path;
    }
}
