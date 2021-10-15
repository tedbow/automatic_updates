<?php

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * @covers \Drupal\package_manager\FileSyncerFactory
 *
 * @group package_manager
 */
class FileSyncerFactoryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['package_manager'];

  /**
   * Data provider for ::testFactory().
   *
   * @return mixed[][]
   *   Sets of arguments to pass to the test method.
   */
  public function providerFactory(): array {
    return [
      ['rsync'],
      ['php'],
      [NULL],
    ];
  }

  /**
   * Tests creating a file syncer using our specialized factory class.
   *
   * @param string|null $configured_syncer
   *   The syncer to use, as configured in automatic_updates.settings. Can be
   *   'rsync', 'php', or NULL.
   *
   * @dataProvider providerFactory
   */
  public function testFactory(?string $configured_syncer): void {
    $factory = $this->container->get('package_manager.file_syncer.factory');

    switch ($configured_syncer) {
      case 'rsync':
        $expected_syncer = $this->container->get('package_manager.file_syncer.rsync');
        break;

      case 'php':
        $expected_syncer = $this->container->get('package_manager.file_syncer.php');
        break;

      default:
        $expected_syncer = $factory->create();
        break;
    }

    $this->config('package_manager.settings')
      ->set('file_syncer', $configured_syncer)
      ->save();

    $this->assertSame($expected_syncer, $factory->create());
  }

}
