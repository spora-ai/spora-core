<?php

declare(strict_types=1);

namespace Spora\Core;

use ReflectionClass;

/**
 * Centralised path resolution for the consumer's project.
 *
 * Two roots:
 * - basePath: the consumer's project root (e.g. /home/operator/myapp)
 * - frameworkPath: the framework's own install dir (vendor/spora-ai/spora-core)
 *
 * Env-var overrides per category (SPORA_STORAGE_DIR, SPORA_PLUGINS_DIR, etc.)
 * let operators relocate subdirectories without code changes.
 *
 * Tests instantiate directly with explicit paths.
 */
final class Paths
{
    public function __construct(
        private readonly string $basePath,
        private readonly ?string $frameworkPath = null,
    ) {
    }

    public function base(string $sub = ''): string
    {
        return $this->join($this->basePath, $sub);
    }

    public function storage(string $sub = ''): string
    {
        return $this->join($this->overrideOrDefault('SPORA_STORAGE_DIR', 'storage'), $sub);
    }

    public function database(string $sub = ''): string
    {
        return $this->join($this->basePath, 'database', $sub);
    }

    public function plugins(string $sub = ''): string
    {
        return $this->join($this->overrideOrDefault('SPORA_PLUGINS_DIR', 'plugins'), $sub);
    }

    public function recipes(string $sub = ''): string
    {
        return $this->join($this->overrideOrDefault('SPORA_RECIPES_DIR', 'recipes'), $sub);
    }

    public function config(string $sub = ''): string
    {
        return $this->join($this->overrideOrDefault('SPORA_CONFIG_FILE', 'config.php'), $sub);
    }

    public function env(): string
    {
        return $this->overrideOrDefault('SPORA_ENV_FILE', '.env');
    }

    /**
     * Path to the framework's own install directory (vendor/spora-ai/spora-core/).
     * Computed via reflection on a known framework class — safe because
     * the framework owns the class and the autoloader definitively resolves it.
     */
    public function framework(string $sub = ''): string
    {
        if ($this->frameworkPath !== null) {
            return $this->join($this->frameworkPath, $sub);
        }
        $reflector = new ReflectionClass(Kernel::class);
        // app/Core/Kernel.php → up 3 = the framework install root.
        $base = dirname($reflector->getFileName(), 3);
        return $this->join($base, $sub);
    }

    /**
     * Email templates: project overrides win over framework defaults.
     * Returns the list of directories to search, in priority order (highest first).
     *
     * @return list<string>
     */
    public function emailTemplatesPaths(): array
    {
        $project = $this->base('email-templates');
        $framework = $this->framework('email-templates');
        $paths = is_dir($project) ? [$project] : [];
        $paths[] = $framework;
        return $paths;
    }

    private function overrideOrDefault(string $envName, string $defaultSub): string
    {
        // Check $_SERVER/$_ENV first (already populated by phpdotenv/symfony Dotenv),
        // then fall back to getenv() so env vars set at the process level (e.g.
        // CLI/service environments where variables_order excludes 'E') are still seen.
        $value = $_SERVER[$envName] ?? $_ENV[$envName] ?? null;
        if ($value === null || $value === '') {
            $fromEnv = getenv($envName);
            $value = ($fromEnv === false || $fromEnv === '') ? null : $fromEnv;
        }
        if ($value !== null) {
            return $value;
        }
        return $this->base($defaultSub);
    }

    private function join(string $base, string ...$parts): string
    {
        $segments = array_filter([$base, ...$parts], static fn (string $s): bool => $s !== '');
        $first = array_shift($segments);
        // Preserve the first segment's leading/trailing slashes (e.g. absolute paths).
        $first = rtrim($first, '/');
        $rest  = array_map(static fn (string $s): string => trim($s, '/'), $segments);
        $parts = array_filter([$first, ...$rest], static fn (string $s): bool => $s !== '');
        return implode('/', $parts);
    }
}
