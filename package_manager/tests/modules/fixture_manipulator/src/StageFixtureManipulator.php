<?php

declare(strict_types = 1);

namespace Drupal\fixture_manipulator;

use Drupal\package_manager_bypass\Beginner;

/**
 * A fixture manipulator for the stage directory.
 */
final class StageFixtureManipulator extends FixtureManipulator {

  /**
   * Whether the fixture is ready to commit.
   *
   * @var bool
   */
  private $ready = FALSE;

  /**
   * {@inheritdoc}
   */
  public function commitChanges(string $dir = NULL): void {
    if (!$this->ready) {
      throw new \LogicException("::setReadyToCommit must be called before ::commitChanges");
    }
    if (!$dir) {
      throw new \UnexpectedValueException("$dir must be specific for a StageFixtureManipulator");
    }
    parent::doCommitChanges($dir);
    $this->committed = TRUE;
  }

  /**
   * Sets the manipulator as ready to commit.
   */
  public function setReadyToCommit(): void {
    $this->ready = TRUE;
    Beginner::setStageManipulator($this);
  }

  /**
   * {@inheritdoc}
   */
  public function __destruct() {
    if (!$this->ready) {
      throw new \LogicException('This fixture manipulator was not yet ready to commit! Please call setReadyToCommit() to signal all necessary changes are queued.');
    }
  }

}
