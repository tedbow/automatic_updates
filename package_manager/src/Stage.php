<?php

namespace Drupal\package_manager;

use PhpTuf\ComposerStager\Domain\BeginnerInterface;
use PhpTuf\ComposerStager\Domain\CleanerInterface;
use PhpTuf\ComposerStager\Domain\CommitterInterface;
use PhpTuf\ComposerStager\Domain\StagerInterface;

/**
 * Creates and manages a staging area in which to install or update code.
 *
 * Allows calling code to copy the current Drupal site into a temporary staging
 * directory, use Composer to require packages into it, sync changes from the
 * staging directory back into the active code base, and then delete the
 * staging directory.
 */
class Stage {

  /**
   * The path locator service.
   *
   * @var \Drupal\package_manager\PathLocator
   */
  protected $pathLocator;

  /**
   * The beginner service from Composer Stager.
   *
   * @var \PhpTuf\ComposerStager\Domain\BeginnerInterface
   */
  protected $beginner;

  /**
   * The stager service from Composer Stager.
   *
   * @var \PhpTuf\ComposerStager\Domain\StagerInterface
   */
  protected $stager;

  /**
   * The committer service from Composer Stager.
   *
   * @var \PhpTuf\ComposerStager\Domain\CommitterInterface
   */
  protected $committer;

  /**
   * The cleaner service from Composer Stager.
   *
   * @var \PhpTuf\ComposerStager\Domain\CleanerInterface
   */
  protected $cleaner;

  /**
   * Constructs a new Stage object.
   *
   * @param \Drupal\package_manager\PathLocator $path_locator
   *   The path locator service.
   * @param \PhpTuf\ComposerStager\Domain\BeginnerInterface $beginner
   *   The beginner service from Composer Stager.
   * @param \PhpTuf\ComposerStager\Domain\StagerInterface $stager
   *   The stager service from Composer Stager.
   * @param \PhpTuf\ComposerStager\Domain\CommitterInterface $committer
   *   The committer service from Composer Stager.
   * @param \PhpTuf\ComposerStager\Domain\CleanerInterface $cleaner
   *   The cleaner service from Composer Stager.
   */
  public function __construct(PathLocator $path_locator, BeginnerInterface $beginner, StagerInterface $stager, CommitterInterface $committer, CleanerInterface $cleaner) {
    $this->pathLocator = $path_locator;
    $this->beginner = $beginner;
    $this->stager = $stager;
    $this->committer = $committer;
    $this->cleaner = $cleaner;
  }

  /**
   * Copies the active code base into the staging area.
   *
   * @param array|null $exclusions
   *   Paths to exclude from being copied into the staging area.
   *
   * @todo Remove the $exclusions parameter when this method fires events.
   */
  public function create(?array $exclusions = []): void {
    $active_dir = $this->pathLocator->getActiveDirectory();
    $stage_dir = $this->pathLocator->getStageDirectory();
    $this->beginner->begin($active_dir, $stage_dir, $exclusions);
  }

  /**
   * Requires packages in the staging area.
   *
   * @param string[] $constraints
   *   The packages to require, in the form 'vendor/name:version'.
   */
  public function require(array $constraints): void {
    $command = array_merge(['require'], $constraints);
    $command[] = '--update-with-all-dependencies';
    $this->stager->stage($command, $this->pathLocator->getStageDirectory());
  }

  /**
   * Applies staged changes to the active directory.
   *
   * @param array|null $exclusions
   *   Paths to exclude from being copied into the active directory.
   *
   * @todo Remove the $exclusions parameter when this method fires events.
   */
  public function apply(?array $exclusions = []): void {
    $active_dir = $this->pathLocator->getActiveDirectory();
    $stage_dir = $this->pathLocator->getStageDirectory();
    $this->committer->commit($stage_dir, $active_dir, $exclusions);
  }

  /**
   * Deletes the staging area.
   */
  public function destroy(): void {
    $stage_dir = $this->pathLocator->getStageDirectory();
    if (is_dir($stage_dir)) {
      $this->cleaner->clean($stage_dir);
    }
  }

}
