<?php
/*
 * Copyright (c) 2012-2016, Hofmänner New Media.
 * DO NOT ALTER OR REMOVE COPYRIGHT NOTICES OR THIS FILE HEADER.
 *
 * This file is part of the N2N FRAMEWORK.
 *
 * The N2N FRAMEWORK is free software: you can redistribute it and/or modify it under the terms of
 * the GNU Lesser General Public License as published by the Free Software Foundation, either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * N2N is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even
 * the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details: http://www.gnu.org/licenses/
 *
 * The following people participated in this project:
 *
 * Andreas von Burg.....: Architect, Lead Developer
 * Bert Hofmänner.......: Idea, Frontend UI, Community Leader, Marketing
 * Thomas Günther.......: Developer, Hangar
 */

namespace n2n\concurrency\sync\impl\fs;

use n2n\concurrency\sync\Lock;
use n2n\concurrency\sync\LockMode;
use n2n\util\io\fs\FsPath;
use n2n\util\io\IoUtils;
use n2n\util\io\IoException;
use n2n\util\io\fs\FileOperationException;
use n2n\util\ex\IllegalStateException;
use n2n\util\type\ArgUtils;
use n2n\concurrency\sync\err\LockOperationFailedException;
use n2n\concurrency\sync\err\LockAcquireTimeoutException;

/**
 * A FileLock, when acquired, creates a file and removes it again, when released. While the file exists,
 * attempts to acquire a lock by other FileLocks for the same file are blocked or fail.
 */
class FileLock implements Lock {

	const DEFAULT_ACQUIRE_ATTEMPTS = 5;
	const DEFAULT_SLEEP_US = 50000;
	const DEFAULT_ORPHAN_CHECK_AFTER_ATTEMPTS = 3;
	const DEFAULT_ORPHAN_CHECK_TIMEOUT_SEC = 120;

	private int $acquireAttempts = self::DEFAULT_ACQUIRE_ATTEMPTS;
	private int $sleepUs = self::DEFAULT_SLEEP_US;
	private int $orphanCheckAfterAttempts = self::DEFAULT_ORPHAN_CHECK_AFTER_ATTEMPTS;
	private int $orphanCheckTimeoutSec = self::DEFAULT_ORPHAN_CHECK_TIMEOUT_SEC;

	private bool $acquired = false;


	function __construct(private FsPath $lockFsPath) {
	}

	function __destruct() {
		$this->release();
	}

	public function getAcquireAttempts(): int {
		return $this->acquireAttempts;
	}

	public function setAcquireAttempts(int $acquireAttempts): static {
		$this->acquireAttempts = $acquireAttempts;
		return $this;
	}

	public function getSleepUs(): int {
		return $this->sleepUs;
	}

	public function setSleepUs(int $sleepUs): static {
		$this->sleepUs = $sleepUs;
		return $this;
	}

	public function getOrphanCheckAfterAttempts(): int {
		return $this->orphanCheckAfterAttempts;
	}

	public function setOrphanCheckAfterAttempts(int $orphanCheckAfterAttempts): void {
		ArgUtils::assertTrue($orphanCheckAfterAttempts > 0, 'Illegal dateCheckAttempts: ' . $orphanCheckAfterAttempts);
		$this->orphanCheckAfterAttempts = $orphanCheckAfterAttempts;
	}

	public function getOrphanCheckTimeoutSec(): int {
		return $this->orphanCheckTimeoutSec;
	}

	public function setOrphanCheckTimeoutSec(int $orphanCheckTimeoutSec): void {
		ArgUtils::assertTrue($orphanCheckTimeoutSec > 0, 'Illegal $maxLockTime: ' . $orphanCheckTimeoutSec);
		$this->orphanCheckTimeoutSec = $orphanCheckTimeoutSec;
	}


	private function checkIfLockWritable(): void {
		if ($this->lockFsPath->isDir()) {
			throw new LockOperationFailedException('Lock file location is a directory: ' . $this->lockFsPath);
		}

		$parentFsPath = $this->lockFsPath->getParent();
		if (!$parentFsPath->isDir()) {
			try {
				$parentFsPath->mkdirs();
			} catch (IoException $e) {
				throw new LockOperationFailedException('Parent directories for lock file do not exist and could '
						. ' not be created: ' . $this->lockFsPath, previous: $e);
			}
		}

		if (!$parentFsPath->isWritable()) {
			throw new LockOperationFailedException('Lock file location is not writable: ' . $this->lockFsPath);
		}
	}

	private function checkForOrphan(): bool {
		try {
			$lockTimeSec = IoUtils::getContents($this->lockFsPath);
		} catch (IoException $e) {
			// lock could have been released in the meantime.
			return false;
		}

		if (is_numeric($lockTimeSec) && (time() - $lockTimeSec) <= $this->orphanCheckTimeoutSec) {
			return false;
		}

		trigger_error(FileLock::class . ' detected an orphan lock file and will remove it: ' . $this->lockFsPath
				. '; Seconds since creation: ' . $lockTimeSec . '; Timeout seconds: ' . $this->orphanCheckTimeoutSec);

		try {
			IoUtils::unlink($this->lockFsPath);
			return true;
		} catch (IoException $e) {
			// lock could have been released in the meantime or another lock performed an orphan check. So if the file
			// does not exist anymore the IoException can be ignored.
			if (!$this->lockFsPath->exists()) {
				return true;
			}

			// there is a small chance that another could have removed the lock and already created a new one
			// and in this case this exception would be
			throw new LockOperationFailedException('Could not delete orphan lock file: ' . $this->lockFsPath,
					previous: $e);
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * If the lock could not be achieved on the first attempt it will return false otherwise it
	 * will make {@link self::$acquireAttempts} - 1 more attempts to do so an usleep {@link self::$sleepUs} microseconds
	 * between the attempts. If the last attempt fails a FileLockTimeoutException will be thrown.
	 *
	 * Note: LockMode $lockMode {@link LockMode::SHARED} is not supported, {@link LockMode::EXCLUSIVE} will be used instead.
	 */
	function acquire(LockMode $lockMode = LockMode::EXCLUSIVE): void {
		for ($i = 0; !$this->acquireNb($lockMode); ++$i) {
			if ($i === $this->orphanCheckTimeoutSec && $this->checkForOrphan()) {
				continue;
			}

			if ($i >= $this->acquireAttempts) {
				throw new LockAcquireTimeoutException('Could not acquire lock in the required time frame.'
						. 'Max attempts: ' . $this->acquireAttempts . '; Sleep between attempts us: ' . $this->sleepUs);
			}

			usleep($this->getSleepUs());
		}
//
//		$remainingAttempts = $this->getAcquireAttempts();
//		$dateCheckAttempts = 1;
//
//		$this->checkIfLockWritable();
//
//		IllegalStateException::assertTrue($remainingAttempts > 0, 'Remaining ');
//		if ($remainingAttempts <= 0) {
//			throw new IllegalGetAcquireAttemptsException();
//		}
//
//		while ($remainingAttempts > 0) {
//			if ($dateCheckAttempts === $this->getOrphanCheckAfterAttempts()) {
//				$lockTime = IoUtils::getContents($this->lockFsPath);
//				$timeDiff = time() - $lockTime;
//				if ($timeDiff > $this->getOrphanCheckTimeoutSec()) {
//					IoUtils::unlink($this->lockFsPath);
//				}
//			}
//			try {
//				$fp = IoUtils::fopen($this->lockFsPath, 'x');
//				IoUtils::fwrite($fp, time());
//				fclose($fp);
//				//there was no lock, and we were able to create it
//				return true;
//			} catch (IoException $e) {
//				if ($blocking) {
//					//there was a lock, we return False because $blocking is true
//					return false;
//				}
//
//				//there was a lock, we try again till we run out of attempts
//				$remainingAttempts--;
//				$dateCheckAttempts++;
//				if ($remainingAttempts > 0) {
//					usleep($this->getSleepUs());
//				}
//			}
//		}
//		throw new FileLockTimeoutException();
	}

	function acquireNb(LockMode $lockMode = LockMode::EXCLUSIVE): bool {
		$this->checkIfLockWritable();

		try {
			$fp = IoUtils::fopen($this->lockFsPath, 'x');
			// there was no lock, and we were able to create it
		} catch (IoException $e) {
			return false;
		}

		try {
			IoUtils::fwrite($fp, time());
			$this->acquired = true;
			return true;
		} catch (IoException $e) {
			throw new LockOperationFailedException('Could not write time to lock file: ' . $this->lockFsPath,
					previous: $e);
		} finally {
			fclose($fp);
		}
	}

	function isActive(): bool {
		return $this->acquired;
	}

	function release(): bool {
		if (!$this->acquired) {
			return false;
		}

		try {
			IoUtils::unlink($this->lockFsPath);
			$this->acquired = false;
			return true;
		} catch (IoException $e) {
			throw new LockOperationFailedException('Could not release lock. Lock file: ' . $this->lockFsPath,
					previous: $e);
		}
	}

}