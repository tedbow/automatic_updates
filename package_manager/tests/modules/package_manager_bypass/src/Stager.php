<?php

namespace Drupal\package_manager_bypass;

use PhpTuf\ComposerStager\Domain\Process\OutputCallbackInterface;
use PhpTuf\ComposerStager\Domain\StagerInterface;

/**
 * Defines an update stager which doesn't actually do anything.
 */
class Stager extends InvocationRecorderBase implements StagerInterface {

  /**
   * {@inheritdoc}
   */
  public function stage(array $composerCommand, string $stagingDir, ?OutputCallbackInterface $callback = NULL, ?int $timeout = 120): void {
    $this->saveInvocationArguments($composerCommand, $stagingDir);
  }

}
