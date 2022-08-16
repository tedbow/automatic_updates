<?php

namespace Drupal\Tests\package_manager\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\File\FileSystemInterface;
use Drupal\package_manager\PathLocator;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\package_manager\PathLocator
 *
 * @group package_manager
 */
class PathLocatorTest extends UnitTestCase {

  /**
   * @covers ::getStagingRoot
   */
  public function testStagingRoot(): void {
    $config_factory = $this->getConfigFactoryStub([
      'system.site' => [
        'uuid' => 'my_site_id',
      ],
    ]);
    $file_system = $this->prophesize(FileSystemInterface::class);
    $file_system->getTempDirectory()->willReturn('/path/to/temp');

    $path_locator = new PathLocator(
      '/path/to/drupal',
      $config_factory,
      $file_system->reveal()
    );
    $this->assertSame('/path/to/temp/.package_managermy_site_id', $path_locator->getStagingRoot());
  }

  /**
   * Tests that deprecations are raised for missing constructor arguments.
   *
   * @group legacy
   */
  public function testConstructorDeprecations(): void {
    $container = new ContainerBuilder();
    $container->set('config.factory', $this->getConfigFactoryStub());
    $container->set('file_system', $this->createMock(FileSystemInterface::class));
    \Drupal::setContainer($container);

    $this->expectDeprecation('Calling ' . PathLocator::class . '::__construct() without the $config_factory argument is deprecated in automatic_updates:8.x-2.1 and will be required before automatic_updates:3.0.0. See https://www.drupal.org/node/3300008.');
    new PathLocator('/path/to/drupal', NULL, $container->get('file_system'));

    $this->expectDeprecation('Calling ' . PathLocator::class . '::__construct() without the $file_system argument is deprecated in automatic_updates:8.x-2.1 and will be required before automatic_updates:3.0.0. See https://www.drupal.org/node/3300008.');
    new PathLocator('/path/to/drupal', $container->get('config.factory'));
  }

}
