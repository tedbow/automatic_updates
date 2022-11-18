<?php

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\Tests\package_manager\Traits\FixtureUtilityTrait;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @coversDefaultClass \Drupal\Tests\package_manager\Traits\FixtureUtilityTrait
 *
 * @group package_manager
 */
class FixtureUtilityTraitTest extends PackageManagerKernelTestBase {

  use FixtureUtilityTrait;

  /**
   * The root directory of the virtual project.
   *
   * @var string
   */
  private string $dir;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->dir = $this->container->get('package_manager.path_locator')
      ->getProjectRoot();

    $this->addPackage($this->dir, [
      'name' => 'my/package',
      'type' => 'library',
    ]);
    $this->addPackage($this->dir, [
      'name' => 'my/dev-package',
      'version' => '2.1.0',
      'type' => 'library',
      'install_path' => '../relative/path',
    ],
    TRUE,
    );
  }

  /**
   * @covers ::addPackage
   */
  public function testAddPackage(): void {
    // Packages cannot be added without a name.
    try {
      $this->addPackage($this->dir, ['type' => 'unknown']);
      $this->fail('Adding an anonymous package should raise an error.');
    }
    catch (AssertionFailedError $e) {
      $this->assertSame("Failed asserting that an array has the key 'name'.", $e->getMessage());
    }

    // Packages cannot be added without a type.
    try {
      $this->addPackage($this->dir, ['name' => 'unknown']);
      $this->fail('Adding an package without a type should raise an error.');
    }
    catch (AssertionFailedError $e) {
      $this->assertSame("Failed asserting that an array has the key 'type'.", $e->getMessage());
    }

    // We should not be able to add an existing package.
    try {
      $this->addPackage($this->dir, [
        'name' => 'my/package',
        'type' => 'library',
      ]);
      $this->fail('Trying to add an existing package should raise an error.');
    }
    catch (AssertionFailedError $e) {
      $this->assertStringContainsString("Expected package 'my/package' to not be installed, but it was.", $e->getMessage());
    }

    // We should not be able to add a package with an absolute installation
    // path.
    try {
      $this->addPackage($this->dir, [
        'name' => 'absolute/path',
        'install_path' => '/absolute/path',
        'type' => 'library',
      ]);
      $this->fail('Add package should have failed.');
    }
    catch (AssertionFailedError $e) {
      $this->assertSame('Failed asserting that \'/absolute/path\' starts with "../".', $e->getMessage());
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
    $installed_php_expected_packages = [
      'drupal/core' => [
        'name' => 'drupal/core',
        'type' => 'drupal-core',
      ],
    ] + $installed_php_expected_packages;
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
    $this->modifyPackage(
      $temp_fixture,
      'the-org/the-package',
      ['install_path' => '../../a_new_path'],
    );
    $this->assertSame($original_installed_json, $decode_installed_json());

    // Assert that ::modifyPackage() throws an error if a package exists in the
    // 'installed.json' file but not the 'installed.php' file. We cannot test
    // this with the trait functions because they cannot produce this starting
    // point.
    $existing_incorrect_fixture = __DIR__ . '/../../fixtures/FixtureUtilityTraitTest/missing_installed_php';
    $temp_fixture = $this->siteDirectory . $this->randomMachineName('42');
    $fs->mirror($existing_incorrect_fixture, $temp_fixture);
    try {
      $this->modifyPackage(
        $temp_fixture,
        'the-org/the-package',
        ['install_path' => '../../a_new_path'],
      );
      $this->fail('Modifying a non-existent package should raise an error.');
    }
    catch (AssertionFailedError $e) {
      $this->assertStringContainsString("Failed asserting that an array has the key 'the-org/the-package'.", $e->getMessage());
    }

    // We should not be able to modify a non-existent package.
    try {
      $this->modifyPackage($this->dir, 'junk/drawer', ['type' => 'library']);
      $this->fail('Modifying a non-existent package should raise an error.');
    }
    catch (AssertionFailedError $e) {
      $this->assertStringContainsString("Expected package 'junk/drawer' to be installed, but it wasn't.", $e->getMessage());
    }

    // Add a key to an existing package.
    $this->modifyPackage($this->dir, 'my/package', ['type' => 'metapackage']);
    // Change a key in an existing package.
    $this->modifyPackage($this->dir, 'my/dev-package', ['version' => '3.2.1']);
    // Move an existing package to dev requirements.
    $this->addPackage($this->dir, [
      'name' => 'my/other-package',
      'type' => 'library',
    ]);

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
    $installed_php_expected_packages = [
      'drupal/core' => [
        'name' => 'drupal/core',
        'type' => 'drupal-core',
      ],
    ] + $installed_php_expected_packages;
    // @see ::testAddPackage()
    $this->assertSame($installed_php_expected_packages, $installed_php);
  }

  /**
   * @covers ::removePackage
   */
  public function testRemovePackage(): void {
    // We should not be able to remove a package that's not installed.
    try {
      $this->removePackage($this->dir, 'junk/drawer');
      $this->fail('Removing a non-existent package should raise an error.');
    }
    catch (AssertionFailedError $e) {
      $this->assertStringContainsString("Expected package 'junk/drawer' to be installed, but it wasn't.", $e->getMessage());
    }

    $this->removePackage($this->dir, 'my/package');
    $this->removePackage($this->dir, 'my/dev-package');

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

}
