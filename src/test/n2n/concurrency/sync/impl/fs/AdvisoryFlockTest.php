<?php

namespace n2n\concurrency\sync\impl\fs;

use PHPUnit\Framework\TestCase;
use n2n\util\io\fs\FsPath;
use n2n\concurrency\sync\LockMode;

class AdvisoryFlockTest extends TestCase {
	function testCacheFileLock() {
		$lockFileFsPath = new FsPath(tempnam(sys_get_temp_dir(),''));
		$lockFileFsPath->delete();

		$this->assertTrue(!$lockFileFsPath->exists());

		$lock = new AdvisoryFlock($lockFileFsPath);
		$lock->acquire(LockMode::EXCLUSIVE);

		$this->assertTrue($lockFileFsPath->exists());
		$this->assertTrue($lockFileFsPath->isFile());

		$lock2 = new AdvisoryFlock($lockFileFsPath);
		$this->assertFalse($lock2->acquireNb(LockMode::EXCLUSIVE));

	}


	function testKeepFile() {
		$lockFileFsPath = new FsPath(tempnam(sys_get_temp_dir(),''));
		$lockFileFsPath->delete();

		$this->assertTrue(!$lockFileFsPath->exists());

		$lock = new AdvisoryFlock($lockFileFsPath);
		$lock->acquire(LockMode::EXCLUSIVE);

		$this->assertTrue($lockFileFsPath->exists());
		$this->assertTrue($lockFileFsPath->isFile());

		//if removeLockFile is false > lock file is not removed
		//this is the default: but can also set by $lock->setRemoveLockFile(false);
		$this->assertTrue($lockFileFsPath->exists());
		$lock->release();

		$this->assertTrue($lockFileFsPath->exists());

		//if removeLockFile is true > lock file is removed if possible ;-)
		$lock->setRemoveLockFile(true);
		$lock->release();

		$this->assertFalse($lockFileFsPath->exists());
	}
}
