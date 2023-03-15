<?php

declare(strict_types = 1);

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\fixture_manipulator\ActiveFixtureManipulator;
use Drupal\fixture_manipulator\FixtureManipulator;

/**
 * @coversDefaultClass \Drupal\fixture_manipulator\FixtureManipulator
 *
 * @group package_manager
 */
class FixtureManipulatorTest extends PackageManagerKernelTestBase {

  /**
   * The root directory of the test project.
   *
   * @var string
   */
  private string $dir;

  /**
   * The exception expected in ::tearDown() of this test.
   *
   * @var \Exception
   */
  private \Exception $expectedTearDownException;

  /**
   * The original 'installed.php' data before any manipulation.
   *
   * @var array
   */
  private array $originalInstalledPhp;

  /**
   * Ensures the original fixture packages in 'installed.php' are unchanged.
   *
   * @param array $installed_php
   *   The current 'installed.php' data.
   */
  private function assertOriginalFixturePackagesUnchanged(array $installed_php): void {
    $original_package_names = array_keys($this->originalInstalledPhp);
    $installed_php_core_packages = array_intersect_key($installed_php, array_flip($original_package_names));
    // `reference` is never the same as the original because the relative path
    // repos from the `fake_site` fixture are converted to absolute ones, which
    // causes a new reference to be computed.
    $without_reference_key = function (array $a): array {
      return array_diff_key($a, array_flip(['reference']));
    };
    $this->assertSame(
      array_map($without_reference_key, $this->originalInstalledPhp),
      array_map($without_reference_key, $installed_php_core_packages)
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->dir = $this->container->get('package_manager.path_locator')
      ->getProjectRoot();

    [, $this->originalInstalledPhp] = $this->getData();

    $manipulator = new ActiveFixtureManipulator();
    $manipulator
      ->addPackage([
        'name' => 'my/package',
        'type' => 'library',
        'version' => '1.2.3',
      ])
      ->addPackage(
        [
          'name' => 'my/dev-package',
          'version' => '2.1.0',
          'type' => 'library',
        ],
        TRUE
      )
      ->commitChanges();
  }

  /**
   * @covers ::addPackage
   */
  public function testAddPackage(): void {
    // Packages cannot be added without a name.
    foreach (['name', 'type'] as $require_key) {
      // Make a package that is missing the required key.
      $package = array_diff_key(
        [
          'name' => 'Any old name',
          'type' => 'Any old type',
        ],
        [$require_key => '']
      );
      try {
        $manipulator = new ActiveFixtureManipulator();
        $manipulator->addPackage($package)
          ->commitChanges();
        $this->fail("Adding a package without the '$require_key' should raise an error.");
      }
      catch (\UnexpectedValueException $e) {
        $this->assertSame("The '$require_key' is required when calling ::addPackage().", $e->getMessage());
      }
    }

    // We should get a helpful error if the name is not a valid package name.
    try {
      $manipulator = new ActiveFixtureManipulator();
      $manipulator->addPackage([
        'name' => 'my_drupal_module',
        'type' => 'drupal-module',
      ])
        ->commitChanges();
      $this->fail('Trying to add a package with an invalid name should raise an error.');
    }
    catch (\UnexpectedValueException $e) {
      $this->assertSame("'my_drupal_module' is not a valid package name.", $e->getMessage());
    }

    // We should not be able to add an existing package.
    try {
      $manipulator = new ActiveFixtureManipulator();
      $manipulator->addPackage([
        'name' => 'my/package',
        'type' => 'library',
      ])
        ->commitChanges();
      $this->fail('Trying to add an existing package should raise an error.');
    }
    catch (\LogicException $e) {
      $this->assertStringContainsString("Expected package 'my/package' to not be installed, but it was.", $e->getMessage());
    }

    $installed_json_expected_packages = [
      'my/dev-package' => [
        'name' => 'my/dev-package',
        'version' => '2.1.0',
        'version_normalized' => '2.1.0.0',
        'type' => 'library',
      ],
      'my/package' => [
        'name' => 'my/package',
        // If no version is specified in a new package it will be added.
        'version' => '1.2.3',
        'version_normalized' => '1.2.3.0',
        'type' => 'library',
      ],
    ];
    $installed_php_expected_packages = $installed_json_expected_packages;
    foreach ($installed_php_expected_packages as $package_name => &$expectation) {
      // Composer stores `version_normalized`in 'installed.json' but in
      // 'installed.php' that is just 'version', and 'version' is
      // 'pretty_version'.
      $expectation['pretty_version'] = $expectation['version'];
      $expectation['version'] = $expectation['version_normalized'];
      unset($expectation['version_normalized']);
      // `name` is omitted in installed.php.
      unset($expectation['name']);
      // Compute the expected `install_path`.
      $expectation['install_path'] = $expectation['type'] === 'metapackage' ? NULL : "$this->dir/vendor/composer/../$package_name";
    }
    [$installed_json, $installed_php] = $this->getData();
    $installed_json['packages'] = array_intersect_key($installed_json['packages'], $installed_json_expected_packages);
    $this->assertSame($installed_json_expected_packages, array_map(fn (array $package) => array_intersect_key($package, array_flip(['name', 'type', 'version', 'version_normalized'])), $installed_json['packages']));
    $this->assertContains('my/dev-package', $installed_json['dev-package-names']);
    $this->assertNotContains('my/package', $installed_json['dev-package-names']);

    // None of the operations should have changed the original packages.
    $this->assertOriginalFixturePackagesUnchanged($installed_php);

    // Remove the original packages since we have confirmed that they have not
    // changed.
    $installed_php = array_diff_key($installed_php, $this->originalInstalledPhp);
    foreach ($installed_php_expected_packages as $package_name => $expected_data) {
      $this->assertEquals($installed_php_expected_packages[$package_name], array_intersect_key($installed_php[$package_name], array_flip(['version', 'type', 'pretty_version', 'install_path'])), $package_name);
    }
  }

  /**
   * @covers ::modifyPackage
   */
  public function testModifyPackageConfig(): void {
    $inspector = $this->container->get('package_manager.composer_inspector');

    // Assert ::modifyPackage() works with a package in an existing fixture not
    // created by ::addPackage().
    $decode_installed_json = function () {
      return json_decode(file_get_contents($this->dir . '/vendor/composer/installed.json'), TRUE, 512, JSON_THROW_ON_ERROR);
    };
    $original_installed_json = $decode_installed_json();
    $this->assertIsArray($original_installed_json);
    (new ActiveFixtureManipulator())
      // @see ::setUp()
      ->modifyPackageConfig('my/dev-package', '2.1.0', ['description' => 'something else'], TRUE)
      ->commitChanges();
    // Verify that the package is indeed properly installed.
    $this->assertSame('2.1.0', $inspector->getInstalledPackagesList($this->dir)['my/dev-package']->version);
    // Verify that the original exists, but has no description.
    $this->assertSame('my/dev-package', $original_installed_json['packages'][3]['name']);
    $this->assertArrayNotHasKey('description', $original_installed_json['packages']);
    // Verify that the description was updated.
    $this->assertSame('something else', $decode_installed_json()['packages'][3]['description']);

    (new ActiveFixtureManipulator())
      // Add a key to an existing package.
      ->modifyPackageConfig('my/package', '1.2.3', ['extra' => ['foo' => 'bar']])
      // Change a key in an existing package.
      ->setVersion('my/dev-package', '3.2.1', TRUE)
      // Move an existing package to dev requirements.
      ->addPackage([
        'name' => 'my/other-package',
        'type' => 'library',
      ])
      ->commitChanges();

    $install_json_expected_packages = [
      'my/dev-package' => [
        'name' => 'my/dev-package',
        'version' => '3.2.1',
        'version_normalized' => '3.2.1.0',
        'type' => 'library',
      ],
      'my/other-package' => [
        'name' => 'my/other-package',
        'version' => '1.2.3',
        'version_normalized' => '1.2.3.0',
        'type' => 'library',
      ],
      'my/package' => [
        'name' => 'my/package',
        'version' => '1.2.3',
        'version_normalized' => '1.2.3.0',
        'type' => 'library',
      ],
    ];
    $installed_php_expected_packages = $install_json_expected_packages;
    foreach ($installed_php_expected_packages as $package_name => &$expectation) {
      // Composer stores `version_normalized`in 'installed.json' but in
      // 'installed.php' that is just 'version', and 'version' is
      // 'pretty_version'.
      $expectation['pretty_version'] = $expectation['version'];
      $expectation['version'] = $expectation['version_normalized'];
      unset($expectation['version_normalized']);
      // `name` is omitted in installed.php.
      unset($expectation['name']);
      // Compute the expected `install_path`.
      $expectation['install_path'] = $expectation['type'] === 'metapackage' ? NULL : "$this->dir/vendor/composer/../$package_name";
    }
    [$installed_json, $installed_php] = $this->getData();
    $installed_json['packages'] = array_intersect_key($installed_json['packages'], $install_json_expected_packages);
    $this->assertSame($install_json_expected_packages, array_map(fn (array $package) => array_intersect_key($package, array_flip(['name', 'type', 'version', 'version_normalized'])), $installed_json['packages']));
    $this->assertContains('my/dev-package', $installed_json['dev-package-names']);
    $this->assertNotContains('my/other-package', $installed_json['dev-package-names']);
    $this->assertNotContains('my/package', $installed_json['dev-package-names']);

    // None of the operations should have changed the original packages.
    $this->assertOriginalFixturePackagesUnchanged($installed_php);

    // Remove the original packages since we have confirmed that they have not
    // changed.
    $installed_php = array_diff_key($installed_php, $this->originalInstalledPhp);
    foreach ($installed_php_expected_packages as $package_name => $expected_data) {
      $this->assertEquals($installed_php_expected_packages[$package_name], array_intersect_key($installed_php[$package_name], array_flip(['version', 'type', 'pretty_version', 'install_path'])), $package_name);
    }
  }

  /**
   * @covers ::removePackage
   */
  public function testRemovePackage(): void {
    // We should not be able to remove a package that's not installed.
    try {
      (new ActiveFixtureManipulator())
        ->removePackage('junk/drawer')
        ->commitChanges();
      $this->fail('Removing a non-existent package should raise an error.');
    }
    catch (\LogicException $e) {
      $this->assertStringContainsString('junk/drawer is not required in your composer.json and has not been remove', $e->getMessage());
    }

    (new ActiveFixtureManipulator())
      ->removePackage('my/package')
      ->removePackage('my/dev-package', TRUE)
      ->commitChanges();

    foreach (['json', 'php'] as $extension) {
      $file = "$this->dir/vendor/composer/installed.$extension";
      $contents = file_get_contents($file);
      $this->assertStringNotContainsString('my/package', $contents, "'my/package' not found in $file");
      $this->assertStringNotContainsString('my/dev-package', $contents, "'my/dev-package' not found in $file");
    }
  }

  /**
   * Returns the data from installed.php and installed.json.
   *
   * @return array[]
   *   An array of two arrays. The first array will be the contents of
   *   installed.json, with the `packages` array keyed by package name. The
   *   second array will be the `versions` array from installed.php.
   */
  private function getData(): array {
    $installed_json = file_get_contents("$this->dir/vendor/composer/installed.json");
    $installed_json = json_decode($installed_json, TRUE, 512, JSON_THROW_ON_ERROR);

    $keyed_packages = [];
    foreach ($installed_json['packages'] as $package) {
      $keyed_packages[$package['name']] = $package;
    }
    $installed_json['packages'] = $keyed_packages;

    $installed_php = require "$this->dir/vendor/composer/installed.php";
    return [
      $installed_json,
      $installed_php['versions'],
    ];
  }

  /**
   * Test that an exception is thrown if ::commitChanges() is not called.
   */
  public function testActiveManipulatorNoCommitError(): void {
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('commitChanges() must be called.');
    (new ActiveFixtureManipulator())
      ->setVersion('drupal/core', '1.2.3');
  }

  /**
   * @covers ::addDotGitFolder
   */
  public function testAddDotGitFolder() {
    $path_locator = $this->container->get('package_manager.path_locator');
    $project_root = $path_locator->getProjectRoot();
    $this->assertFalse(is_dir($project_root . "/relative/path/.git"));
    // We should not be able to add a git folder to a non-existing directory.
    try {
      (new FixtureManipulator())
        ->addDotGitFolder($project_root . "/relative/path")
        ->commitChanges($project_root);
      $this->fail('Trying to create a .git directory that already exists should raise an error.');
    }
    catch (\LogicException $e) {
      $this->assertSame('No directory exists at ' . $project_root . '/relative/path.', $e->getMessage());
    }
    mkdir($project_root . "/relative/path", 0777, TRUE);
    $fixture_manipulator = (new FixtureManipulator())
      ->addPackage([
        'name' => 'relative/project_path',
        'type' => 'drupal-module',
      ])
      ->addDotGitFolder($path_locator->getVendorDirectory() . "/relative/project_path")
      ->addDotGitFolder($project_root . "/relative/path");
    $this->assertTrue(!is_dir($project_root . "/relative/project_path/.git"));
    $fixture_manipulator->commitChanges($project_root);
    $this->assertTrue(is_dir($project_root . "/relative/path/.git"));
    // We should not be able to create already existing directory.
    try {
      (new FixtureManipulator())
        ->addDotGitFolder($project_root . "/relative/path")
        ->commitChanges($project_root);
      $this->fail('Trying to create a .git directory that already exists should raise an error.');
    }
    catch (\LogicException $e) {
      $this->assertStringContainsString("A .git directory already exists at " . $project_root, $e->getMessage());
    }
  }

  /**
   * Tests that the stage manipulator throws an exception if not committed.
   */
  public function testStagedFixtureNotCommitted(): void {
    $this->expectedTearDownException = new \LogicException('The StageFixtureManipulator has arguments that were not cleared. This likely means that the PostCreateEvent was never fired.');
    $this->getStageFixtureManipulator()->setVersion('any-org/any-package', '3.2.1');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    try {
      parent::tearDown();
    }
    catch (\Exception $exception) {
      if (!(get_class($exception) === get_class($this->expectedTearDownException) && $exception->getMessage() === $this->expectedTearDownException->getMessage())) {
        throw $exception;
      }
    }
  }

}
