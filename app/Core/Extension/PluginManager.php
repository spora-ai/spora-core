<?php

declare(strict_types=1);

namespace Spora\Core\Extension;

use Closure;
use Psr\Log\LoggerInterface;
use Spora\Core\Extension\Exceptions\PluginInstallFailedException;

/**
 * Manages plugin install / uninstall / list / update by shelling out to Composer.
 *
 * Security: every command is built as an argv array — never a string — and
 * handed to the processFactory closure, which wraps {@see \Symfony\Component\Process\Process}.
 * Process spawns the child without an intervening shell, so a package name
 * from a CLI flag or HTTP body is literal data, never shell metacharacters.
 *
 * CWD is fixed to the project root ({@see $basePath}) so `composer require` always
 * operates on this project's composer.json, not on whatever directory the
 * caller happened to invoke the command from. The 120s timeout is a generous
 * upper bound for `composer require` against Packagist; very slow registries
 * or large path repos may still hit it, in which case the exception carries
 * the partial output for diagnosis.
 *
 * The $processFactory is a closure seam (not a constructor-injected
 * ProcessInterface) so tests can substitute a fake that records argv + cwd
 * and returns canned output, without depending on symfony/process internals.
 */
final class PluginManager
{
    public const TIMEOUT_SECONDS = 120;

    /**
     * @param Closure(array<int, string>, string): object $processFactory
     *        Returns a process-like object exposing run(), getExitCode(),
     *        getOutput(), getErrorOutput(), and isSuccessful(). Production
     *        wires this to a Symfony Process constructed with the given argv,
     *        cwd, and a 120s timeout.
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Closure $processFactory,
        private readonly string $basePath,
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
        $argv = [
            'composer',
            'remove',
            $package,
            '--no-interaction',
            '--no-progress',
        ];

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
        $argv = ['composer', 'update'];
        if ($package !== null) {
            $argv[] = $package;
        }
        $argv = array_merge($argv, [
            '--no-interaction',
            '--no-progress',
            '--optimize-autoloader',
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
     * Enumerate every package installed via Composer whose composer.json declares
     * `type: spora-plugin`. Returns an array of {name, version, path} entries.
     *
     * Returns [] when `composer show` fails or returns undecodable JSON — the
     * CLI "list" command should treat that as "no plugins installed" rather
     * than surfacing a confusing composer error to the operator.
     *
     * @return list<array{name: string, version: ?string, path: ?string}>
     */
    public function list(): array
    {
        $argv = ['composer', 'show', '--installed', '--direct', '--format=json'];

        try {
            $output = $this->runProcess($argv);
        } catch (PluginInstallFailedException $e) {
            $this->logger->info('Plugin list: composer show failed, returning empty list', [
                'exitCode' => $e->exitCode,
            ]);
            return [];
        }

        $decoded = json_decode($output, true);
        if (!is_array($decoded)) {
            return [];
        }

        $plugins = [];
        foreach ($decoded as $entry) {
            if (is_array($entry) && ($entry['type'] ?? null) === 'spora-plugin') {
                $plugins[] = [
                    'name'    => (string) ($entry['name'] ?? ''),
                    'version' => isset($entry['version']) ? (string) $entry['version'] : null,
                    'path'    => isset($entry['path']) ? (string) $entry['path'] : null,
                ];
            }
        }

        return $plugins;
    }

    private function installFromRegistry(PluginInstallRequest $req): PluginInstallResult
    {
        $spec = ($req->version !== null && $req->version !== '')
            ? $req->package . ':' . $req->version
            : $req->package;

        $argv = [
            'composer',
            'require',
            $spec,
            '--no-interaction',
            '--no-progress',
            '--optimize-autoloader',
        ];

        $output = $this->runProcess($argv);

        return new PluginInstallResult(
            package: $req->package,
            status: PluginInstallResult::STATUS_INSTALLED,
            version: $req->version,
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
        // constraint. The slug must be unique per repo; using the package's
        // last segment matches Composer's own convention for path repos.
        $this->runProcess([
            'composer',
            'config',
            "repositories.{$slug}",
            'path',
            $path,
        ]);

        $output = $this->runProcess([
            'composer',
            'require',
            $req->package . ':*@dev',
            '--no-interaction',
            '--no-progress',
            '--optimize-autoloader',
        ]);

        return new PluginInstallResult(
            package: $req->package,
            status: PluginInstallResult::STATUS_INSTALLED,
            path: $path,
            message: $output,
        );
    }

    private function packageSlug(string $package): string
    {
        $pos = strrpos($package, '/');
        return $pos === false ? $package : substr($package, $pos + 1);
    }

    /**
     * @param array<int, string> $argv
     */
    private function runProcess(array $argv): string
    {
        /** @var object $process */
        $process = ($this->processFactory)($argv, $this->basePath);

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
