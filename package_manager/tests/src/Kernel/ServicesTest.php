<?php

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Tests that Package Manager services are wired correctly.
 *
 * @group package_manager
 */
class ServicesTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['package_manager'];

  /**
   * Tests that Package Manager's public services can be instantiated.
   */
  public function testPackageManagerServices(): void {
    $services = [
      'package_manager.beginner',
      'package_manager.stager',
      'package_manager.committer',
    ];
    foreach ($services as $service) {
      $this->assertIsObject($this->container->get($service));
    }
  }

}
