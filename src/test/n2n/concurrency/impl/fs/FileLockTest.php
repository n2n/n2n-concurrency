<?php

namespace n2n\concurrency\impl\fs;

use PHPUnit\Framework\TestCase;
use n2n\concurrency\LockMode;
use n2n\util\io\fs\FsPath;
use n2n\util\io\IoUtils;

class FileLockTest extends TestCase {

	private string $dirPath;
	public string $fileName = 'deleteThisFile';

	public static function setUpBeforeClass(): void {
		//create and empty the dir before first test
//		$dirPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'n2n-concurrency';
//		IoUtils::rmdirs($dirPath);
//		IoUtils::mkdirs($dirPath);
	}

	protected function setUp(): void {
		$this->dirPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'n2n-concurrency';
		IoUtils::rmdirs($this->dirPath);
		IoUtils::mkdirs($this->dirPath);
	}

	public static function tearDownAfterClass(): void {
		//remove dir after last test
		$dirPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'n2n-concurrency';
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
		$return = $fileLock->acquire(false, LockMode::EXCLUSIVE);
		$this->assertFalse($return);
	}

	public function testAcquire() {
		//file is crated and therefore locked after first try
		$fileLock = $this->createFileLock();
		$return = $fileLock->acquire(true, LockMode::EXCLUSIVE);
		$this->assertTrue($return);

		$fileLock = $this->createFileLock('blubb');
		$return = $fileLock->acquire(true, LockMode::EXCLUSIVE);
		$this->assertTrue($return);

		$fileLock = $this->createFileLock();
		$return = $fileLock->acquire(true, LockMode::EXCLUSIVE);
		$this->assertFalse($return);

	}

	public function testIsActive() {
		$fileLock = $this->createFileLock('blubb');
		$fileLock->acquire(true, LockMode::EXCLUSIVE);
		$return = $fileLock->isActive();
		$this->assertTrue($return);

		$fileLock = $this->createFileLock();
		$return = $fileLock->isActive();
		$this->assertFalse($return);

		$fileLock = $this->createFileLock('blubb');
		$return = $fileLock->isActive();
		$this->assertTrue($return);
	}

	public function testRelease() {
		$fileLock = $this->createFileLock('blubb');
		$fileLock->acquire(true, LockMode::EXCLUSIVE);
		$return = $fileLock->isActive();
		$this->assertTrue($return);

		$fileLock->release();
		$return = $fileLock->isActive();
		$this->assertFalse($return);
	}
}
