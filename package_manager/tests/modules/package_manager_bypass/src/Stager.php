<?php

namespace Drupal\package_manager_bypass;

use Composer\Json\JsonFile;
use PhpTuf\ComposerStager\Domain\Core\Stager\StagerInterface;
use PhpTuf\ComposerStager\Domain\Service\ProcessOutputCallback\ProcessOutputCallbackInterface;
use PhpTuf\ComposerStager\Domain\Service\ProcessRunner\ProcessRunnerInterface;
use PhpTuf\ComposerStager\Domain\Value\Path\PathInterface;

/**
 * Defines an update stager which doesn't actually do anything.
 */
class Stager extends BypassedStagerServiceBase implements StagerInterface {

  /**
   * {@inheritdoc}
   */
  public function stage(array $composerCommand, PathInterface $activeDir, PathInterface $stagingDir, ?ProcessOutputCallbackInterface $callback = NULL, ?int $timeout = ProcessRunnerInterface::DEFAULT_TIMEOUT): void {
    $this->saveInvocationArguments($composerCommand, $stagingDir);
    $this->copyFixtureFilesTo($stagingDir);

    // If desired, simulate a change to the lock file (e.g., as a result of
    // running `composer update`).
    $lockFile = new JsonFile($stagingDir->resolve() . '/composer.lock');
    $changeLockFile = $this->state->get(static::class . ' lock', TRUE);

    if ($changeLockFile && $lockFile->exists()) {
      $data = $lockFile->read();
      $data['_time'] = microtime();
      $lockFile->write($data);
    }
  }

  /**
   * Sets whether or not ::stage() should simulate a change in the lock file.
   *
   * @param bool $value
   *   (optional) Whether or not to simulate a change in the lock file when
   *   ::stage() is called. Defaults to TRUE.
   */
  public static function setLockFileShouldChange(bool $value = TRUE): void {
    \Drupal::state()->set(static::class . ' lock', $value);
  }

}
