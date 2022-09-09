<?php

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\package_manager\ComposerUtility;
use Drupal\Tests\package_manager\Traits\InfoYmlConverterTrait;
use org\bovigo\vfs\vfsStream;

/**
 * @coversDefaultClass \Drupal\package_manager\ComposerUtility
 *
 * @group package_manager
 */
class ComposerUtilityTest extends KernelTestBase {

  use InfoYmlConverterTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['package_manager', 'update'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $fixture = vfsStream::newDirectory('fixture');
    vfsStream::copyFromFileSystem(__DIR__ . '/../../fixtures/project_package_conversion', $fixture);
    $this->vfsRoot->addChild($fixture);
    $this->renameVfsInfoYmlFiles();
  }

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

  /**
   * @covers ::getProjectForPackage
   *
   * @param string $package
   *   The package name.
   * @param string|null $expected_project
   *   The expected project if any, otherwise NULL.
   *
   * @dataProvider providerGetProjectForPackage
   */
  public function testGetProjectForPackage(string $package, ?string $expected_project): void {
    $dir = $this->vfsRoot->getChild('fixture')->url();
    $this->assertSame($expected_project, ComposerUtility::createForDirectory($dir)->getProjectForPackage($package));
  }

  /**
   * Data provider for ::testGetProjectForPackage().
   *
   * @return mixed[][]
   *   The test cases.
   */
  public function providerGetProjectForPackage(): array {
    return [
      'package and project match' => [
        'drupal/package_project_match',
        'package_project_match',
      ],
      'package and project do not match' => [
        'drupal/not_match_package',
        'not_match_project',
      ],
      'vendor is not drupal' => [
        'non_drupal/other_project',
        NULL,
      ],
      'missing package' => [
        'drupal/missing',
        NULL,
      ],
      'nested_no_match' => [
        'drupal/nested_no_match_package',
        'nested_no_match_project',
      ],
      'unsupported package type' => [
        'drupal/custom_module',
        NULL,
      ],
    ];
  }

  /**
   * @covers ::getPackageForProject
   *
   * @param string $project
   *   The project name.
   * @param string|null $expected_package
   *   The expected package if any, otherwise NULL.
   *
   * @dataProvider providerGetPackageForProject
   */
  public function testGetPackageForProject(string $project, ?string $expected_package): void {
    $dir = $this->vfsRoot->getChild('fixture')->url();
    $this->assertSame($expected_package, ComposerUtility::createForDirectory($dir)->getPackageForProject($project));
  }

  /**
   * Data provider for ::testGetPackageForProject().
   *
   * @return mixed[][]
   *   The test cases.
   */
  public function providerGetPackageForProject(): array {
    return [
      'package and project match' => [
        'package_project_match',
        'drupal/package_project_match',
      ],
      'package and project do not match' => [
        'not_match_project',
        'drupal/not_match_package',
      ],
      'vendor is not drupal' => [
        'other_project',
        NULL,
      ],
      'missing package' => [
        'missing',
        NULL,
      ],
      'nested_no_match' => [
        'nested_no_match_project',
        'drupal/nested_no_match_package',
      ],
    ];
  }

}
