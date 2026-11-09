<?php

declare(strict_types=1);

namespace Spora\Console\Worker;

use RuntimeException;

/**
 * The single-instance lock that keeps one worker per storage directory.
 *
 * The lock is a `flock()` on `storage/spora-worker.lock`, with the holder's
 * pid written into the file. A worker that finds the lock held by a pid that
 * is gone takes it over, because the lock outlives the daemon that took it:
 * the daemon spawns `task:run` children with `proc_open()`, and a child
 * inherits the lock descriptor, so a crash leaves the `flock` held by an
 * orphan. A new lock file is a new inode, and the orphan cannot hold that.
 *
 * Every path that changes the lock's owner — taking a free lock, refusing a
 * live one, replacing a stale one — runs under a second lock file. Without
 * that, two workers starting at the same moment could each take a lock: one
 * on the old inode while the other replaced the file. The mutex is held only
 * while the owner changes and is closed before the first child is spawned, so
 * it is never inherited by a child and never outlives the process that took
 * it.
 */
final class WorkerLock
{
    /** `EPERM` — the process exists but belongs to another user. */
    private const EPERM = 1;

    private const TAKEOVER_SUFFIX = '.takeover';

    /** @var resource|null */
    private mixed $fd = null;

    private ?int $takenOverFromPid = null;

    /**
     * `$procRoot` is where the pid directories live. It is a parameter
     * because the `/proc` fallback — the only liveness check on a build
     * without `posix_kill()` — has to be exercisable where `/proc` cannot
     * be read.
     */
    public function __construct(
        private readonly string $lockFile,
        private readonly string $procRoot = '/proc',
    ) {}

    /**
     * @throws RuntimeException when the lock file cannot be opened.
     */
    public function acquire(): bool
    {
        $mutex = $this->openMutex();

        try {
            flock($mutex, LOCK_EX);

            if ($this->tryTake()) {
                return true;
            }

            // The lock is held; it may only be taken over when its recorded
            // holder is gone.
            $holderPid = $this->readHolderPid();

            return $holderPid !== null
                && !$this->isProcessAlive($holderPid)
                && $this->replaceStaleLock($holderPid);
        } finally {
            flock($mutex, LOCK_UN);
            fclose($mutex);
        }
    }

    /**
     * The flock lives on the descriptor, so unlocking without closing
     * would keep the lock held for as long as the process runs.
     */
    public function release(): void
    {
        if ($this->fd === null) {
            return;
        }

        flock($this->fd, LOCK_UN);
        fclose($this->fd);
        $this->fd = null;
    }

    public function holderPid(): ?int
    {
        return $this->readHolderPid();
    }

    /** Null when the lock was taken as it stood rather than taken over. */
    public function takenOverFrom(): ?int
    {
        return $this->takenOverFromPid;
    }

    public function path(): string
    {
        return $this->lockFile;
    }

    private function openMutex(): mixed
    {
        return $this->openFile($this->lockFile . self::TAKEOVER_SUFFIX);
    }

    private function tryTake(): bool
    {
        $fd = $this->openFile($this->lockFile);

        if (!flock($fd, LOCK_EX | LOCK_NB)) {
            fclose($fd);
            return false;
        }

        $this->adopt($fd);

        return true;
    }

    private function replaceStaleLock(int $deadHolderPid): bool
    {
        // Removing the file is what gives the lock a new inode. Where the
        // directory cannot be written, the old inode stays and the orphan
        // keeps holding it — the flock below then refuses.
        if (is_writable(dirname($this->lockFile))) {
            @unlink($this->lockFile);
        }

        $fd = $this->openFile($this->lockFile);

        if (!flock($fd, LOCK_EX | LOCK_NB)) {
            fclose($fd);
            return false;
        }

        $this->takenOverFromPid = $deadHolderPid;
        $this->adopt($fd);

        return true;
    }

    /**
     * The path is checked first so the failure is quiet: `fopen()` on a
     * path whose directory does not exist, or on a directory, warns — and
     * the caller reports the failure itself.
     *
     * @throws RuntimeException when the file cannot be opened.
     */
    private function openFile(string $path): mixed
    {
        if (!is_dir(dirname($path)) || is_dir($path)) {
            throw $this->openFailed();
        }

        $fd = fopen($path, 'c');
        if ($fd === false) {
            throw $this->openFailed();
        }

        return $fd;
    }

    private function openFailed(): RuntimeException
    {
        return new RuntimeException(sprintf('Could not open the worker lock file at %s.', $this->lockFile));
    }

    private function adopt(mixed $fd): void
    {
        $this->fd = $fd;
        file_put_contents($this->lockFile, (string) getmypid());
    }

    /**
     * Read the holder pid from the lock file.
     *
     * A file that cannot be read yields no pid, and no pid stops the
     * takeover: the holder may be a worker that has taken the flock but
     * not yet written its pid.
     */
    private function readHolderPid(): ?int
    {
        if (!is_file($this->lockFile)) {
            return null;
        }

        $raw = @file_get_contents($this->lockFile);
        if ($raw === false) {
            return null;
        }

        $pid = (int) trim($raw);

        return $pid > 0 ? $pid : null;
    }

    /**
     * Whether the process still exists.
     *
     * `posix_kill()` with signal 0 reports false both for a process that
     * does not exist (ESRCH) and for one owned by another user (EPERM).
     * The second means the process is there, so the lock is not ours to
     * take.
     *
     * Without `posix_kill()` the answer comes from `/proc`. A `/proc` that
     * cannot be read leaves the question open, and the caller reads "not
     * alive" as "safe to take the lock over" — so an unknown liveness
     * counts as alive and the lock is left alone.
     */
    private function isProcessAlive(int $pid): bool
    {
        if (!function_exists('posix_kill')) {
            // `/proc/self` exists wherever procfs is mounted, so its
            // absence means there is no procfs to read a pid directory
            // from.
            if (!is_dir($this->procRoot . '/self')) {
                return true;
            }

            return is_dir($this->procRoot . '/' . $pid);
        }

        return posix_kill($pid, 0) || posix_get_last_error() === self::EPERM;
    }
}
