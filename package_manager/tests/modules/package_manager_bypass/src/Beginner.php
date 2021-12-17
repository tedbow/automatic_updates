<?php

namespace Drupal\package_manager_bypass;

use PhpTuf\ComposerStager\Domain\BeginnerInterface;
use PhpTuf\ComposerStager\Domain\Output\ProcessOutputCallbackInterface;

/**
 * Defines an update beginner which doesn't do anything.
 */
class Beginner extends InvocationRecorderBase implements BeginnerInterface {

  /**
   * {@inheritdoc}
   */
  public function begin(string $activeDir, string $stagingDir, ?array $exclusions = [], ?ProcessOutputCallbackInterface $callback = NULL, ?int $timeout = 120): void {
    $this->saveInvocationArguments($activeDir, $stagingDir, $exclusions);
  }

}
