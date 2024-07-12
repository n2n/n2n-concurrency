<?php

namespace n2n\concurrency\sync\impl\fs;

use n2n\util\io\fs\FsPath;
use n2n\util\io\fs\FileOperationException;
use PHPUnit\Framework\TestCase;
use n2n\concurrency\sync\impl\Sync;
use n2n\util\io\IoUtils;
use n2n\util\io\IoException;
use n2n\concurrency\sync\err\LockAcquireTimeoutException;
use n2n\concurrency\sync\err\LockOperationFailedException;

class FileLockAdvancedTest extends TestCase {

	private FsPath $tmpDirFsPath;

	/**
	 * @throws FileOperationException
	 * @throws IoException
	 */
	function setUp(): void {
		$this->tmpDirFsPath = new FsPath(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'n2n-concurrency-tmp-' . uniqid());
		$this->tmpDirFsPath->delete();
		$this->tmpDirFsPath->mkdirs();
	}

	/**
	 * @throws IoException
	 * @throws FileOperationException
	 */
	function tearDown(): void {
		$this->tmpDirFsPath->delete();
	}

	/**
	 * @throws IoException
	 */
	function testAcquireNb(): void {
		$fsPath = $this->tmpDirFsPath->ext('lock1.lock');

		$this->assertFalse($fsPath->exists());
		$lock1 = Sync::byFileLock($fsPath);
		$lock2 = Sync::byFileLock($fsPath);

		$this->assertTrue($lock1->acquireNb());
		$this->assertTrue($fsPath->exists());
		$this->assertTrue(is_numeric(IoUtils::getContents($fsPath)));
		$this->assertFalse($lock2->acquireNb());

		$this->assertTrue($lock1->isActive());
		$this->assertFalse($lock2->isActive());

		$this->assertTrue($lock1->release());
		$this->assertFalse($fsPath->exists());
		$this->assertFalse($lock2->release());

		$this->assertTrue($lock2->acquireNb());

		$this->assertFalse($lock1->isActive());
		$this->assertTrue($lock2->isActive());
	}

	function testDestruct(): void {
		$fsPath = $this->tmpDirFsPath->ext('lock1.lock');

		$this->assertFalse($fsPath->exists());
		$lock1 = Sync::byFileLock($fsPath);

		$this->assertTrue($lock1->acquireNb());
		$this->assertTrue($fsPath->exists());

		$lock1 = null;
		gc_collect_cycles();

		$this->assertFalse($fsPath->exists());
	}


	/**
	 * @throws IoException
	 */
	function testAcquireNbOrphan(): void {
		$fsPath = $this->tmpDirFsPath->ext('lock1.lock');

		$this->assertFalse($fsPath->exists());

		$lockTime = time() - FileLock::DEFAULT_ORPHAN_CHECK_TIMEOUT_SEC - 1;
		IoUtils::putContents($fsPath, $lockTime);

		$lock1 = Sync::byFileLock($fsPath)->setOrphanCheckAfterAttempts(2);
		$this->assertFalse($lock1->acquireNb());

		$lock1 = Sync::byFileLock($fsPath)->setOrphanCheckAfterAttempts(null);
		$this->assertFalse($lock1->acquireNb());

		$this->assertEquals($lockTime, IoUtils::getContents($fsPath));

		$lock2 = Sync::byFileLock($fsPath)->setOrphanCheckAfterAttempts(1)
				->setOrphanDetectionWarningEnabled(false);
		$this->assertTrue($lock2->acquireNb());

		$this->assertNotEquals($lockTime, IoUtils::getContents($fsPath));
	}

	/**
	 * @throws IoException
	 * @throws LockAcquireTimeoutException
	 */
	function testAcquireOrphan(): void {
		$fsPath = $this->tmpDirFsPath->ext('lock1.lock');

		$this->assertFalse($fsPath->exists());

		$lockTime = time() - FileLock::DEFAULT_ORPHAN_CHECK_TIMEOUT_SEC - 1;
		IoUtils::putContents($fsPath, $lockTime);

		try {
			$lock = Sync::byFileLock($fsPath)->setAcquireAttempts(3)->setSleepUs(0)
					->setOrphanCheckAfterAttempts(null);
			$lock->acquire();
			$this->fail('LockAcquireTimeoutException expected');
		} catch (LockAcquireTimeoutException $e) {
		}

		$this->assertEquals($lockTime, IoUtils::getContents($fsPath));

		try {
			$lock = Sync::byFileLock($fsPath)->setAcquireAttempts(3)->setSleepUs(0)
					->setOrphanCheckAfterAttempts(null)
					->setOrphanDetectionWarningEnabled(false);
			$lock->acquire();
			$this->fail('LockAcquireTimeoutException expected');
		} catch (LockAcquireTimeoutException $e) {
		}

		$this->assertEquals($lockTime, IoUtils::getContents($fsPath));

		$lock = Sync::byFileLock($fsPath)->setAcquireAttempts(2)->setSleepUs(0)
				->setOrphanCheckAfterAttempts(2)
				->setOrphanDetectionWarningEnabled(false);
		$lock->acquire();

		$this->assertNotEquals($lockTime, IoUtils::getContents($fsPath));

		$lock = Sync::byFileLock($fsPath)->setAcquireAttempts(2)
				->setOrphanCheckAfterAttempts(3);

		$this->expectException(LockOperationFailedException::class);
		$lock->acquire();
	}

	/**
	 * @throws IoException
	 */
	function testCheckOrphanWarning() {
		$fsPath = $this->tmpDirFsPath->ext('lock1.lock');

		$this->assertFalse($fsPath->exists());

		$lockTime = time() - FileLock::DEFAULT_ORPHAN_CHECK_TIMEOUT_SEC - 1;
		IoUtils::putContents($fsPath, $lockTime);

		$errorHandlerCalled = false;
		set_error_handler(function($errno, $errstr) use (&$errorHandlerCalled) {
			$errorHandlerCalled = true;
			$this->assertEquals(E_USER_WARNING, $errno);
			$this->assertStringContainsString('FileLock detected', $errstr);
			return true;
		});

		try {
			$lock1 = Sync::byFileLock($fsPath);
			$this->assertTrue($lock1->checkForOrphan());
		} finally {
			restore_error_handler();
		}

		$this->assertTrue($errorHandlerCalled);
	}

	function testAcquireTimeout() {
		$fsPath = $this->tmpDirFsPath->ext('lock1.lock');

		$this->assertFalse($fsPath->exists());

		$fileLockMock = $this->getMockBuilder(FileLock::class)
				->setConstructorArgs([$fsPath])
				->onlyMethods(['tryToCreateFile', 'checkForOrphan', 'wait'])
				->getMock();
		$fileLockMock->setAcquireAttempts(3);

		$fileLockMock->expects($this->exactly(1))->method('checkForOrphan')->willReturn(false);
		$fileLockMock->expects($this->exactly(3))->method('tryToCreateFile')->willReturn(false);
		$fileLockMock->expects($this->exactly(2))->method('wait');

		$this->expectException(LockAcquireTimeoutException::class);
		$fileLockMock->acquire();
	}

	function testAcquireSleep(): void {
		$fsPath = $this->tmpDirFsPath->ext('lock1.lock');

		$this->assertFalse($fsPath->exists());

		$blockingLock = Sync::byFileLock($fsPath);
		$this->assertTrue($blockingLock->acquireNb());

		$lock = Sync::byFileLock($fsPath)->setAcquireAttempts(3)->setSleepUs(50000);
		$startS = microtime(true);

		try {
			$lock->acquire();
			$this->fail(LockAcquireTimeoutException::class . ' expected.');
		} catch (LockAcquireTimeoutException $e) {
		}

		$deltaS = (microtime(true)) - $startS;

		// only 2 sleep attempts
		$this->assertGreaterThanOrEqual(100000, $deltaS * 1000000);
		// less than 3 sleep attempts
		$this->assertLessThan(150000, $deltaS * 1000000);
	}

	/**
	 * @throws LockAcquireTimeoutException
	 */
	function testAcquireAfterWait(): void {
		$fsPath = $this->tmpDirFsPath->ext('lock1.lock');

		$this->assertFalse($fsPath->exists());

		$blockingLock = Sync::byFileLock($fsPath);
		$this->assertTrue($blockingLock->acquireNb());

		$fileLockMock = $this->getMockBuilder(FileLock::class)
				->setConstructorArgs([$fsPath])
				->onlyMethods(['wait'])
				->getMock();

		$calls = 0;

		$fileLockMock->expects($this->exactly(3))->method('wait')
				->willReturnCallback(function () use (&$calls, $blockingLock) {
					$calls++;
					if ($calls === 3) {
						$blockingLock->release();
					}
				});

		$fileLockMock->acquire();
		$this->assertTrue($fileLockMock->isActive());
	}

	function testDirDoesNotExist() {
		$fsPath = $this->tmpDirFsPath->ext('subdir')->ext('lock1.lock');

		$this->assertFalse($fsPath->exists());

		$this->expectException(LockOperationFailedException::class);
		$this->expectExceptionMessage('Parent directory for lock file does not exist');
		Sync::byFileLock($fsPath)->acquireNb();
	}

	/**
	 * @throws FileOperationException
	 */
	function testDirNotWritable() {
		$fsPath = $this->tmpDirFsPath->ext('subdir')->ext('lock1.lock');
		$dirFsPath = $fsPath->getParent();
		$dirFsPath->mkdirs();
		$dirFsPath->chmod(0444);

		$this->assertFalse($fsPath->exists());

		$this->expectException(LockOperationFailedException::class);
		$this->expectExceptionMessage('Lock file location is not writable:');

		try {
			Sync::byFileLock($fsPath)->acquireNb();
		} finally {
			$dirFsPath->chmod(0777);
		}
	}
}