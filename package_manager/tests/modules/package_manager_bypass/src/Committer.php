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
class Committer extends BypassedStagerServiceBase implements CommitterInterface {

  /**
   * {@inheritdoc}
   */
  public function commit(PathInterface $stagingDir, PathInterface $activeDir, ?PathListInterface $exclusions = NULL, ?ProcessOutputCallbackInterface $callback = NULL, ?int $timeout = ProcessRunnerInterface::DEFAULT_TIMEOUT): void {
    $this->saveInvocationArguments($stagingDir, $activeDir, $exclusions, $timeout);
    $this->copyFixtureFilesTo($activeDir);
  }

  /**
   * {@inheritdoc}
   */
  public static function setFixturePath(?string $path): void {
    // We haven't yet encountered a situation where we need the committer to
    // copy fixture files to the active directory, but when we do, go ahead and
    // remove this entire method.
    throw new \BadMethodCallException('This is not implemented yet.');
  }

}
