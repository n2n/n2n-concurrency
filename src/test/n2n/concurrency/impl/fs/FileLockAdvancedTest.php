<?php

namespace n2n\concurrency\impl\fs;

use n2n\util\io\fs\FsPath;
use n2n\util\ex\IllegalStateException;
use n2n\util\io\fs\FileOperationException;
use PHPUnit\Framework\TestCase;
use n2n\concurrency\sync\impl\Sync;
use n2n\util\io\IoUtils;
use n2n\util\io\IoException;

class FileLockAdvancedTest extends TestCase {

	private FsPath $tmpDirFsPath;

	/**
	 * @throws FileOperationException
	 */
	function setUp(): void {
		$this->tmpDirFsPath = new FsPath(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'n2n-concurrency-tmp');
		$this->tmpDirFsPath->delete();
		$this->tmpDirFsPath->mkdirs();
	}

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
}