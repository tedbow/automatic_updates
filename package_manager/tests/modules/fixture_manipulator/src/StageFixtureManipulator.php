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

    // In a kernel test, the Beginner runs in the same PHP process as the test,
    // so there's no need for extra logic to inform the test runner that the
    // queued stage fixture manipulations have been committed. In functional
    // tests, however, we do need to pass information back from the system under
    // test to the test runner.
    // @see \Drupal\Core\CoreServiceProvider::registerTest()
    $in_functional_test = defined('DRUPAL_TEST_IN_CHILD_SITE');
    if ($in_functional_test) {
      // Relay "committed" state to the test runner by re-serializing to state.
      Beginner::setStageManipulator($this);
    }
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

    // Update the state to match reality, because ::commitChanges() *should*
    // have been called by \Drupal\package_manager_bypass\Beginner::begin(). The
    // "committed" flag will already be set in kernel tests, because there the
    // test runner and the system under test live in the same PHP process.
    // Note that this will never run for the system under test, because
    // $this->committed will always be set. Ensure we do this only
    // functional tests by checking for the presence of a container.
    if (!$this->committed && \Drupal::hasContainer()) {
      $sut = \Drupal::state()->get(Beginner::class . '-stage-manipulator', NULL);
      if ($sut) {
        $this->committed = $sut->committed;
      }
    }

    // Proceed regular destruction (which will complain if it's not committed).
    parent::__destruct();
  }

}
