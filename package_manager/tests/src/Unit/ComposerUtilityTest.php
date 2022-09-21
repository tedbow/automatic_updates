<?php

namespace Drupal\Tests\package_manager\Unit;

use Composer\Package\PackageInterface;
use Drupal\package_manager\ComposerUtility;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\package_manager\ComposerUtility
 *
 * @group package_manager
 */
class ComposerUtilityTest extends UnitTestCase {

  /**
   * Data provider for ::testCorePackages().
   *
   * @return \string[][][]
   *   The test cases.
   */
  public function providerCorePackages(): array {
    return [
      'core-recommended not installed' => [
        ['drupal/core'],
        ['drupal/core'],
      ],
      'core-recommended installed' => [
        ['drupal/core', 'drupal/core-recommended'],
        ['drupal/core-recommended'],
      ],
    ];
  }

  /**
   * @covers ::getCorePackages
   *
   * @param string[] $installed_package_names
   *   The names of the packages that are installed.
   * @param string[] $expected_core_package_names
   *   The expected core package names that should be returned by
   *   ::getCorePackages().
   *
   * @dataProvider providerCorePackages
   */
  public function testCorePackages(array $installed_package_names, array $expected_core_package_names): void {
    $versions = array_fill(0, count($installed_package_names), '1.0.0');
    $installed_packages = array_combine($installed_package_names, $versions);

    $core_packages = $this->mockUtilityWithPackages($installed_packages)
      ->getCorePackages();
    $this->assertSame($expected_core_package_names, array_keys($core_packages));
  }

  /**
   * @covers ::getPackagesNotIn
   * @covers ::getPackagesWithDifferentVersionsIn
   */
  public function testPackageComparison(): void {
    $active = $this->mockUtilityWithPackages([
      'drupal/existing' => '1.0.0',
      'drupal/updated' => '1.0.0',
      'drupal/removed' => '1.0.0',
    ]);
    $staged = $this->mockUtilityWithPackages([
      'drupal/existing' => '1.0.0',
      'drupal/updated' => '1.1.0',
      'drupal/added' => '1.0.0',
    ]);

    $added = $staged->getPackagesNotIn($active);
    $this->assertSame(['drupal/added'], array_keys($added));

    $removed = $active->getPackagesNotIn($staged);
    $this->assertSame(['drupal/removed'], array_keys($removed));

    $updated = $active->getPackagesWithDifferentVersionsIn($staged);
    $this->assertSame(['drupal/updated'], array_keys($updated));
  }

  /**
   * Mocks a ComposerUtility object to return a set of installed packages.
   *
   * @param string[]|null[] $installed_packages
   *   The installed packages that the mocked object should return. The keys are
   *   the package names and the values are either a version number or NULL to
   *   not mock the corresponding package's getVersion() method.
   *
   * @return \Drupal\package_manager\ComposerUtility|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked object.
   */
  private function mockUtilityWithPackages(array $installed_packages) {
    $mock = $this->getMockBuilder(ComposerUtility::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getInstalledPackages'])
      ->getMock();

    $packages = [];
    foreach ($installed_packages as $name => $version) {
      $package = $this->createMock(PackageInterface::class);
      if (isset($version)) {
        $package->method('getVersion')->willReturn($version);
      }
      $packages[$name] = $package;
    }
    $mock->method('getInstalledPackages')->willReturn($packages);

    return $mock;
  }

}
