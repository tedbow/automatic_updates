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
        'uuid' => '_my_site_id',
      ],
    ]);
    $file_system = $this->prophesize(FileSystemInterface::class);
    $file_system->getTempDirectory()->willReturn('/path/to/temp');

    $path_locator = new PathLocator(
      '/path/to/drupal',
      $config_factory,
      $file_system->reveal()
    );
    $this->assertSame('/path/to/temp/.package_manager_my_site_id', $path_locator->getStagingRoot());
  }

  /**
   * Data provider for ::testWebRoot().
   *
   * @return string[][]
   *   Sets of arguments to pass to the test method.
   */
  public function providerWebRoot(): array {
    // In certain sites (like those created by drupal/recommended-project), the
    // web root is a subdirectory of the project, and exists next to the
    // vendor directory.
    return [
      'recommended project' => [
        '/path/to/project/www',
        '/path/to/project',
        'www',
      ],
      'recommended project with trailing slash on app root' => [
        '/path/to/project/www/',
        '/path/to/project',
        'www',
      ],
      'recommended project with trailing slash on project root' => [
        '/path/to/project/www',
        '/path/to/project/',
        'www',
      ],
      'recommended project with trailing slashes' => [
        '/path/to/project/www/',
        '/path/to/project/',
        'www',
      ],
      // In legacy projects (i.e., created by drupal/legacy-project), the
      // web root is the project root.
      'legacy project' => [
        '/path/to/drupal',
        '/path/to/drupal',
        '',
      ],
      'legacy project with trailing slash on app root' => [
        '/path/to/drupal/',
        '/path/to/drupal',
        '',
      ],
      'legacy project with trailing slash on project root' => [
        '/path/to/drupal',
        '/path/to/drupal/',
        '',
      ],
      'legacy project with trailing slashes' => [
        '/path/to/drupal/',
        '/path/to/drupal/',
        '',
      ],
    ];
  }

  /**
   * Tests that the web root is computed correctly.
   *
   * @param string $app_root
   *   The absolute path of the Drupal root.
   * @param string $project_root
   *   The absolute path of the project root.
   * @param string $expected_web_root
   *   The value expected from getWebRoot().
   *
   * @covers ::getWebRoot
   *
   * @dataProvider providerWebRoot
   */
  public function testWebRoot(string $app_root, string $project_root, string $expected_web_root): void {
    $path_locator = $this->getMockBuilder(PathLocator::class)
      // Mock all methods except getWebRoot().
      ->setMethodsExcept(['getWebRoot'])
      ->setConstructorArgs([
        $app_root,
        $this->getConfigFactoryStub(),
        $this->prophesize(FileSystemInterface::class)->reveal(),
      ])
      ->getMock();

    $path_locator->method('getProjectRoot')->willReturn($project_root);
    $this->assertSame($expected_web_root, $path_locator->getWebRoot());
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
