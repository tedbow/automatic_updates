<?php

namespace Drupal\package_manager_bypass;

use PhpTuf\ComposerStager\Domain\CommitterInterface;
use PhpTuf\ComposerStager\Domain\Process\OutputCallbackInterface;

/**
 * Defines an update committer which doesn't do any actual committing.
 */
class Committer extends InvocationRecorderBase implements CommitterInterface {

  /**
   * The decorated committer service.
   *
   * @var \PhpTuf\ComposerStager\Domain\CommitterInterface
   */
  private $decorated;

  /**
   * Constructs a Committer object.
   *
   * @param \PhpTuf\ComposerStager\Domain\CommitterInterface $decorated
   *   The decorated committer service.
   */
  public function __construct(CommitterInterface $decorated) {
    $this->decorated = $decorated;
  }

  /**
   * {@inheritdoc}
   */
  public function commit(string $stagingDir, string $activeDir, ?array $exclusions = [], ?OutputCallbackInterface $callback = NULL, ?int $timeout = 120): void {
    $this->saveInvocationArguments($activeDir, $stagingDir, $exclusions);
  }

  /**
   * {@inheritdoc}
   */
  public function directoryExists(string $stagingDir): bool {
    return $this->decorated->directoryExists($stagingDir);
  }

}
