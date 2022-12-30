<?php

declare(strict_types = 1);

namespace Drupal\package_manager_bypass;

use Drupal\fixture_manipulator\StageFixtureManipulator;
use PhpTuf\ComposerStager\Domain\Core\Beginner\BeginnerInterface;
use PhpTuf\ComposerStager\Domain\Service\ProcessOutputCallback\ProcessOutputCallbackInterface;
use PhpTuf\ComposerStager\Domain\Service\ProcessRunner\ProcessRunnerInterface;
use PhpTuf\ComposerStager\Domain\Value\Path\PathInterface;
use PhpTuf\ComposerStager\Domain\Value\PathList\PathListInterface;

/**
 * Defines an update beginner which doesn't do anything.
 */
class Beginner extends BypassedStagerServiceBase implements BeginnerInterface {

  /**
   * {@inheritdoc}
   */
  public function begin(PathInterface $activeDir, PathInterface $stagingDir, ?PathListInterface $exclusions = NULL, ?ProcessOutputCallbackInterface $callback = NULL, ?int $timeout = ProcessRunnerInterface::DEFAULT_TIMEOUT): void {
    $this->saveInvocationArguments($activeDir, $stagingDir, $exclusions, $timeout);
    $this->copyFixtureFilesTo($stagingDir);

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
  public static function setStageManipulator(StageFixtureManipulator $manipulator): void {
    if (\Drupal::state()->get(__CLASS__ . '-stage-manipulator')) {
      throw new \Exception('Stage manipulator already set.');
    }
    \Drupal::state()->set(__CLASS__ . '-stage-manipulator', $manipulator);
  }

}
