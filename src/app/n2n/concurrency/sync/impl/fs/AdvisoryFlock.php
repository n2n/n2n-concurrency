<?php

namespace n2n\concurrency\sync\impl\fs;

use n2n\concurrency\sync\Lock;
use n2n\concurrency\sync\LockMode;
use n2n\util\io\fs\FsPath;

class AdvisoryFlock implements Lock {

	function __construct(private FsPath $fsPath) {
	}

	function acquire(LockMode $lockMode = LockMode::EXCLUSIVE): void {

	}

	function acquireNb(LockMode $lockMode = LockMode::EXCLUSIVE): bool {
		// TODO: Implement acquireNb() method.
	}

	function isActive(): bool {
		// TODO: Implement isActive() method.
	}

	function release(): bool {
		// TODO: Implement release() method.
	}
}