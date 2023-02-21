<?php

namespace Drupal\Tests\package_manager\Unit;

use Drupal\package_manager\InstalledPackage;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\package_manager\InstalledPackage
 *
 * @group package_manager
 */
class InstalledPackageTest extends UnitTestCase {

  /**
   * @covers ::createFromArray
   */
  public function testPathResolution(): void {
    // It's okay to create an InstalledPackage without a path.
    $package = InstalledPackage::createFromArray([
      'name' => 'vendor/test',
      'type' => 'library',
      'version' => '1.0.0',
    ]);
    $this->assertNull($package->path);

    $package = InstalledPackage::createFromArray([
      'name' => 'vendor/test',
      'type' => 'library',
      'version' => '1.0.0',
      'path' => NULL,
    ]);
    $this->assertNull($package->path);

    // Paths should be converted to real paths.
    $package = InstalledPackage::createFromArray([
      'name' => 'vendor/test',
      'type' => 'library',
      'version' => '1.0.0',
      'path' => __DIR__ . '/../..',
    ]);
    $this->assertSame(realpath(__DIR__ . '/../..'), $package->path);

    // If we provide a path that cannot be resolved to a real path, it should
    // raise an error.
    $this->expectException('TypeError');
    $this->expectExceptionMessageMatches('/must be of type \?string, bool given/');
    InstalledPackage::createFromArray([
      'name' => 'vendor/test',
      'type' => 'library',
      'version' => '1.0.0',
      'path' => $this->getRandomGenerator()->string(),
    ]);
  }

}
