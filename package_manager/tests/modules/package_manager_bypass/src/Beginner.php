<?php

namespace Drupal\package_manager_bypass;

use PhpTuf\ComposerStager\Domain\Core\Beginner\BeginnerInterface;
use PhpTuf\ComposerStager\Domain\Service\ProcessOutputCallback\ProcessOutputCallbackInterface;
use PhpTuf\ComposerStager\Domain\Service\ProcessRunner\ProcessRunnerInterface;
use PhpTuf\ComposerStager\Domain\Value\Path\PathInterface;
use PhpTuf\ComposerStager\Domain\Value\PathList\PathListInterface;

/**
 * Defines an update beginner which doesn't do anything.
 */
class Beginner extends InvocationRecorderBase implements BeginnerInterface {

  /**
   * {@inheritdoc}
   */
  public function begin(PathInterface $activeDir, PathInterface $stagingDir, ?PathListInterface $exclusions = NULL, ?ProcessOutputCallbackInterface $callback = NULL, ?int $timeout = ProcessRunnerInterface::DEFAULT_TIMEOUT): void {
    $this->saveInvocationArguments($activeDir, $stagingDir, $exclusions, $timeout);
  }

}
