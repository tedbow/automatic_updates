<?php

namespace Drupal\package_manager_bypass;

use PhpTuf\ComposerStager\Domain\Output\ProcessOutputCallbackInterface;
use PhpTuf\ComposerStager\Domain\StagerInterface;

/**
 * Defines an update stager which doesn't actually do anything.
 */
class Stager implements StagerInterface {

  /**
   * {@inheritdoc}
   */
  public function stage(array $composerCommand, string $stagingDir, ?ProcessOutputCallbackInterface $callback = NULL, ?int $timeout = 120): void {
  }

}
