<?php

namespace Drupal\package_manager_bypass;

use PhpTuf\ComposerStager\Domain\Core\Stager\StagerInterface;
use PhpTuf\ComposerStager\Domain\Service\ProcessOutputCallback\ProcessOutputCallbackInterface;
use PhpTuf\ComposerStager\Domain\Service\ProcessRunner\ProcessRunnerInterface;
use PhpTuf\ComposerStager\Domain\Value\Path\PathInterface;

/**
 * Defines an update stager which doesn't actually do anything.
 */
class Stager extends InvocationRecorderBase implements StagerInterface {

  /**
   * {@inheritdoc}
   */
  public function stage(array $composerCommand, PathInterface $activeDir, PathInterface $stagingDir, ?ProcessOutputCallbackInterface $callback = NULL, ?int $timeout = ProcessRunnerInterface::DEFAULT_TIMEOUT): void {
    $this->saveInvocationArguments($composerCommand, $stagingDir);
  }

}
