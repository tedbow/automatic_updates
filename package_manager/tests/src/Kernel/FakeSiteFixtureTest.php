<?php

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\package_manager\ComposerUtility;

/**
 * Test that the 'fake-site' fixture is a valid starting point.
 *
 * @group package_manager
 */
class FakeSiteFixtureTest extends PackageManagerKernelTestBase {

  /**
   * Tests the complete stage life cycle using the 'fake-site' fixture.
   */
  public function testLifeCycle(): void {
    $this->assertStatusCheckResults([]);
    $this->assertResults([]);
    // Ensure there are no validation errors after the stage lifecycle has been
    // completed.
    $this->assertStatusCheckResults([]);
  }

  /**
   * Tests calls to ComposerUtility class methods.
   */
  public function testCallToComposerUtilityMethods(): void {
    $active_dir = $this->container->get('package_manager.path_locator')->getProjectRoot();
    $composer_utility = ComposerUtility::createForDirectory($active_dir);
    // Although the fake-site fixture does not contain any Composer packages or
    // Drupal projects that would be returned from these methods calling them
    // and asserting that they return NULL proves there are not any missing
    // metadata in the fixture files that would cause these methods to throw an
    // exception.
    $this->assertNull($composer_utility->getProjectForPackage('any_random_name'));
    $this->assertNull($composer_utility->getPackageForProject('drupal/any_random_name'));
  }

}
