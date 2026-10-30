# FileLock: create lock files atomically with `link()`

Status: idea, not implemented.

## Problem

`FileLock::tryToCreateFile()` creates the lock file with `fopen($path, 'x+')` and then writes the timestamp in a
separate step. In between, the lock file exists but is empty.

Under heavy load another process can run `checkForOrphan()` during this window. It reads `''`, which is not numeric,
treats the file as an orphan and deletes it. The first process then writes its timestamp into the unlinked file, so it
believes it holds the lock. The results:

- two processes can hold the lock at the same time (mutual exclusion is broken)
- `release()` fails with `LockOperationFailedException: Could not release lock. Lock file did not exist: ...` or with a
  timestamp mismatch

Current mitigation (on `develop`, not yet committed at the time of writing):

- `checkForOrphan()` treats an empty lock file as an orphan only if its mtime is older than `orphanCheckTimeoutSec`
- `tryToCreateFile()` verifies the lock by re-reading the file through its path instead of the open handle

## Why waiting is not enough

Waiting (`usleep()`) before deciding that an empty file is an orphan only makes the race less likely:

- `usleep(1)` takes about as long as the system call itself.
- A longer wait (10–50 ms) covers only the normal case. Under load, or on a slow shared drive, the writer can be paused
  for longer than that.
- Every wait adds latency to every request that hits a busy lock.

## Clock skew

The lock files can be on a shared drive and written by other nodes or containers whose clocks differ.

- The timestamp stored in the lock file comes from the writing node's clock and is compared with the local `time()`.
- The mtime check has the same problem. On NFS the mtime is usually set by the file server's clock, but it is still
  compared with the local `time()`.

If a node's clock is off by more than `orphanCheckTimeoutSec` (default 360 s), a fresh lock can be deleted as an orphan,
or a real orphan is never cleaned up.

## Idea: never have an empty lock file

1. Write the timestamp to a uniquely named temp file in the same directory as the lock file, e.g.
   `<lockfile>.<random>.tmp`.
2. Create the lock file with `link($tmpPath, $lockPath)`.
3. Delete the temp file (`unlink($tmpPath)`) in every case.

```php
protected function tryToCreateFile(): bool {
	$this->checkIfLockWritable();

	$time = (string) (time() + random_int(0, 9999) / 10000);
	$tmpPath = $this->lockFsPath . '.' . bin2hex(random_bytes(8)) . '.tmp';
	IoUtils::putContents($tmpPath, $time);

	try {
		if (!@link($tmpPath, (string) $this->lockFsPath)) {
			// NFS: the link may have been created even though the reply was lost.
			clearstatcache(true, $tmpPath);
			if ((@stat($tmpPath)['nlink'] ?? 1) !== 2) {
				return false;
			}
		}

		$this->acquireTime = $time;
		return true;
	} finally {
		@unlink($tmpPath);
	}
}
```

(Sketch only: error handling and `IoUtils` wrappers still need to be filled in.)

### Advantages

- A hard link is created atomically and fails if the target exists, so it gives the same exclusivity as
  `fopen(.., 'x')`.
- The lock file has its content from the moment it exists. There is no empty window, so the following are no longer
  needed:
  - the mtime check for empty files in `checkForOrphan()`
  - the read-back verification in `tryToCreateFile()`
- `link()` is the classic way to lock files on NFS. `O_EXCL` (`fopen` with `x`) is unreliable on older NFS versions.

### Caveats

- **NFS lost replies:** `link()` can report an error although the link was created. Check whether the temp file now
  has 2 links (`stat($tmp)['nlink'] === 2`). If so, the lock was acquired.
- **Filesystem support:** some shared filesystems, such as certain SMB/CIFS mounts, do not support hard links. Either
  fall back to the current `fopen(.., 'x+')` approach or document the requirement.
- **Leftover temp files:** if a process crashes between steps 1 and 3, a `*.tmp` file is left behind. It does not block
  the lock, but it may need cleaning up from time to time.

## Remaining issue: real orphans and clock skew

`link()` removes the empty-file race, but it does not solve clock skew when detecting real orphans. A skew-free option
is to measure the age on the file server's clock:

1. Touch a probe file in the same directory.
2. Compare its mtime with the lock file's mtime.

On NFS both mtimes come from the server's clock, so the local clocks don't matter.

## Other related issue: stale orphan double-delete

When a lock really is orphaned, two waiting processes can both detect it. One deletes it and creates a new lock, then
the other deletes that new lock. This only happens after a real orphan, so it is rare. It could be solved by renaming
the orphan to a unique name before deleting it, or by switching to `flock()`-based locking.

## Other related issue: exceptions from `__destruct()`

`FileLock::__destruct()` calls `release()`, which can throw `LockOperationFailedException`. Exceptions thrown from
destructors are hard to handle. Consider catching the exception in `__destruct()` and reporting it with
`trigger_error()` instead.
