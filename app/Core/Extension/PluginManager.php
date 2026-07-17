<?php

declare(strict_types=1);

namespace Spora\Core\Extension;

use Closure;
use Psr\Log\LoggerInterface;
use Spora\Core\Extension\Exceptions\PluginInstallFailedException;
use Spora\Core\Paths;

/**
 * Manages plugin install / uninstall / list / update by shelling out to Composer.
 *
 * Security: every command is built as an argv array — never a string — and
 * handed to the processFactory closure, which wraps {@see \Symfony\Component\Process\Process}.
 * Process spawns the child without an intervening shell, so a package name
 * from a CLI flag or HTTP body is literal data, never shell metacharacters.
 *
 * CWD is fixed to the project root ({@see Paths::base()}) so `composer require` always
 * operates on this project's composer.json, not on whatever directory the
 * caller happened to invoke the command from. The 120s timeout is a generous
 * upper bound for `composer require` against Packagist; very slow registries
 * or large path repos may still hit it, in which case the exception carries
 * the partial output for diagnosis.
 *
 * The $processFactory is a closure seam (not a constructor-injected
 * ProcessInterface) so tests can substitute a fake that records argv + cwd
 * and returns canned output, without depending on symfony/process internals.
 *
 * Composer binary path: $composerBinary defaults to `'composer'` (resolved
 * via the host's $PATH). Operators on shared hosts without a system-wide
 * Composer can ship `composer.phar` alongside the application and configure
 * `composer_binary` in `config.php` (or `SPORA_COMPOSER_BINARY` env) to
 * the absolute phar path. When the configured value ends in `.phar`,
 * PluginManager prepends PHP_BINARY so the PHP runtime executes it.
 */
final class PluginManager
{
    public const TIMEOUT_SECONDS = 120;

    /**
     * Composer flags we pass to every invocation. Centralized so a
     * future change (e.g. dropping --no-progress) only needs to happen
     * here, and so SONAR's "duplicate literal" check stays quiet.
     */
    private const FLAG_NO_INTERACTION      = '--no-interaction';
    private const FLAG_NO_PROGRESS         = '--no-progress';
    private const FLAG_OPTIMIZE_AUTOLOADER = '--optimize-autoloader';

    /**
     * @param Closure(array<int, string>, string): object $processFactory
     *        Returns a process-like object exposing run(), getExitCode(),
     *        getOutput(), getErrorOutput(), and isSuccessful(). Production
     *        wires this to a Symfony Process constructed with the given argv,
     *        cwd, and a 120s timeout.
     * @param string $composerBinary Absolute path to the composer executable
     *        (a phar) OR the name of an executable on $PATH (e.g. 'composer').
     *        The default ('composer') matches systems where the host has
     *        Composer installed globally — typical for dev/CI but NOT for
     *        shared-host deployments.
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Closure $processFactory,
        private readonly Paths $paths,
        private readonly string $composerBinary = 'composer',
    ) {}

    public function install(PluginInstallRequest $req): PluginInstallResult
    {
        if ($req->path !== null && $req->path !== '') {
            $result = $this->installFromPath($req);
        } else {
            $result = $this->installFromRegistry($req);
        }

        $this->logger->info('Plugin install completed', [
            'package' => $req->package,
            'status'  => $result->status,
        ]);

        return $result;
    }

    public function uninstall(string $package): PluginInstallResult
    {
        $argv = $this->composerArgv([
            'remove',
            $package,
            self::FLAG_NO_INTERACTION,
            self::FLAG_NO_PROGRESS,
        ]);

        $output = $this->runProcess($argv);

        $this->logger->info('Plugin uninstalled', ['package' => $package]);

        return new PluginInstallResult(
            package: $package,
            status: PluginInstallResult::STATUS_UNINSTALLED,
            message: $output,
        );
    }

    public function update(?string $package = null): PluginInstallResult
    {
        $args = ['update'];
        if ($package !== null) {
            $args[] = $package;
        }
        $argv = $this->composerArgv([
            ...$args,
            self::FLAG_NO_INTERACTION,
            self::FLAG_NO_PROGRESS,
            self::FLAG_OPTIMIZE_AUTOLOADER,
        ]);

        $output = $this->runProcess($argv);

        $this->logger->info('Plugin updated', ['package' => $package ?? '*']);

        return new PluginInstallResult(
            package: $package ?? '',
            status: PluginInstallResult::STATUS_UPDATED,
            message: $output,
        );
    }

    /**
     * Enumerate every plugin currently loadable by the framework.
     *
     * Walks the same data source {@see \Spora\Plugins\PluginLoaderCache}
     * uses at boot, so the CLI inventory and the runtime inventory cannot
     * drift. `path` is `realpath()`-resolved — for path-repo installs, that
     * is the source checkout rather than the `plugins/<slug>/` symlink.
     *
     * @return list<array{name: string, version: ?string, path: ?string}>
     */
    public function list(): array
    {
        $plugins = [];

        foreach ($this->pluginDirectories() as $dir) {
            if ($dir === '' || !is_dir($dir)) {
                continue;
            }

            foreach (glob(rtrim($dir, '/') . '/*/plugin.json') ?: [] as $manifestFile) {
                $real = realpath($manifestFile);
                if ($real === false) {
                    continue;
                }
                $plugins[] = $this->pluginFromManifest($real);
            }
        }

        usort(
            $plugins,
            static fn(array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']),
        );

        return $plugins;
    }

    /**
     * Build a list-entry from a single plugin.json manifest path.
     *
     * `name` prefers `composer.json#name`; the directory basename is the
     * fallback for partial installs (no sibling `composer.json`). The
     * manifest itself is treated as an existence marker — its `slug` is
     * not read here, so malformed manifests still surface with
     * `(unknown)` version even though {@see \Spora\Plugins\PluginLoader}
     * would reject them on boot.
     *
     * @return array{name: string, version: ?string, path: ?string}
     */
    private function pluginFromManifest(string $manifestFile): array
    {
        $pluginDir = dirname($manifestFile);
        $slug      = basename($pluginDir);

        $composerFile = $pluginDir . '/composer.json';
        $raw          = is_readable($composerFile) ? file_get_contents($composerFile) : false;
        $decoded      = is_string($raw) && $raw !== ''
            ? json_decode($raw, true)
            : null;

        $name    = '';
        $version = null;
        if (is_array($decoded)) {
            $name    = (string) ($decoded['name'] ?? '');
            $version = isset($decoded['version']) && is_string($decoded['version']) && $decoded['version'] !== ''
                ? $decoded['version']
                : null;
        }

        return [
            'name'    => $name !== '' ? $name : $slug,
            'version' => $version,
            'path'    => $pluginDir,
        ];
    }

    /** @return list<string> */
    private function pluginDirectories(): array
    {
        return [$this->paths->plugins()];
    }

    private function installFromRegistry(PluginInstallRequest $req): PluginInstallResult
    {
        $spec = ($req->constraint !== null && $req->constraint !== '')
            ? $req->package . ':' . $req->constraint
            : $req->package;

        $argv = $this->composerArgv([
            'require',
            $spec,
            self::FLAG_NO_INTERACTION,
            self::FLAG_NO_PROGRESS,
            self::FLAG_OPTIMIZE_AUTOLOADER,
        ]);

        $output = $this->runProcess($argv);

        return new PluginInstallResult(
            package: $req->package,
            status: PluginInstallResult::STATUS_INSTALLED,
            constraint: $req->constraint,
            message: $output,
        );
    }

    private function installFromPath(PluginInstallRequest $req): PluginInstallResult
    {
        $path = $req->path ?? '';
        if (!is_dir($path)) {
            throw new PluginInstallFailedException(
                "Path does not exist: {$path}",
                exitCode: 1,
            );
        }

        $slug = $this->packageSlug($req->package);

        // Register the local directory as a Composer path repo, then require the
        // package with `*@dev` so the path repo's local version satisfies the
        // constraint. The slug is `vendor-name` (slash→dot) so two packages
        // with the same name from different vendors (acme/foo vs spora-ai/foo)
        // cannot collide on `repositories.<slug>`.
        $this->runProcess($this->composerArgv([
            'config',
            "repositories.{$slug}",
            'path',
            $path,
        ]));

        $output = $this->runProcess($this->composerArgv([
            'require',
            $req->package . ':*@dev',
            self::FLAG_NO_INTERACTION,
            self::FLAG_NO_PROGRESS,
            self::FLAG_OPTIMIZE_AUTOLOADER,
        ]));

        return new PluginInstallResult(
            package: $req->package,
            status: PluginInstallResult::STATUS_INSTALLED,
            path: $path,
            message: $output,
        );
    }

    /**
     * Build a Composer `repositories.<name>` slug from a package name.
     *
     * Using only the last segment would collide when two vendors ship a
     * package with the same name (`acme/foo` and `spora-ai/foo` both →
     * `repositories.foo`). The full `vendor/name` is collision-free; we
     * rewrite `/` to `.` because Composer repository keys are restricted
     * to `[A-Za-z0-9_.-]+` and dots read more naturally than hyphens in
     * nested config (`repositories.spora-ai.something-plugin`).
     */
    private function packageSlug(string $package): string
    {
        return str_replace('/', '.', $package);
    }

    /**
     * Build the argv prefix that invokes Composer. When the configured binary
     * is a phar (`.phar` extension), the PHP runtime is prepended so the phar
     * executes via the same PHP binary as the parent process (PHP_BINARY is
     * always defined in CLI). For a bare executable name (e.g. `'composer'`),
     * the prefix is just the binary — relying on the host's $PATH.
     *
     * @param array<int, string> $args  Composer subcommand + arguments, without the binary prefix.
     * @return array<int, string>
     */
    private function composerArgv(array $args): array
    {
        if (str_ends_with($this->composerBinary, '.phar')) {
            return [PHP_BINARY, $this->composerBinary, ...$args];
        }
        return [$this->composerBinary, ...$args];
    }

    /**
     * @param array<int, string> $argv
     */
    private function runProcess(array $argv): string
    {
        /** @var object $process */
        $process = ($this->processFactory)($argv, $this->paths->base());

        $process->run();

        if (!$process->isSuccessful()) {
            $exitCode = $process->getExitCode() ?? 1;
            $stderr   = $process->getErrorOutput();
            $stdout   = $process->getOutput();

            $this->logger->error('Composer process failed', [
                'argv'     => $argv,
                'exitCode' => $exitCode,
            ]);

            $detail = $stderr !== '' ? $stderr : $stdout;
            throw new PluginInstallFailedException(
                "composer exited {$exitCode}: {$detail}",
                exitCode: $exitCode,
                stderr: $stderr,
            );
        }

        return $process->getOutput();
    }
}
