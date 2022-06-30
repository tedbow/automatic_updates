<?php

namespace Drupal\Tests\package_manager\Kernel\PathExcluder;

use Drupal\package_manager_bypass\Beginner;
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
   * Tests that unreadable directories are ignored by the event subscriber.
   */
  public function testUnreadableDirectoriesAreIgnored(): void {
    $active_dir = $this->container->get('package_manager.path_locator')
      ->getProjectRoot();

    // Create an unreadable directory within the active directory, which will
    // raise an exception as the event subscriber tries to scan for .git
    // directories...unless unreadable directories are being ignored, as they
    // should be.
    $unreadable_dir = $active_dir . '/unreadable';
    mkdir($unreadable_dir, 0000);
    $this->assertDirectoryIsNotReadable($unreadable_dir);

    // Don't mirror the active directory into the virtual staging area, since
    // the active directory contains an unreadable directory which will cause
    // an exception.
    Beginner::setFixturePath(NULL);

    $this->createStage()->create();
  }

  /**
   * Tests that Git directories are excluded from staging operations.
   */
  public function testGitDirectoriesExcluded(): void {
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
