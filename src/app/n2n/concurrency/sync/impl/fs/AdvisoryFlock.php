<?php

namespace n2n\concurrency\sync\impl\fs;

use n2n\concurrency\sync\Lock;
use n2n\util\io\IoUtils;
use n2n\util\io\IoException;
use n2n\concurrency\sync\LockMode;
use n2n\concurrency\sync\err\LockOperationFailedException;
use n2n\util\io\fs\FsPath;
use n2n\util\io\stream\impl\FileResourceStream;
use n2n\util\io\fs\CouldNotAchieveFlockException;

class AdvisoryFlock implements Lock {
	private ?FileResourceStream $frs = null;
	private bool $removeLockFile = false;

	function __construct(private FsPath $fsPath) {
	}

	/**
	 * @$this->removeLockFile unlink could collide with fopen command from another thread. Set online true
	 * when necesseary. fopen will cause a permission denied exception in this case.
	 */
	public function release(): bool {
		if ($this->frs === null) {
			return false;
		}

		try {
			$this->frs->close();
		} catch (IoException $e) {
			throw new LockOperationFailedException('Could not release lock. Lock file: ' . $this->fsPath,
					previous: $e);
		}

		if (!$this->isRemoveLockFile()) {
			return true;
		}

		try {
			IoUtils::unlink($this->frs->getFileName());
			$this->frs = null;
			return true;
		} catch (IoException $e) {
			throw new LockOperationFailedException('Could not remove lock file: ' . $this->fsPath,
					previous: $e);
		}
	}

	function acquire(LockMode $lockMode = LockMode::EXCLUSIVE): void {
		if ($this->isActive()) {
			throw new LockOperationFailedException('Lock was already acquired.');
		}

		$lock = $lockMode === LockMode::EXCLUSIVE ? LOCK_EX : LOCK_SH;

		try {
			$this->frs = new FileResourceStream($this->fsPath, 'w', $lock);
		} catch (CouldNotAchieveFlockException|IoException $e) {
			throw new LockOperationFailedException('Could not acquire flock. Lock file: ' . $this->fsPath,
					previous: $e);
		}
	}

	function acquireNb(LockMode $lockMode = LockMode::EXCLUSIVE): bool {
		$lock = $lockMode === LockMode::EXCLUSIVE ? LOCK_EX : LOCK_SH;

		try {
			$this->frs = new FileResourceStream($this->fsPath, 'w', $lock | LOCK_NB);
			return true;
		} catch (CouldNotAchieveFlockException|IoException $e) {
			return false;
		}
	}

	function isActive(): bool {
		return $this->frs !== null;
	}

	public function isRemoveLockFile(): bool {
		return $this->removeLockFile;
	}

	public function setRemoveLockFile(bool $removeLockFile): static {
		$this->removeLockFile = $removeLockFile;
		return $this;
	}

	public function __destruct() {
		$this->release();
	}
}