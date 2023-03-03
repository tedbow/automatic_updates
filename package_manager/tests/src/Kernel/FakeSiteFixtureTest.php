<?php

declare(strict_types = 1);

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\fixture_manipulator\ActiveFixtureManipulator;
use Drupal\package_manager\ComposerUtility;
use Symfony\Component\Process\Process;

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
   * Tests if `setVersion` can be called on all packages in the fixture.
   *
   * @see \Drupal\fixture_manipulator\FixtureManipulator::setVersion()
   */
  public function testCallToSetVersion(): void {
    $active_dir = $this->container->get('package_manager.path_locator')->getProjectRoot();
    $stage = $this->createStage();
    $installed_packages = $stage->getActiveComposer()->getInstalledPackages();
    foreach (self::getExpectedFakeSitePackages() as $package_name) {
      $this->assertArrayHasKey($package_name, $installed_packages);
      $this->assertSame('9.8.0', $installed_packages[$package_name]->getPrettyVersion());
      $list = $this->container->get('package_manager.composer_inspector')->getInstalledPackagesList($active_dir);
      $this->assertSame($list[$package_name]->version, '9.8.0');
      (new ActiveFixtureManipulator())
        ->setVersion($package_name, '11.1.0')
        ->commitChanges();
      $list = $this->container->get('package_manager.composer_inspector')->getInstalledPackagesList($active_dir);
      $this->assertSame($list[$package_name]->version, '11.1.0');
    }
  }

  /**
   * Tests if `removePackage` can be called on all packages in the fixture.
   *
   * @covers \Drupal\fixture_manipulator\FixtureManipulator::removePackage()
   */
  public function testCallToRemovePackage(): void {
    $active_dir = $this->container->get('package_manager.path_locator')->getProjectRoot();
    $expected_packages = self::getExpectedFakeSitePackages();
    $stage = $this->createStage();
    $actual_packages = array_keys($stage->getActiveComposer()->getInstalledPackages());
    sort($actual_packages);
    $this->assertSame($expected_packages, $actual_packages);
    foreach (self::getExpectedFakeSitePackages() as $package_name) {
      (new ActiveFixtureManipulator())
        ->removePackage($package_name, $package_name === 'drupal/core-dev')
        ->commitChanges();
      array_shift($expected_packages);
      $list = $this->container->get('package_manager.composer_inspector')->getInstalledPackagesList($active_dir);
      $actual_package_names = array_keys($list->getArrayCopy());
      sort($actual_package_names);
      $this->assertSame($expected_packages, $actual_package_names);
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

  /**
   * Tests that Composer show command can be used on the fixture.
   */
  public function testComposerShow(): void {
    $active_dir = $this->container->get('package_manager.path_locator')->getProjectRoot();
    (new ActiveFixtureManipulator())
      ->addPackage([
        'type' => 'package',
        'version' => '1.2.3',
        'name' => 'any-org/any-package',
      ])
      ->commitChanges();
    $process = new Process(['composer', 'show', '--format=json'], $active_dir);
    $process->run();
    if ($error = $process->getErrorOutput()) {
      $this->fail('Process error: ' . $error);
    }
    $output = json_decode($process->getOutput(), TRUE);
    $package_names = array_map(fn (array $package) => $package['name'], $output['installed']);
    $this->assertTrue(asort($package_names));
    $this->assertSame(['any-org/any-package', 'drupal/core', 'drupal/core-dev', 'drupal/core-recommended'], $package_names);
    $list = $this->container->get('package_manager.composer_inspector')->getInstalledPackagesList($active_dir);
    $list_packages_names = array_keys($list->getArrayCopy());
    $this->assertSame(['any-org/any-package', 'drupal/core', 'drupal/core-dev', 'drupal/core-recommended'], $list_packages_names);

  }

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);
    $container->getDefinition('package_manager.composer_inspector')->setPublic(TRUE);
  }

  /**
   * Tests that the fixture passes `composer validate`.
   */
  public function testComposerValidate(): void {
    $active_dir = $this->container->get('package_manager.path_locator')->getProjectRoot();
    $process = new Process([
      'composer',
      'validate',
      '--check-lock',
      '--with-dependencies',
      '--no-interaction',
      '--ansi',
      '--no-cache',
    ], $active_dir);
    $process->mustRun();
  }

}
