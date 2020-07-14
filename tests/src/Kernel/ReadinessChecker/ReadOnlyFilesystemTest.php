<?php

namespace Drupal\Tests\automatic_updates\Kernel\ReadinessChecker;

use Drupal\automatic_updates\ReadinessChecker\ReadOnlyFilesystem;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\FileSystemInterface;
use Drupal\KernelTests\KernelTestBase;
use org\bovigo\vfs\vfsStream;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\automatic_updates\ReadinessChecker\ReadOnlyFilesystem
 *
 * @group automatic_updates
 */
class ReadOnlyFilesystemTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'automatic_updates',
    'system',
  ];

  /**
   * Tests the readiness check where the root directory does not exist.
   *
   * @covers ::run
   *
   * @dataProvider providerNoWebRoot
   */
  public function testNoWebRoot($files) {
    vfsStream::setup('root');
    vfsStream::create($files);
    $readOnly = new ReadOnlyFilesystem(
      vfsStream::url('root'),
      $this->prophesize(LoggerInterface::class)->reveal(),
      $this->prophesize(FileSystemInterface::class)->reveal()
    );
    $this->assertEquals(['The web root could not be located.'], $readOnly->run());
  }

  /**
   * Data provider for testNoWebRoot().
   */
  public function providerNoWebRoot() {
    return [
      'no core.api.php' => [
        [
          'core' => [
            'core.txt' => 'test',
          ],
        ],
      ],
      'core.api.php in wrong location' => [
        [
          'core.api.php' => 'test',
        ],
      ],

    ];
  }

  /**
   * Tests the readiness check on writable file system on same logic disk.
   *
   * @covers ::run
   */
  public function testSameLogicDiskWritable() {
    $readOnly = new ReadOnlyFilesystem(
      self::getVfsRoot(),
      $this->container->get('logger.channel.automatic_updates'),
      $this->container->get('file_system')
    );
    $this->assertEquals([], $readOnly->run());
  }

  /**
   * Tests root and vendor directories are writable on different logical disks.
   *
   * @covers ::run
   */
  public function testDifferentLogicDiskWritable() {
    $readOnly = new TestReadOnlyFilesystem(
      self::getVfsRoot(),
      $this->container->get('logger.channel.automatic_updates'),
      $this->container->get('file_system')
    );
    $this->assertEquals([], $readOnly->run());
  }

  /**
   * Tests non-writable core and vendor directories on same logic disk.
   *
   * @covers ::run
   */
  public function testSameLogicDiskNotWritable() {
    $file_system = $this->createMock(FileSystemInterface::class);
    $file_system->expects($this->once())
      ->method('copy')
      ->willThrowException(new FileException());

    $root = self::getVfsRoot();
    $readOnly = new ReadOnlyFilesystem(
      $root,
      $this->container->get('logger.channel.automatic_updates'),
      $file_system
    );
    $this->assertEquals(["Logical disk at \"$root\" is read only. Updates to Drupal cannot be applied against a read only file system."], $readOnly->run());
  }

  /**
   * Tests the readiness check on read-only file system.
   *
   * @covers ::run
   */
  public function testDifferentLogicDiskNotWritable() {
    $root = self::getVfsRoot();

    // Assert messages if both core and vendor directory are not writable.
    $file_system = $this->createMock(FileSystemInterface::class);
    $file_system->expects($this->any())
      ->method('copy')
      ->willThrowException(new FileException());
    $readOnly = new TestReadOnlyFileSystem(
      $root,
      $this->container->get('logger.channel.automatic_updates'),
      $file_system
    );
    $this->assertEquals(
      [
        "Drupal core filesystem at \"$root/core\" is read only. Updates to Drupal core cannot be applied against a read only file system.",
        "Vendor filesystem at \"$root/vendor\" is read only. Updates to the site's code base cannot be applied against a read only file system.",
      ],
      $readOnly->run()
    );

    // Assert messages if core directory is not writable.
    $file_system = $this->createMock(FileSystemInterface::class);
    $file_system
      ->method('copy')
      ->withConsecutive(
        ['vfs://root/core/core.api.php', 'vfs://root/core/core.api.php.automatic_updates'],
        ['vfs://root/vendor/composer/LICENSE', 'vfs://root/vendor/composer/LICENSE.automatic_updates']
      )
      ->willReturnOnConsecutiveCalls(FALSE, TRUE);
    $readOnly = new TestReadOnlyFileSystem(
      $root,
      $this->container->get('logger.channel.automatic_updates'),
      $file_system
    );
    $this->assertEquals(
      ["Drupal core filesystem at \"$root/core\" is read only. Updates to Drupal core cannot be applied against a read only file system."],
      $readOnly->run()
    );

    // Assert messages if vendor directory is not writable.
    $file_system = $this->createMock(FileSystemInterface::class);
    $file_system
      ->method('copy')
      ->withConsecutive(
        ['vfs://root/core/core.api.php', 'vfs://root/core/core.api.php.automatic_updates'],
        ['vfs://root/vendor/composer/LICENSE', 'vfs://root/vendor/composer/LICENSE.automatic_updates']
      )
      ->willReturnOnConsecutiveCalls(TRUE, FALSE);
    $readOnly = new TestReadOnlyFileSystem(
      $root,
      $this->container->get('logger.channel.automatic_updates'),
      $file_system
    );
    $this->assertEquals(
      ["Vendor filesystem at \"$root/vendor\" is read only. Updates to the site's code base cannot be applied against a read only file system."],
      $readOnly->run()
    );
  }

  /**
   * Gets root of virtual Drupal directory.
   *
   * @return string
   *   The root.
   */
  protected static function getVfsRoot() {
    vfsStream::setup('root');
    vfsStream::create([
      'core' => [
        'core.api.php' => 'test',
      ],
      'vendor' => [
        'composer' => [
          'LICENSE' => 'test',
        ],
      ],
    ]);
    return vfsStream::url('root');
  }

}

/**
 * Test class to root and vendor directories in different logic disks.
 *
 * Calls to stat() does not work on \org\bovigo\vfs\vfsStream.
 */
class TestReadOnlyFileSystem extends ReadOnlyFilesystem {

  /**
   * {@inheritdoc}
   */
  protected function areSameLogicalDisk($root, $vendor) {
    return FALSE;
  }

}
