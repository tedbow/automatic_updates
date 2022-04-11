<?php

namespace Drupal\Tests\package_manager\Kernel\PathExcluder;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\package_manager\PathExcluder\SiteConfigurationExcluder;
use Drupal\Tests\package_manager\Kernel\PackageManagerKernelTestBase;

/**
 * @covers \Drupal\package_manager\PathExcluder\SiteConfigurationExcluder
 *
 * @group package_manager
 */
class SiteConfigurationExcluderTest extends PackageManagerKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // In this test, we want to disable the lock file validator because, even
    // though both the active and stage directories will have a valid lock file,
    // this validator will complain because they don't differ at all.
    $this->disableValidators[] = 'package_manager.validator.lock_file';
    parent::setUp();
  }

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);

    $container->getDefinition('package_manager.site_configuration_excluder')
      ->setClass(TestSiteConfigurationExcluder::class);
  }

  /**
   * Tests that certain paths are excluded from staging operations.
   */
  public function testExcludedPaths(): void {
    // In this test, we want to perform the actual staging operations so that we
    // can be sure that files are staged as expected.
    $this->disableModules(['package_manager_bypass']);
    // Ensure we have an up-to-date container.
    $this->container = $this->container->get('kernel')->getContainer();

    $this->createTestProject();
    $active_dir = $this->container->get('package_manager.path_locator')
      ->getProjectRoot();

    $site_path = 'sites/example.com';

    // Update the event subscribers' dependencies.
    $this->container->get('package_manager.site_configuration_excluder')->sitePath = $site_path;

    $stage = $this->createStage();
    $stage->create();
    $stage_dir = $stage->getStageDirectory();

    $ignore = [
      "$site_path/settings.php",
      "$site_path/settings.local.php",
      "$site_path/services.yml",
      // Default site-specific settings files should be ignored.
      'sites/default/settings.php',
      'sites/default/settings.local.php',
      'sites/default/services.yml',
    ];
    foreach ($ignore as $path) {
      $this->assertFileExists("$active_dir/$path");
      $this->assertFileDoesNotExist("$stage_dir/$path");
    }
    // A non-excluded file in the default site directory should be staged.
    $this->assertFileExists("$stage_dir/sites/default/stage.txt");
    // Regular module files should be staged.
    $this->assertFileExists("$stage_dir/modules/example/example.info.yml");

    // A new file added to the staging area in an excluded directory, should not
    // be copied to the active directory.
    $file = "$stage_dir/sites/default/no-copy.txt";
    touch($file);
    $this->assertFileExists($file);
    $stage->apply();
    $this->assertFileDoesNotExist("$active_dir/sites/default/no-copy.txt");

    // The ignored files should still be in the active directory.
    foreach ($ignore as $path) {
      $this->assertFileExists("$active_dir/$path");
    }
  }

}

/**
 * A test version of the site configuration excluder, to expose internals.
 */
class TestSiteConfigurationExcluder extends SiteConfigurationExcluder {

  /**
   * {@inheritdoc}
   */
  public $sitePath;

}
