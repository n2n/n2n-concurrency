<?php

namespace n2n\concurrency\sync\impl\fs;

use PHPUnit\Framework\TestCase;
use n2n\concurrency\sync\LockMode;
use n2n\util\io\fs\FsPath;
use n2n\util\io\IoUtils;
use n2n\concurrency\sync\err\LockAcquireTimeoutException;
use n2n\concurrency\sync\err\LockOperationFailedException;
use n2n\util\io\IoException;
use n2n\util\io\fs\FileOperationException;

class FileLockTest extends TestCase {

	private string $dirPath;

	public static function setUpBeforeClass(): void {
		//create and empty the dir before first test
//		$dirPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'n2n-concurrency-tmp';
//		IoUtils::rmdirs($dirPath);
//		IoUtils::mkdirs($dirPath);
	}

	/**
	 * @throws IoException
	 * @throws FileOperationException
	 */
	protected function setUp(): void {
		$this->dirPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'n2n-concurrency-tmp';
		IoUtils::rmdirs($this->dirPath);
		IoUtils::mkdirs($this->dirPath);
	}

	/**
	 * @throws IoException
	 */
	public static function tearDownAfterClass(): void {
		//remove dir after last test
		$dirPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'n2n-concurrency-tmp';
		IoUtils::rmdirs($dirPath);
	}

	public function createFileLock(string $filename = 'deleteThisFile'): FileLock {
		$fsPath = new FsPath($this->dirPath . DIRECTORY_SEPARATOR . $filename);
		return new FileLock($fsPath);
	}

	public function testDoubleAcquire() {
		//file is crated and therefore locked after first try
		$this->expectException(LockOperationFailedException::class);
		$fileLock = $this->createFileLock()->setAcquireAttempts(3)->setSleepUs(50000);
		$fileLock->acquireNb(LockMode::EXCLUSIVE);
		$fileLock->acquire(LockMode::EXCLUSIVE);
		$this->assertFalse($fileLock->isActive());
	}

	public function testAcquireExpectException() {
		//file is crated and therefore locked after first try
		$this->expectException(LockAcquireTimeoutException::class);
		$fileLock = $this->createFileLock()->setAcquireAttempts(3)->setSleepUs(50000);
		$fileLock->acquireNb(LockMode::EXCLUSIVE);
		$fileLock2 = $this->createFileLock()->setAcquireAttempts(3)->setSleepUs(50000);
		$fileLock2->acquire(LockMode::EXCLUSIVE);
		$this->assertTrue($fileLock->isActive());
		$this->assertFalse($fileLock2->isActive());
	}

	public function testAcquire() {
		//file is crated and therefore locked after first try
		$fileLock = $this->createFileLock();
		$isAcquired = $fileLock->acquireNb(LockMode::EXCLUSIVE);
		$this->assertTrue($isAcquired);

		$fileLock2 = $this->createFileLock('blubb');
		$isAcquired = $fileLock2->acquireNb(LockMode::EXCLUSIVE);
		$this->assertTrue($isAcquired);

		//only one acquired lock for same file, therefore as long as the $fileLock block (don't release) the lock, acquire should fail
		$fileLock3 = $this->createFileLock();
		$isAcquired = $fileLock3->acquireNb(LockMode::EXCLUSIVE);
		$this->assertFalse($isAcquired);

		//after release acquire should work
		$fileLock->release();
		$isAcquired = $fileLock3->acquireNb(LockMode::EXCLUSIVE);
		$this->assertTrue($isAcquired);

	}

	/**
	 * @throws LockAcquireTimeoutException
	 */
	public function testIsActive() {

		$fileLock = $this->createFileLock('blubb');
		$fileLock->acquire(LockMode::EXCLUSIVE);
		$isActive = $fileLock->isActive();
		$this->assertTrue($isActive);

		$fileLock2 = $this->createFileLock();
		$isActive = $fileLock2->isActive();
		$this->assertFalse($isActive);

		//even if lock is for same file: as long as file is not acquired it has to be not active
		$fileLock3 = $this->createFileLock('blubb');
		$isActive = $fileLock3->isActive();
		$this->assertFalse($isActive);
	}

	/**
	 * @throws LockAcquireTimeoutException
	 */
	public function testRelease() {
		$fileLock = $this->createFileLock('blubb');
		$fileLock->acquire(LockMode::EXCLUSIVE);
		$isActive = $fileLock->isActive();
		$this->assertTrue($isActive);

		$isReleased = $fileLock->release();
		$this->assertTrue($isReleased);
		$isActive = $fileLock->isActive();
		$this->assertFalse($isActive);
	}

	/**
	 * @throws IoException
	 * @throws LockAcquireTimeoutException
	 */
	public function testOrphan() {
		$fsPath = new FsPath($this->dirPath . DIRECTORY_SEPARATOR . 'blubb');
		$fileLock = new FileLock($fsPath);
		$fileLock->setOrphanDetectionWarningEnabled(false);
		$this->assertFalse($fsPath->exists());
		$lockTime = time() - FileLock::DEFAULT_ORPHAN_CHECK_TIMEOUT_SEC - 1;
		IoUtils::putContents($fsPath, $lockTime);

		$this->assertEquals($lockTime, IoUtils::getContents($fsPath));
		$fileLock->acquire(LockMode::EXCLUSIVE); //fileLock is too old and a new one is generated
		$this->assertNotEquals($lockTime, IoUtils::getContents($fsPath));
		$this->assertEquals($fileLock->getAcquireTime(), IoUtils::getContents($fsPath));
		$isReleased = $fileLock->release();
		$isActive = $fileLock->isActive();
		$this->assertTrue($isReleased);
		$this->assertFalse($isActive);
	}

	/**
	 * @throws IoException
	 * @throws LockAcquireTimeoutException
	 */
	public function testOrphanExpectExceptionWasAlreadyReleased() {
		$fsPath = new FsPath($this->dirPath . DIRECTORY_SEPARATOR . 'blubb');
		$fileLock = new FileLock($fsPath);
		$fileLock->setOrphanDetectionWarningEnabled(false);
		$fileLock->acquireNb(LockMode::EXCLUSIVE);
		$lockTime = time() - FileLock::DEFAULT_ORPHAN_CHECK_TIMEOUT_SEC - 10;
		IoUtils::putContents($fsPath, $lockTime);
		$this->assertTrue($fileLock->isActive());

		$fileLock2 = new FileLock($fsPath);
		$fileLock2->setOrphanDetectionWarningEnabled(false);
		$fileLock2->setOrphanCheckTimeoutSec(1);
		$fileLock2->acquire(LockMode::EXCLUSIVE);

		$this->assertTrue($fileLock->isActive());
		$this->assertTrue($fileLock2->isActive());
		$this->assertTrue($fileLock2->release());
		$this->assertFalse($fileLock2->isActive());
		try {
			$fileLock->release();
			$this->fail('LockOperationFailedException expected');
		} catch (LockOperationFailedException $e) {
			$this->assertStringContainsString(
					'Could not release lock. Lock file did not exist:',
					$e->getMessage());
		}
	}

	/**
	 * @throws LockAcquireTimeoutException
	 * @throws IoException
	 */
	public function testOrphanExpectExceptionLockWasOverwritten() {
		$fsPath = new FsPath($this->dirPath . DIRECTORY_SEPARATOR . 'blubb');
		$fileLock = new FileLock($fsPath);
		$fileLock->setOrphanDetectionWarningEnabled(false);
		$fileLock->acquireNb(LockMode::EXCLUSIVE);
		IoUtils::putContents($fsPath, 'not-numeric');
		$this->assertTrue($fileLock->isActive());

		$fileLock2 = new FileLock($fsPath);
		$fileLock2->setOrphanDetectionWarningEnabled(false);
		$fileLock2->acquire(LockMode::EXCLUSIVE);

		$this->assertTrue($fileLock->isActive());
		$this->assertTrue($fileLock2->isActive());

		try {
			$fileLock->release();
			$this->fail('LockOperationFailedException expected');
		} catch (LockOperationFailedException $e) {
			$this->assertStringContainsString(
					'Could not release lock. Lock was overwritten by other lock.',
					$e->getMessage());
		}

		$this->assertFalse($fileLock->isActive());
		$this->assertTrue($fileLock2->isActive());
		$this->assertTrue($fileLock2->release());
		$this->assertFalse($fileLock2->isActive());

	}

	/**
	 */
	function testOrphanWasDeletedBetweenCheckAndUnlink() {
		$fsPath = new FsPath($this->dirPath . DIRECTORY_SEPARATOR . 'blubb');
		$fileLockMock = $this->getMockBuilder(FileLock::class)
				->setConstructorArgs([$fsPath])
				->onlyMethods(['releaseCheck'])
				->getMock();

		$this->expectException(LockOperationFailedException::class);
		$this->expectExceptionMessage('Could not release lock. Lock file:');

		$fileLockMock->acquireNb(LockMode::EXCLUSIVE);
		//because we skip the releaseCheck, we have the same as a deleted/unlinked FileLock file between check and unlink from release
		$fsPath->delete();
		$this->assertTrue($fileLockMock->isActive());
		$fileLockMock->release();
	}


}
