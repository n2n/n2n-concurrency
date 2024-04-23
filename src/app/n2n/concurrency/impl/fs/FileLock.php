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
namespace n2n\concurrency\impl\fs;

use n2n\concurrency\Lock;
use n2n\concurrency\LockMode;
use n2n\util\io\fs\FsPath;
use n2n\util\io\IoUtils;

/**
 * A FileLock, when acquired, creates a file and removes it again, when released. While the file exists,
 * attempts to acquire a lock by other FileLocks for the same file are blocked or fail.
 */
class FileLock implements Lock {

	const DEFAULT_ACQUIRE_ATTEMPTS = 5;
	const DEFAULT_SLEEP_US = 50000;

	private int $acquireAttempts = self::DEFAULT_ACQUIRE_ATTEMPTS;
	private int $defaultSleepUs = self::DEFAULT_SLEEP_US;

	function __construct(private FsPath $lockFsPath) {

	}

	public function getAcquireAttempts(): int {
		return $this->acquireAttempts;
	}

	public function setAcquireAttempts(int $acquireAttempts): static {
		$this->acquireAttempts = $acquireAttempts;
		return $this;
	}

	public function getDefaultSleepUs(): int {
		return $this->defaultSleepUs;
	}

	public function setDefaultSleepUs(int $defaultSleepUs): static {
		$this->defaultSleepUs = $defaultSleepUs;
		return $this;
	}

	/**
	 * {@inheritDoc}
	 *
	 * If blocking is false, and the lock could not be achieved on the first attempt it will return false otherwise it
	 * will make {@link self::$acquireAttempts} - 1 more attempts to do so an usleep {@link self::$defaultSleepUs} microseconds
	 * between the attempts. If the last attempt fails a FileLockTimeoutException will be thrown.
	 *
	 * Note: LockMode $lockMode {@link LockMode::SHARED} is not supported, {@link LockMode::EXCLUSIVE} will be used instead.
	 */
	function acquire(bool $blocking, LockMode $lockMode = LockMode::EXCLUSIVE): bool {
		// TODO: Implement acquire() method.

		// check if lock file exists
		// if false IoUtils::putContents(<path>, $token)
		// check if IoUtils::getContents(<path>) === $token
	}

	function isActive(): bool {
		// TODO: Implement isActive() method.
	}

	function release(): bool {
		// TODO: Implement release() method.
	}
}