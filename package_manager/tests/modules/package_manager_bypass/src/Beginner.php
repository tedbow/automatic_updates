<?php

declare(strict_types = 1);

namespace Drupal\package_manager_bypass;

use Drupal\Core\State\StateInterface;
use Drupal\fixture_manipulator\StageFixtureManipulator;
use PhpTuf\ComposerStager\Domain\Core\Beginner\BeginnerInterface;
use PhpTuf\ComposerStager\Domain\Service\ProcessOutputCallback\ProcessOutputCallbackInterface;
use PhpTuf\ComposerStager\Domain\Service\ProcessRunner\ProcessRunnerInterface;
use PhpTuf\ComposerStager\Domain\Value\Path\PathInterface;
use PhpTuf\ComposerStager\Domain\Value\PathList\PathListInterface;

/**
 * Defines a service that decorates the Composer Stager beginner service.
 */
class Beginner extends BypassedStagerServiceBase implements BeginnerInterface {

  /**
   * The decorated service.
   *
   * @var \PhpTuf\ComposerStager\Domain\Core\Beginner\BeginnerInterface
   */
  private $inner;

  /**
   * Constructs a Beginner object.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \PhpTuf\ComposerStager\Domain\Core\Beginner\BeginnerInterface $inner
   *   The decorated beginner service.
   */
  public function __construct(StateInterface $state, BeginnerInterface $inner) {
    $this->state = $state;
    $this->inner = $inner;
  }

  /**
   * A reference to the stage fixture manipulator, if any.
   *
   * Without this, StageFixtureManipulator::__destruct() would run too early:
   * before the test has finished running,
   *
   * @var \Drupal\fixture_manipulator\StageFixtureManipulator|null
   */
  private static $manipulatorReference;

  /**
   * {@inheritdoc}
   */
  public function begin(PathInterface $activeDir, PathInterface $stagingDir, ?PathListInterface $exclusions = NULL, ?ProcessOutputCallbackInterface $callback = NULL, ?int $timeout = ProcessRunnerInterface::DEFAULT_TIMEOUT): void {
    $this->saveInvocationArguments($activeDir, $stagingDir, $exclusions, $timeout);
    $this->inner->begin($activeDir, $stagingDir, $exclusions, $callback, $timeout);

    /** @var \Drupal\fixture_manipulator\StageFixtureManipulator|null $stageManipulator */
    $stageManipulator = $this->state->get(__CLASS__ . '-stage-manipulator', NULL);
    if ($stageManipulator) {
      $stageManipulator->commitChanges($stagingDir->resolve());
    }
  }

  /**
   * Sets the manipulator for the stage.
   *
   * @param \Drupal\fixture_manipulator\StageFixtureManipulator $manipulator
   *   The manipulator.
   */
  public static function setStageManipulator(StageFixtureManipulator &$manipulator): void {
    if (isset(self::$manipulatorReference)) {
      throw new \Exception('Stage manipulator already set.');
    }
    // Keep a reference to the stage fixture manipulator.
    self::$manipulatorReference = $manipulator;
    \Drupal::state()->set(__CLASS__ . '-stage-manipulator', $manipulator);
  }

  /**
   * Destroys references to the tracked manipulator.
   *
   * Without this, StageFixtureManipulator::__destruct() would run too late:
   * after the database connection is destroyed, and hence it would fail.
   *
   * @see \Drupal\Tests\automatic_updates\Functional\AutomaticUpdatesFunctionalTestBase::tearDown()
   * @see \Drupal\fixture_manipulator\StageFixtureManipulator::__destruct()
   */
  public function destroy() {
    self::$manipulatorReference = NULL;
  }

}
