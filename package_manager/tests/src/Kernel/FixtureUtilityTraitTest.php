<?php

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\Tests\package_manager\Traits\FixtureUtilityTrait;
use PHPUnit\Framework\AssertionFailedError;

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
    ]);
    $this->addPackage($this->dir, [
      'name' => 'my/dev-package',
      'version' => '2.1.0',
      'dev_requirement' => TRUE,
      'install_path' => '../relative/path',
    ]);
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

    // We should not be able to add an existing package.
    try {
      $this->addPackage($this->dir, ['name' => 'my/package']);
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
      ]);
    }
    catch (AssertionFailedError $e) {
      $this->assertSame('Failed asserting that \'/absolute/path\' starts with "../".', $e->getMessage());
    }

    $expected_packages = [
      'my/package' => [
        'name' => 'my/package',
      ],
      'my/dev-package' => [
        'name' => 'my/dev-package',
        'version' => '2.1.0',
        'dev_requirement' => TRUE,
        'install_path' => '../relative/path',
      ],
    ];
    [$installed_json, $installed_php] = $this->getData();
    $installed_json['packages'] = array_intersect_key($installed_json['packages'], $expected_packages);
    $this->assertSame($expected_packages, $installed_json['packages']);
    $this->assertContains('my/dev-package', $installed_json['dev-package-names']);
    $this->assertNotContains('my/package', $installed_json['dev-package-names']);
    // In installed.php, the relative installation path of my/dev-package should
    // have been prefixed with the __DIR__ constant, which should be interpreted
    // when installed.php is loaded by the PHP runtime.
    $expected_packages['my/dev-package']['install_path'] = "$this->dir/vendor/composer/../relative/path";
    $this->assertSame($expected_packages, $installed_php);
  }

  /**
   * @covers ::modifyPackage
   */
  public function testModifyPackage(): void {
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
    $this->modifyPackage($this->dir, 'my/other-package', ['dev_requirement' => TRUE]);

    $expected_packages = [
      'my/package' => [
        'name' => 'my/package',
        'type' => 'metapackage',
      ],
      'my/dev-package' => [
        'name' => 'my/dev-package',
        'version' => '3.2.1',
        'dev_requirement' => TRUE,
        'install_path' => '../relative/path',
      ],
      'my/other-package' => [
        'name' => 'my/other-package',
        'type' => 'library',
        'dev_requirement' => TRUE,
      ],
    ];

    [$installed_json, $installed_php] = $this->getData();
    $installed_json['packages'] = array_intersect_key($installed_json['packages'], $expected_packages);
    $this->assertSame($expected_packages, $installed_json['packages']);
    $this->assertContains('my/dev-package', $installed_json['dev-package-names']);
    $this->assertContains('my/other-package', $installed_json['dev-package-names']);
    $this->assertNotContains('my/package', $installed_json['dev-package-names']);
    // @see ::testAddPackage()
    $expected_packages['my/dev-package']['install_path'] = "$this->dir/vendor/composer/../relative/path";
    $this->assertSame($expected_packages, $installed_php);
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
      $contents = file_get_contents("$this->dir/vendor/composer/installed.$extension");
      $this->assertStringNotContainsString('my/package', $contents);
      $this->assertStringNotContainsString('my/dev-package', $contents);
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
