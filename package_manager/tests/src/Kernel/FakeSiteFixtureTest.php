<?php

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\package_manager\ComposerUtility;

/**
 * Test that the 'fake-site' fixture is a valid starting point.
 *
 * @group package_manager
 * @internal
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

  /**
   * Tests if `modifyPackage` can be called on all packages in the fixture.
   *
   * @see \Drupal\Tests\package_manager\Traits\FixtureUtilityTrait::modifyPackage()
   */
  public function testCallToModifyPackage(): void {
    $project_root = $this->container->get('package_manager.path_locator')->getProjectRoot();
    $stage = $this->createStage();
    $installed_packages = $stage->getActiveComposer()->getInstalledPackages();
    foreach (self::getExpectedFakeSitePackages() as $package_name) {
      $this->assertArrayHasKey($package_name, $installed_packages);
      $this->assertSame('9.8.0', $installed_packages[$package_name]->getPrettyVersion());
      $this->modifyPackage(
        $project_root,
        $package_name,
        ['version' => '11.1.0']
      );
    }
  }

  /**
   * Tests if `removePackage` can be called on all packages in the fixture.
   *
   * @covers \Drupal\Tests\package_manager\Traits\FixtureUtilityTrait::removePackage()
   */
  public function testCallToRemovePackage(): void {
    $expected_packages = self::getExpectedFakeSitePackages();
    $project_root = $this->container->get('package_manager.path_locator')->getProjectRoot();
    $stage = $this->createStage();
    $actual_packages = array_keys($stage->getActiveComposer()->getInstalledPackages());
    sort($actual_packages);
    $this->assertSame($expected_packages, $actual_packages);
    foreach (self::getExpectedFakeSitePackages() as $package_name) {
      $this->removePackage(
        $project_root,
        $package_name,
      );
    }
  }

  /**
   * Check which packages are installed in each file.
   */
  public function testExpectedPackages(): void {
    $expected_packages = $this->getExpectedFakeSitePackages();
    $active_dir = $this->container->get('package_manager.path_locator')->getProjectRoot();
    $stage = $this->createStage();
    $original_installed_php = $stage->getActiveComposer()->getInstalledPackages();
    $installed_php_packages = array_keys($original_installed_php);
    sort($installed_php_packages);
    $installed_json = json_decode(file_get_contents($active_dir . '/vendor/composer/installed.json'), TRUE, 512, JSON_THROW_ON_ERROR);
    $installed_json_packages = [];
    foreach ($installed_json['packages'] as $package) {
      $installed_json_packages[] = $package['name'];
    }
    sort($installed_json_packages);
    $this->assertSame($expected_packages, $installed_json_packages);
    // Assert same packages are present in both installed.json and installed.php.
    $this->assertSame($installed_json_packages, $installed_php_packages);
  }

  /**
   * Gets the expected packages in the `fake_site` fixture.
   *
   * @return string[]
   *   The package names.
   */
  private static function getExpectedFakeSitePackages(): array {
    $packages = [
      'drupal/core',
      'drupal/core-recommended',
      'drupal/core-dev',
    ];
    sort($packages);
    return $packages;
  }

}
