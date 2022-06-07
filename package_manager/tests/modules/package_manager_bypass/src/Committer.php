<?php

namespace Drupal\package_manager_bypass;

use PhpTuf\ComposerStager\Domain\Core\Committer\CommitterInterface;
use PhpTuf\ComposerStager\Domain\Service\ProcessOutputCallback\ProcessOutputCallbackInterface;
use PhpTuf\ComposerStager\Domain\Service\ProcessRunner\ProcessRunnerInterface;
use PhpTuf\ComposerStager\Domain\Value\Path\PathInterface;
use PhpTuf\ComposerStager\Domain\Value\PathList\PathListInterface;

/**
 * Defines an update committer which doesn't do any actual committing.
 */
class Committer extends InvocationRecorderBase implements CommitterInterface {

  /**
   * {@inheritdoc}
   */
  public function commit(PathInterface $stagingDir, PathInterface $activeDir, ?PathListInterface $exclusions = NULL, ?ProcessOutputCallbackInterface $callback = NULL, ?int $timeout = ProcessRunnerInterface::DEFAULT_TIMEOUT): void {
    $this->saveInvocationArguments($stagingDir, $activeDir, $exclusions, $timeout);
  }

}
