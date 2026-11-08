<?php

declare(strict_types=1);

use Spora\Console\Worker\WorkerLock;

describe('WorkerLock', function (): void {
    beforeEach(function (): void {
        $this->storageDir = sys_get_temp_dir() . '/spora-worker-lock-' . getmypid() . '-' . uniqid();
        mkdir($this->storageDir, 0777, true);
        $this->lockFile = $this->storageDir . '/spora-worker.lock';
    });

    afterEach(function (): void {
        chmod($this->storageDir, 0755);
        foreach (glob($this->storageDir . '/*') ?: [] as $file) {
            is_dir($file) ? @rmdir($file) : @unlink($file);
        }
        @rmdir($this->storageDir);
    });

    it('acquires an uncontended lock and records the holder pid', function (): void {
        $lock = new WorkerLock($this->lockFile);

        expect($lock->holderPid())->toBeNull()
            ->and($lock->acquire())->toBeTrue()
            ->and($lock->holderPid())->toBe(getmypid())
            ->and(trim((string) file_get_contents($this->lockFile)))->toBe((string) getmypid());

        $lock->release();
    });

    it('takes over a lock whose holder is gone', function (): void {
        // The daemon spawns `task:run` children with proc_open(), and a
        // child inherits the lock descriptor — so the flock outlives the
        // daemon that took it. A worker starting against such a lock must
        // take it over instead of refusing to run.
        $holderFd = holdWorkerLockFile($this->lockFile, 4194304);

        $lock = new WorkerLock($this->lockFile);

        expect($lock->acquire())->toBeTrue()
            ->and(trim((string) file_get_contents($this->lockFile)))->toBe((string) getmypid())
            ->and($lock->takenOverFrom())->toBe(4194304)
            ->and($lock->path())->toBe($this->lockFile);

        fclose($holderFd);
        $lock->release();
    });

    it('reports a lock file it cannot open', function (): void {
        $lock = new WorkerLock($this->storageDir . '/missing/spora-worker.lock');

        expect(fn() => $lock->acquire())->toThrow(RuntimeException::class);
    });

    it('reports a lock path that is a directory', function (): void {
        mkdir($this->lockFile);

        $lock = new WorkerLock($this->lockFile);

        expect(fn() => $lock->acquire())->toThrow(RuntimeException::class);
    });

    it('refuses to take over a lock it cannot replace', function (): void {
        // The storage directory cannot be written, so the lock file cannot
        // be replaced: the old inode stays in place, and the orphan still
        // holds it.
        $holderFd = holdWorkerLockFile($this->lockFile, 4194304);
        touch($this->lockFile . '.takeover');
        chmod($this->storageDir, 0555);

        $lock = new WorkerLock($this->lockFile);

        expect($lock->acquire())->toBeFalse();

        chmod($this->storageDir, 0755);
        fclose($holderFd);
    });

    it('refuses to start while a live worker holds the lock', function (): void {
        // The pid of this test process is alive, so the lock is not stale
        // and must not be taken over.
        $holderFd = holdWorkerLockFile($this->lockFile, getmypid());

        $lock = new WorkerLock($this->lockFile);

        expect($lock->acquire())->toBeFalse()
            ->and($lock->holderPid())->toBe(getmypid());

        fclose($holderFd);
    });

    it('does not steal a lock whose holder cannot be identified', function (): void {
        // The holder may be a worker that has taken the flock but not yet
        // written its pid into the file.
        $holderFd = holdWorkerLockFile($this->lockFile, 0);

        $lock = new WorkerLock($this->lockFile);

        expect($lock->acquire())->toBeFalse();

        fclose($holderFd);
    });

    it('lets only one of several workers hold the lock', function (): void {
        // Every WorkerLock opens its own descriptor, and flock() is per
        // descriptor, so several workers in one process contend exactly as
        // several processes would.
        $locks = array_map(fn(): WorkerLock => new WorkerLock($this->lockFile), range(1, 5));

        $acquired = array_filter($locks, fn(WorkerLock $lock): bool => $lock->acquire());

        expect(count($acquired))->toBe(1);
    });

    it('releases the lock so another worker can take it', function (): void {
        $first = new WorkerLock($this->lockFile);
        expect($first->acquire())->toBeTrue();

        $second = new WorkerLock($this->lockFile);
        expect($second->acquire())->toBeFalse();

        $first->release();

        expect($second->acquire())->toBeTrue();

        $second->release();
        // Releasing a lock that is not held is a no-op.
        $second->release();

        expect(true)->toBeTrue();
    });

    it('makes a second worker wait while the first one decides', function (): void {
        // Who owns the lock is decided under the takeover mutex. A worker
        // that starts while another one is deciding must wait for it
        // rather than read a half-written lock file and decide for
        // itself — which is how two workers each end up holding a lock.
        $mutexFd = fopen($this->lockFile . '.takeover', 'c');
        flock($mutexFd, LOCK_EX);

        $readyFile = $this->storageDir . '/ready';
        $script = sprintf(
            'require %s; file_put_contents($argv[2], "ready"); (new Spora\Console\Worker\WorkerLock($argv[1]))->acquire();',
            var_export(BASE_PATH . '/vendor/autoload.php', true),
        );

        $proc = proc_open(
            [PHP_BINARY, '-r', $script, $this->lockFile, $readyFile],
            [1 => ['pipe', 'w']],
            $pipes,
        );

        // Wait until the child is about to take the lock.
        $deadline = microtime(true) + 10.0;
        while (!file_exists($readyFile) && microtime(true) < $deadline) {
            usleep(20_000);
        }
        expect(file_exists($readyFile))->toBeTrue();

        // Give it time to reach the lock, then check it is still waiting.
        usleep(500_000);
        expect(proc_get_status($proc)['running'])->toBeTrue();

        flock($mutexFd, LOCK_UN);
        fclose($mutexFd);

        while (proc_get_status($proc)['running']) {
            usleep(20_000);
        }
        fclose($pipes[1]);
        proc_close($proc);
    });
});
