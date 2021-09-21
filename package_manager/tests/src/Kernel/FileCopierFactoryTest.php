<?php

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * @covers \Drupal\package_manager\FileCopierFactory
 *
 * @group package_manager
 */
class FileCopierFactoryTest extends KernelTestBase {

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
   * Tests creating a file copier using our specialized factory class.
   *
   * @param string|null $configured_copier
   *   The copier to use, as configured in automatic_updates.settings. Can be
   *   'rsync', 'php', or NULL.
   *
   * @dataProvider providerFactory
   */
  public function testFactory(?string $configured_copier): void {
    $factory = $this->container->get('package_manager.file_copier.factory');

    switch ($configured_copier) {
      case 'rsync':
        $expected_copier = $this->container->get('package_manager.file_copier.rsync');
        break;

      case 'php':
        $expected_copier = $this->container->get('package_manager.file_copier.php');
        break;

      default:
        $expected_copier = $factory->create();
        break;
    }

    $this->config('package_manager.settings')
      ->set('file_copier', $configured_copier)
      ->save();

    $this->assertSame($expected_copier, $factory->create());
  }

}
