<?php

declare(strict_types = 1);

namespace Drupal\Tests\package_manager\Kernel;

use Composer\Json\JsonFile;
use Drupal\Component\Serialization\Json;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\package_manager\InstalledPackage;
use PhpTuf\ComposerStager\Domain\Exception\RuntimeException;

/**
 * @coversDefaultClass \Drupal\package_manager\ComposerInspector
 *
 * @group package_manager
 */
class ComposerInspectorTest extends PackageManagerKernelTestBase {

  /**
   * @covers ::getConfig
   */
  public function testConfig(): void {
    $dir = __DIR__ . '/../../fixtures/fake_site';
    $inspector = $this->container->get('package_manager.composer_inspector');
    $this->assertSame(1, Json::decode($inspector->getConfig('secure-http', $dir)));

    $this->assertSame([
      'boo' => 'boo boo',
      "foo" => ["dev" => "2.x-dev"],
      "foo-bar" => TRUE,
      "boo-far" => [
        "foo" => 1.23,
        "bar" => 134,
        "foo-bar" => NULL,
      ],
      'baz' => NULL,
    ], Json::decode($inspector->getConfig('extra', $dir)));

    $this->expectException(RuntimeException::class);
    $inspector->getConfig('non-existent-config', $dir);
  }

  /**
   * @covers ::getVersion
   */
  public function testGetVersion() {
    $dir = __DIR__ . '/../../fixtures/fake_site';
    $inspector = $this->container->get('package_manager.composer_inspector');
    $version = $inspector->getVersion($dir);
    // We can assert an exact version of Composer, but we can assert that the
    // number is in the expected 'MAJOR.MINOR.PATCH' format.
    $parts = explode('.', $version);
    $this->assertCount(3, $parts);
    $this->assertCount(3, array_filter($parts, 'is_numeric'));
  }

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);
    $container->getDefinition('package_manager.composer_inspector')->setPublic(TRUE);
  }

  /**
   * @covers ::getInstalledPackagesList
   */
  public function testGetInstalledPackagesList(): void {
    $project_root = $this->container->get('package_manager.path_locator')
      ->getProjectRoot();

    /** @var \Drupal\package_manager\ComposerInspector $inspector */
    $inspector = $this->container->get('package_manager.composer_inspector');
    $list = $inspector->getInstalledPackagesList($project_root);

    $this->assertInstanceOf(InstalledPackage::class, $list['drupal/core']);
    $this->assertSame('drupal/core', $list['drupal/core']->name);
    $this->assertSame('drupal-core', $list['drupal/core']->type);
    $this->assertSame('9.8.0', $list['drupal/core']->version);
    $this->assertSame("$project_root/vendor/drupal/core", $list['drupal/core']->path);

    $this->assertInstanceOf(InstalledPackage::class, $list['drupal/core-recommended']);
    $this->assertSame('drupal/core-recommended', $list['drupal/core-recommended']->name);
    $this->assertSame('project', $list['drupal/core-recommended']->type);
    $this->assertSame('9.8.0', $list['drupal/core']->version);
    $this->assertSame("$project_root/vendor/drupal/core-recommended", $list['drupal/core-recommended']->path);

    $this->assertInstanceOf(InstalledPackage::class, $list['drupal/core-dev']);
    $this->assertSame('drupal/core-dev', $list['drupal/core-dev']->name);
    $this->assertSame('package', $list['drupal/core-dev']->type);
    $this->assertSame('9.8.0', $list['drupal/core']->version);
    $this->assertSame("$project_root/vendor/drupal/core-dev", $list['drupal/core-dev']->path);

    // Since the lock file hasn't changed, we should get the same package list
    // back if we call getInstalledPackageList() again.
    $this->assertSame($list, $inspector->getInstalledPackagesList($project_root));

    // If we change the lock file, we should get a different package list.
    $lock_file = new JsonFile($project_root . '/composer.lock');
    $lock_data = $lock_file->read();
    $this->assertArrayHasKey('_readme', $lock_data);
    unset($lock_data['_readme']);
    $lock_file->write($lock_data);
    $this->assertNotSame($list, $inspector->getInstalledPackagesList($project_root));
  }

}
