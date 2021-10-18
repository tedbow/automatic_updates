<?php

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\package_manager\ComposerUtility;

/**
 * @coversDefaultClass \Drupal\package_manager\ComposerUtility
 *
 * @group package_manager
 */
class ComposerUtilityTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['package_manager'];

  /**
   * Data provider for ::testCorePackagesFromLockFile().
   *
   * @return array[]
   *   Sets of arguments to pass to the test method.
   */
  public function providerCorePackagesFromLockFile(): array {
    $fixtures_dir = __DIR__ . '/../../fixtures';

    return [
      'distro with drupal/core-recommended' => [
        // This fixture's lock file mentions drupal/core, which is considered a
        // canonical core package, but it will be ignored in favor of
        // drupal/core-recommended, which always requires drupal/core as one of
        // its direct dependencies.
        "$fixtures_dir/distro_core_recommended",
        ['drupal/core-recommended'],
      ],
      'distro with drupal/core' => [
        "$fixtures_dir/distro_core",
        ['drupal/core'],
      ],
    ];
  }

  /**
   * Tests that required core packages are found by scanning the lock file.
   *
   * @param string $dir
   *   The path of the fake site fixture.
   * @param string[] $expected_packages
   *   The names of the core packages which should be detected.
   *
   * @covers ::getCorePackageNames
   *
   * @dataProvider providerCorePackagesFromLockFile
   */
  public function testCorePackagesFromLockFile(string $dir, array $expected_packages): void {
    $packages = ComposerUtility::createForDirectory($dir)
      ->getCorePackageNames();
    $this->assertSame($expected_packages, $packages);
  }

}
