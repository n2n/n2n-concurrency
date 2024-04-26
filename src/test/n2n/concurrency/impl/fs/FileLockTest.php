<?php

namespace n2n\concurrency\impl\fs;

use PHPUnit\Framework\TestCase;
use n2n\concurrency\LockMode;
use n2n\util\io\fs\FsPath;
use n2n\util\io\IoUtils;

class FileLockTest extends TestCase {

	private string $dirPath;

	public static function setUpBeforeClass(): void {
		//create and empty the dir before first test
//		$dirPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'n2n-concurrency-tmp';
//		IoUtils::rmdirs($dirPath);
//		IoUtils::mkdirs($dirPath);
	}

	protected function setUp(): void {
		$this->dirPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'n2n-concurrency-tmp';
		IoUtils::rmdirs($this->dirPath);
		IoUtils::mkdirs($this->dirPath);
	}

	public static function tearDownAfterClass(): void {
		//remove dir after last test
		$dirPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'n2n-concurrency-tmp';
		IoUtils::rmdirs($dirPath);
	}

	public function createFileLock(string $filename = 'deleteThisFile') {
		$fsPath = new FsPath($this->dirPath . DIRECTORY_SEPARATOR . $filename);
		return new FileLock($fsPath);
	}

	public function testAcquireExpectException() {
		//file is crated and therefore locked after first try
		$this->expectException(FileLockTimeoutException::class);
		$fileLock = $this->createFileLock();
		$fileLock->acquire(false, LockMode::EXCLUSIVE);
		$isAcquired = $fileLock->acquire(false, LockMode::EXCLUSIVE);
		$this->assertFalse($isAcquired);
	}

	public function testAcquire() {
		//file is crated and therefore locked after first try
		$fileLock = $this->createFileLock();
		$isAcquired = $fileLock->acquire(true, LockMode::EXCLUSIVE);
		$this->assertTrue($isAcquired);

		$fileLock2 = $this->createFileLock('blubb');
		$isAcquired = $fileLock2->acquire(true, LockMode::EXCLUSIVE);
		$this->assertTrue($isAcquired);

		$fileLock3 = $this->createFileLock();
		$isAcquired = $fileLock3->acquire(true, LockMode::EXCLUSIVE);
		$this->assertFalse($isAcquired);

	}

	public function testIsActive() {
		$fileLock = $this->createFileLock('blubb');
		$fileLock->acquire(true, LockMode::EXCLUSIVE);
		$isActive = $fileLock->isActive();
		$this->assertTrue($isActive);

		$fileLock2 = $this->createFileLock();
		$isActive = $fileLock2->isActive();
		$this->assertFalse($isActive);

		$fileLock3 = $this->createFileLock('blubb');
		$isActive = $fileLock3->isActive();
		$this->assertTrue($isActive);
	}

	public function testRelease() {
		$fileLock = $this->createFileLock('blubb');
		$fileLock->acquire(true, LockMode::EXCLUSIVE);
		$isActive = $fileLock->isActive();
		$this->assertTrue($isActive);

		$isReleased = $fileLock->release();
		$this->assertTrue($isReleased);
		$isActive = $fileLock->isActive();
		$this->assertFalse($isActive);
	}

	public function testMaxTimeDiff() {
		$fileLock = $this->createFileLock('blubb');
		$fileLock->acquire(false, LockMode::EXCLUSIVE);
		sleep(2);
		$fileLock2 = $this->createFileLock('blubb');
		$fileLock2->setMaxLockTime(1);
		$fileLock2->acquire(false, LockMode::EXCLUSIVE);
		$isReleased = $fileLock2->release();
		$isActive = $fileLock2->isActive();
		$this->assertFalse($isActive);
		$this->assertTrue($isReleased);
	}
}
