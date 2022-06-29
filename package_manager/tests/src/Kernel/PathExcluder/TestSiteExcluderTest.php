<?php

namespace Drupal\Tests\package_manager\Kernel\PathExcluder;

use Drupal\Tests\package_manager\Kernel\PackageManagerKernelTestBase;

/**
 * @covers \Drupal\package_manager\PathExcluder\TestSiteExcluder
 *
 * @group package_manager
 */
class TestSiteExcluderTest extends PackageManagerKernelTestBase {

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
   * Tests that test site directories are excluded from staging operations.
   */
  public function testTestSitesExcluded(): void {
    // In this test, we want to perform the actual staging operations so that we
    // can be sure that files are staged as expected.
    $this->disableModules(['package_manager_bypass']);
    // Ensure we have an up-to-date container.
    $this->container = $this->container->get('kernel')->getContainer();

    $active_dir = $this->container->get('package_manager.path_locator')
      ->getProjectRoot();

    $stage = $this->createStage();
    $stage->create();
    $stage_dir = $stage->getStageDirectory();

    $ignored = [
      'sites/simpletest',
    ];
    foreach ($ignored as $path) {
      $this->assertFileExists("$active_dir/$path");
      $this->assertFileDoesNotExist("$stage_dir/$path");
    }

    $stage->apply();
    // The ignored files should still be in the active directory.
    foreach ($ignored as $path) {
      $this->assertFileExists("$active_dir/$path");
    }
  }

}
