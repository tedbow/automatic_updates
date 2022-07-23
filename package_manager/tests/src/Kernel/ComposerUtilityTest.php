<?php

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\package_manager\ComposerUtility;
use org\bovigo\vfs\vfsStream;

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
   * Tests that ComposerUtility disables automatic creation of .htaccess files.
   */
  public function testHtaccessProtectionDisabled(): void {
    $dir = vfsStream::setup()->url();
    file_put_contents($dir . '/composer.json', '{}');

    ComposerUtility::createForDirectory($dir);
    $this->assertFileDoesNotExist($dir . '/.htaccess');
  }

  /**
   * Data provider for testCorePackagesFromLockFile().
   *
   * @return string[][]
   *   The test cases.
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
   * @covers ::getCorePackages
   *
   * @dataProvider providerCorePackagesFromLockFile
   */
  public function testCorePackagesFromLockFile(string $dir, array $expected_packages): void {
    $packages = ComposerUtility::createForDirectory($dir)
      ->getCorePackages();
    $this->assertSame($expected_packages, array_keys($packages));
  }

  /**
   * @covers ::getPackagesNotIn
   * @covers ::getPackagesWithDifferentVersionsIn
   */
  public function testPackageComparison(): void {
    $fixture_dir = __DIR__ . '/../../fixtures/packages_comparison';
    $active = ComposerUtility::createForDirectory($fixture_dir . '/active');
    $staged = ComposerUtility::createForDirectory($fixture_dir . '/stage');

    $added = $staged->getPackagesNotIn($active);
    $this->assertSame(['drupal/added'], array_keys($added));

    $removed = $active->getPackagesNotIn($staged);
    $this->assertSame(['drupal/removed'], array_keys($removed));

    $updated = $active->getPackagesWithDifferentVersionsIn($staged);
    $this->assertSame(['drupal/updated'], array_keys($updated));
  }

}
