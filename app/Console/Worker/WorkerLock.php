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

    /** The dead holder whose lock was replaced, when the acquisition took one over. */
    private ?int $takenOverFromPid = null;

    public function __construct(private readonly string $lockFile) {}

    /**
     * Take the lock, replacing one whose recorded holder is gone.
     *
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

            // The lock is held. It may only be taken over when its recorded
            // holder is gone — a lock that cannot be attributed to a process
            // is a lock we do not steal, because the holder may be a worker
            // that has not written its pid yet.
            $holderPid = $this->readHolderPid();

            return $holderPid !== null
                && !self::isProcessAlive($holderPid)
                && $this->replaceStaleLock($holderPid);
        } finally {
            flock($mutex, LOCK_UN);
            fclose($mutex);
        }
    }

    public function release(): void
    {
        if ($this->fd === null) {
            return;
        }

        flock($this->fd, LOCK_UN);
        fclose($this->fd);
        $this->fd = null;
    }

    /**
     * The pid of the process holding the lock, as that process wrote it.
     * Null when the file holds no usable pid.
     */
    public function holderPid(): ?int
    {
        return $this->readHolderPid();
    }

    /**
     * The pid of the dead holder whose lock this acquisition replaced, or
     * null when the lock was taken as it stood.
     */
    public function takenOverFrom(): ?int
    {
        return $this->takenOverFromPid;
    }

    public function path(): string
    {
        return $this->lockFile;
    }

    /**
     * The descriptor every path that changes the lock's owner runs under.
     */
    private function openMutex(): mixed
    {
        $mutex = fopen($this->lockFile . self::TAKEOVER_SUFFIX, 'c');
        if ($mutex === false) {
            throw new RuntimeException(sprintf('Could not open the worker lock file at %s.', $this->lockFile));
        }

        return $mutex;
    }

    /**
     * Lock the file as it stands. Fails when a live worker holds it.
     */
    private function tryTake(): bool
    {
        $fd = fopen($this->lockFile, 'c');
        if ($fd === false) {
            throw new RuntimeException(sprintf('Could not open the worker lock file at %s.', $this->lockFile));
        }

        if (!flock($fd, LOCK_EX | LOCK_NB)) {
            fclose($fd);
            return false;
        }

        $this->adopt($fd);

        return true;
    }

    /**
     * Replace the lock file so the lock lives on an inode nobody holds.
     *
     * The orphan keeps its lock on the old inode, which no longer has a path.
     */
    private function replaceStaleLock(int $deadHolderPid): bool
    {
        @unlink($this->lockFile);

        $fd = fopen($this->lockFile, 'c');
        if ($fd === false) {
            throw new RuntimeException(sprintf('Could not open the worker lock file at %s.', $this->lockFile));
        }

        if (!flock($fd, LOCK_EX | LOCK_NB)) {
            fclose($fd);
            return false;
        }

        $this->takenOverFromPid = $deadHolderPid;
        $this->adopt($fd);

        return true;
    }

    /**
     * Become the holder: keep the descriptor and record the pid in the file.
     */
    private function adopt(mixed $fd): void
    {
        $this->fd = $fd;
        file_put_contents($this->lockFile, (string) getmypid());
    }

    private function readHolderPid(): ?int
    {
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
     */
    private static function isProcessAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (!function_exists('posix_kill')) {
            return is_dir('/proc/' . $pid);
        }

        return posix_kill($pid, 0) || posix_get_last_error() === self::EPERM;
    }
}
