<?php

declare(strict_types = 1);

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\fixture_manipulator\ActiveFixtureManipulator;
use Drupal\fixture_manipulator\FixtureManipulator;
use Drupal\fixture_manipulator\StageFixtureManipulator;
use Drupal\package_manager_bypass\Beginner;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @coversDefaultClass \Drupal\fixture_manipulator\FixtureManipulator
 *
 * @group package_manager
 */
class FixtureManipulatorTest extends PackageManagerKernelTestBase {

  /**
   * The root directory of the virtual project.
   *
   * @var string
   */
  private string $dir;

  /**
   * The existing packages in the fixture.
   *
   * @var \string[][]
   */
  private array $existingCorePackages = [
    'drupal/core' => [
      'name' => 'drupal/core',
      'version' => '9.8.0',
      'type' => 'drupal-core',
    ],
    'drupal/core-recommended' => [
      'name' => 'drupal/core-recommended',
      'version' => '9.8.0',
      'type' => 'drupal-core',
    ],
    'drupal/core-dev' => [
      'name' => 'drupal/core-dev',
      'version' => '9.8.0',
      'type' => 'drupal-core',
    ],
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->dir = $this->container->get('package_manager.path_locator')
      ->getProjectRoot();

    $manipulator = new ActiveFixtureManipulator();
    $manipulator
      ->addPackage([
        'name' => 'my/package',
        'type' => 'library',
      ])
      ->addPackage(
        [
          'name' => 'my/dev-package',
          'version' => '2.1.0',
          'type' => 'library',
          'install_path' => '../relative/path',
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

    // We should not be able to add a package with an absolute installation
    // path.
    try {
      (new ActiveFixtureManipulator())
        ->addPackage([
          'name' => 'absolute/path',
          'install_path' => '/absolute/path',
          'type' => 'library',
        ])
        ->commitChanges();
      $this->fail('Add package should have failed.');
    }
    catch (\UnexpectedValueException $e) {
      $this->assertSame("'install_path' must start with '../'.", $e->getMessage());
    }

    $installed_json_expected_packages = [
      'my/package' => [
        'name' => 'my/package',
        'type' => 'library',
      ],
      'my/dev-package' => [
        'name' => 'my/dev-package',
        'version' => '2.1.0',
        'type' => 'library',
      ],
    ];
    $installed_php_expected_packages = $installed_json_expected_packages;
    [$installed_json, $installed_php] = $this->getData();
    $installed_json['packages'] = array_intersect_key($installed_json['packages'], $installed_json_expected_packages);
    $this->assertSame($installed_json_expected_packages, $installed_json['packages']);
    $this->assertContains('my/dev-package', $installed_json['dev-package-names']);
    $this->assertNotContains('my/package', $installed_json['dev-package-names']);
    // In installed.php, the relative installation path of my/dev-package should
    // have been prefixed with the __DIR__ constant, which should be interpreted
    // when installed.php is loaded by the PHP runtime.
    $installed_php_expected_packages['my/dev-package']['install_path'] = "$this->dir/vendor/composer/../relative/path";
    $installed_php_expected_packages = $this->existingCorePackages + $installed_php_expected_packages;
    $this->assertSame($installed_php_expected_packages, $installed_php);
  }

  /**
   * @covers ::modifyPackage
   */
  public function testModifyPackage(): void {
    $fs = (new Filesystem());
    // Assert ::modifyPackage() works with a package in an existing fixture not
    // created by ::addPackage().
    $existing_fixture = __DIR__ . '/../../fixtures/FixtureUtilityTraitTest/existing_correct_fixture';
    $temp_fixture = $this->siteDirectory . $this->randomMachineName('42');
    $fs->mirror($existing_fixture, $temp_fixture);
    $decode_installed_json = function () use ($temp_fixture) {
      return json_decode(file_get_contents($temp_fixture . '/vendor/composer/installed.json'), TRUE, 512, JSON_THROW_ON_ERROR);
    };
    $original_installed_json = $decode_installed_json();
    $this->assertIsArray($original_installed_json);
    (new FixtureManipulator())
      ->modifyPackage('the-org/the-package', ['install_path' => '../../a_new_path'])
      ->commitChanges($temp_fixture);
    $this->assertSame($original_installed_json, $decode_installed_json());

    // Assert that ::modifyPackage() throws an error if a package exists in the
    // 'installed.json' file but not the 'installed.php' file. We cannot test
    // this with the trait functions because they cannot produce this starting
    // point.
    $existing_incorrect_fixture = __DIR__ . '/../../fixtures/FixtureUtilityTraitTest/missing_installed_php';
    $temp_fixture = $this->siteDirectory . $this->randomMachineName('42');
    $fs->mirror($existing_incorrect_fixture, $temp_fixture);
    try {
      (new FixtureManipulator())
        ->modifyPackage('the-org/the-package', ['install_path' => '../../a_new_path'])
        ->commitChanges($temp_fixture);
      $this->fail('Modifying a non-existent package should raise an error.');
    }
    catch (\LogicException $e) {
      $this->assertSame("Expected package 'the-org/the-package' to be installed, but it wasn't.", $e->getMessage());
    }

    // We should not be able to modify a non-existent package.
    try {
      (new ActiveFixtureManipulator())
        ->modifyPackage('junk/drawer', ['type' => 'library'])
        ->commitChanges();
      $this->fail('Modifying a non-existent package should raise an error.');
    }
    catch (\LogicException $e) {
      $this->assertStringContainsString("Expected package 'junk/drawer' to be installed, but it wasn't.", $e->getMessage());
    }

    (new ActiveFixtureManipulator())
      // Add a key to an existing package.
      ->modifyPackage('my/package', ['type' => 'metapackage'])
      // Change a key in an existing package.
      ->setVersion('my/dev-package', '3.2.1')
      // Move an existing package to dev requirements.
      ->addPackage([
        'name' => 'my/other-package',
        'type' => 'library',
      ])
      ->commitChanges();

    $install_json_expected_packages = [
      'my/package' => [
        'name' => 'my/package',
        'type' => 'metapackage',
      ],
      'my/dev-package' => [
        'name' => 'my/dev-package',
        'version' => '3.2.1',
        'type' => 'library',
      ],
      'my/other-package' => [
        'name' => 'my/other-package',
        'type' => 'library',
      ],
    ];
    $installed_php_expected_packages = $install_json_expected_packages;
    $installed_php_expected_packages['my/dev-package']['install_path'] = "$this->dir/vendor/composer/../relative/path";
    [$installed_json, $installed_php] = $this->getData();
    $installed_json['packages'] = array_intersect_key($installed_json['packages'], $install_json_expected_packages);
    $this->assertSame($install_json_expected_packages, $installed_json['packages']);
    $this->assertContains('my/dev-package', $installed_json['dev-package-names']);
    $this->assertNotContains('my/other-package', $installed_json['dev-package-names']);
    $this->assertNotContains('my/package', $installed_json['dev-package-names']);
    $installed_php_expected_packages = $this->existingCorePackages + $installed_php_expected_packages;
    // @see ::testAddPackage()
    $this->assertSame($installed_php_expected_packages, $installed_php);
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
      $this->assertStringContainsString("Expected package 'junk/drawer' to be installed, but it wasn't.", $e->getMessage());
    }

    (new ActiveFixtureManipulator())
      ->removePackage('my/package')
      ->removePackage('my/dev-package')
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
   * Test that an exception is thrown if ::readyToCommit() is not called.
   */
  public function testStageManipulatorSetReadyToCommitExpected(): void {
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('This fixture manipulator was not yet ready to commit! Please call setReadyToCommit() to signal all necessary changes are queued.');

    $manipulator = new StageFixtureManipulator();
    $manipulator->setVersion('drupal/core', '1.2.3');
  }

  /**
   * Test that an exception is thrown if ::commitChanges() is not called.
   *
   * @dataProvider stageManipulatorUsageStyles
   */
  public function testStageManipulatorNoCommitError(bool $immediately_destroyed): void {
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('commitChanges() must be called.');

    if ($immediately_destroyed) {
      (new StageFixtureManipulator())
        ->setCorePackageVersion('9.8.1')
        ->setReadyToCommit();
    }
    else {
      $manipulator = new StageFixtureManipulator();
      $manipulator->setVersion('drupal/core', '1.2.3');
      $manipulator->setReadyToCommit();
    }

    // Ensure \Drupal\fixture_manipulator\StageFixtureManipulator::__destruct()
    // is triggered.
    $this->container->get('state')->delete(Beginner::class . '-stage-manipulator');
    $this->container->get('package_manager.beginner')->destroy();
  }

  /**
   * Test no exceptions are thrown if StageFixtureManipulator is used correctly.
   *
   * @dataProvider stageManipulatorUsageStyles
   */
  public function testStageManipulatorSatisfied(bool $immediately_destroyed): void {
    if ($immediately_destroyed) {
      (new StageFixtureManipulator())
        ->setCorePackageVersion('9.8.1')
        ->setReadyToCommit();
    }
    else {
      $manipulator = new StageFixtureManipulator();
      $manipulator->setVersion('drupal/core', '1.2.3');
      $manipulator->setReadyToCommit();
    }

    $path_locator = $this->container->get('package_manager.path_locator');
    // We need to set the vendor directory's permissions first because, in the
    // virtual project, it's located inside the project root.
    $this->assertTrue(chmod($path_locator->getVendorDirectory(), 0777));
    $this->assertTrue(chmod($path_locator->getProjectRoot(), 0777));

    // Simulate a stage beginning, which would commit the changes.
    // @see \Drupal\package_manager_bypass\Beginner::begin()
    $this->container->get('package_manager.beginner')->begin(
      new TestPath($path_locator->getProjectRoot()),
      new TestPath($path_locator->getStagingRoot()),
    );
  }

  public function stageManipulatorUsageStyles() {
    return [
      'immediately destroyed' => [TRUE],
      'variable' => [FALSE],
    ];
  }

}
