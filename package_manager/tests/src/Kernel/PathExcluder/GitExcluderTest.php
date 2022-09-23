<?php

namespace Drupal\Tests\package_manager\Kernel\PathExcluder;

use Drupal\Tests\package_manager\Kernel\PackageManagerKernelTestBase;

/**
 * @covers \Drupal\package_manager\PathExcluder\GitExcluder
 *
 * @group package_manager
 */
class GitExcluderTest extends PackageManagerKernelTestBase {

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
   * Tests that Git directories are excluded from staging operations.
   */
  public function testGitDirectoriesExcluded(): void {
    // In this test, we want to perform the actual staging operations so that we
    // can be sure that files are staged as expected.
    $this->setSetting('package_manager_bypass_composer_stager', FALSE);
    // Ensure we have an up-to-date container.
    $this->container = $this->container->get('kernel')->rebuildContainer();

    $active_dir = $this->container->get('package_manager.path_locator')
      ->getProjectRoot();

    $stage = $this->createStage();
    $stage->create();
    $stage_dir = $stage->getStageDirectory();

    $ignored = [
      '.git/ignore.txt',
      'modules/example/.git/ignore.txt',
    ];
    foreach ($ignored as $path) {
      $this->assertFileExists("$active_dir/$path");
      $this->assertFileDoesNotExist("$stage_dir/$path");
    }
    // Files that start with .git, but aren't actually .git, should be staged.
    $this->assertFileExists("$stage_dir/.gitignore");

    $stage->apply();
    // The ignored files should still be in the active directory.
    foreach ($ignored as $path) {
      $this->assertFileExists("$active_dir/$path");
    }
  }

}
