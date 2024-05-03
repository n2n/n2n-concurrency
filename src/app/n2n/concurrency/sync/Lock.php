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
namespace n2n\concurrency\sync;

use n2n\concurrency\sync\err\LockAcquireTimeoutException;
use n2n\concurrency\sync\err\LockOperationFailedException;

/**
 * If any failure other than described occurs, a {@link LockOperationFailedException} should be thrown.
 */
interface Lock {

	/**
	 * Tries to acquire the lock and blocks the process if the lock for the same unit was already acquired by
	 * another lock of this kind until it can be acquired because it was released by the other lock released.
	 *
	 * @param LockMode $lockMode
	 * @throws LockAcquireTimeoutException if it takes to long.
	 */
	function acquire(LockMode $lockMode = LockMode::EXCLUSIVE): void;

	/**
	 * Tries to acquire the lock but does not block the process if the lock for the same unit was already acquired by
	 * another lock of this kind and returns false in this case.
	 *
	 * @param LockMode $lockMode
	 * @return bool whether the lock could be acquired or not.
	 */
	function acquireNb(LockMode $lockMode = LockMode::EXCLUSIVE): bool;

	/**
	 * If the lock was acquired before and has not been released yet.
	 *
	 * @return bool
	 */
	function isActive(): bool;

	/**
	 * Releases a before acquired lock.
	 *
	 * @return bool whether a lock could have been released or not. false if {@link self::isActive()} is false.
	 */
	function release(): bool;
}